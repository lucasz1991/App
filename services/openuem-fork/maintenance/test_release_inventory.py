import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location('inventory_release', Path(__file__).with_name('release-inventory.py'))
r = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r)


@unittest.skipUnless(os.name=='posix' and os.geteuid()==0,'Run these Linux-root tests in an isolated protected /srv staging directory')
class ReleaseTests(unittest.TestCase):
    def setUp(self):
        # Keep the synthetic fixture inside the reviewed staging directory;
        # the release intentionally refuses traversal through world-writable /tmp.
        self.temp = tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent)
        self.root = Path(self.temp.name)
        self.app = self.root/'app'
        (self.app/r.PREFIX).mkdir(parents=True)
        self.runtime = self.root/'runtime'/'worker'
        self.runtime.parent.mkdir()
        self.runtime.write_bytes(b'old-worker')
        self.backup = self.root/'backup'
        self.patch = patch.multiple(r, RUNTIME=self.runtime, BACKUP=self.backup)
        self.patch.start()
        self.payload = {r.PREFIX+f'extension/example{i}.go':b'package example' for i in range(101)}
        self.payload[r.BINARY] = b'reviewed-binary'

    def tearDown(self):
        self.patch.stop()
        self.temp.cleanup()

    def archive(self, additions=None):
        file = self.root/'source.zip'
        with zipfile.ZipFile(file,'w') as z:
            for key, value in (self.payload if additions is None else additions).items():
                z.writestr(key,value)
        file.chmod(0o600)
        return file, r.digest(file.read_bytes())

    def test_valid_payload_and_runtime_mapping(self):
        path,pin = self.archive()
        self.assertEqual(r.load(path,pin),self.payload)
        self.assertEqual(r.targets(self.payload)[-1][1],self.runtime)
        self.assertEqual(len(r.targets(self.payload)),1)

    def test_pin_failure(self):
        path,_ = self.archive()
        with self.assertRaises(RuntimeError): r.load(path,'0'*64)

    def test_path_and_private_file_refusals(self):
        for name in ['/etc/passwd','../outside',r.PREFIX+'../../outside',r.PREFIX+'a/../b',r.PREFIX+'private.key',r.PREFIX+'x\\z.go',r.PREFIX+'.git/config']:
            with self.subTest(name=name),self.assertRaises(RuntimeError): r.valid_name(name)

    def test_duplicate_and_symlink_archive(self):
        path,pin = self.archive()
        with zipfile.ZipFile(path,'a') as z: z.writestr(r.BINARY,b'changed')
        with self.assertRaises(RuntimeError): r.load(path,r.digest(path.read_bytes()))
        path,_=self.archive()
        with zipfile.ZipFile(path,'a') as z:
            entry=zipfile.ZipInfo(r.PREFIX+'link')
            entry.create_system=3
            entry.external_attr=(stat.S_IFLNK|0o777)<<16
            z.writestr(entry,'/etc/passwd')
        with self.assertRaises(RuntimeError): r.load(path,r.digest(path.read_bytes()))

    def test_runtime_config_and_unapproved_executable_refusals(self):
        for suffix in ['.env','.env.production','credentials.json','config/railtime.json','execution.json','secrets/x.json','native/unapproved.exe','bin/unapproved-worker']:
            with self.subTest(suffix=suffix),self.assertRaises(RuntimeError): r.valid_name(r.PREFIX+suffix)
        for name in r.APPROVED_BINARIES:
            r.valid_name(name)

    def test_archive_must_be_private(self):
        path,pin=self.archive()
        path.chmod(0o644)
        with self.assertRaises(RuntimeError): r.load(path,pin)

    def test_group_writable_or_foreign_owned_runtime_parent_refused(self):
        parent=self.runtime.parent
        try:
            parent.chmod(0o770)
            with self.assertRaises(RuntimeError): r.baseline(self.payload)
            parent.chmod(0o700)
            os.chown(parent,54321,54321)
            with self.assertRaises(RuntimeError): r.baseline(self.payload)
        finally:
            os.chown(parent,0,0)
            parent.chmod(0o700)

    def test_drift_refuses_before_backup(self):
        _,pin=r.baseline(self.payload)
        self.runtime.write_bytes(b'changed independently')
        with self.assertRaises(RuntimeError): r.apply(self.payload,pin,'synthetic')
        self.assertFalse(self.backup.exists())

    @unittest.skipUnless(os.name=='posix','Linux protected writes')
    def test_apply_preserves_backup_and_proves_all_hashes(self):
        untouched=self.app/(r.PREFIX+'unchanged.go')
        untouched.write_bytes(b'live app source remains unchanged')
        _,pin=r.baseline(self.payload)
        receipt=r.apply(self.payload,pin,'synthetic')
        self.assertTrue(receipt['applied'])
        self.assertEqual(self.runtime.read_bytes(),b'reviewed-binary')
        self.assertTrue(any(p.read_bytes()==b'old-worker' for p in self.backup.glob('*.before')))
        self.assertEqual(json.loads((self.backup/'applied.json').read_text())['files_verified'],1)
        self.assertFalse(receipt['live_app_source_changed'])
        self.assertFalse(receipt['source_archive_applied'])
        self.assertEqual(untouched.read_bytes(),b'live app source remains unchanged')
        self.assertFalse((self.app/(r.PREFIX+'extension')).exists())
        self.assertEqual(stat.S_IMODE(self.backup.stat().st_mode),0o700)
        with self.assertRaises(RuntimeError): r.apply(self.payload,r.baseline(self.payload)[1],'synthetic')

    def test_corrupt_backup_refuses_before_runtime_change(self):
        _,pin=r.baseline(self.payload)
        original=r.write_new
        def corrupt(path,raw,*args,**kwargs):
            original(path,raw,*args,**kwargs)
            if path.suffix=='.before': path.write_bytes(b'synthetic backup corruption')
        with patch.object(r,'write_new',side_effect=corrupt),self.assertRaises(RuntimeError):
            r.apply(self.payload,pin,'synthetic')
        self.assertEqual(self.runtime.read_bytes(),b'old-worker')
        self.assertFalse((self.backup/'applied.json').exists())

    def test_drift_immediately_before_replace_is_not_overwritten(self):
        _,pin=r.baseline(self.payload)
        original=r.write_new
        def change_target(path,raw,*args,**kwargs):
            original(path,raw,*args,**kwargs)
            if path.name.endswith('.railtime-inventory-new'):
                self.runtime.write_bytes(b'independent root deployment')
        with patch.object(r,'write_new',side_effect=change_target),self.assertRaises(RuntimeError):
            r.apply(self.payload,pin,'synthetic')
        self.assertEqual(self.runtime.read_bytes(),b'independent root deployment')
        self.assertEqual((self.backup/'0000.before').read_bytes(),b'old-worker')
        self.assertFalse((self.backup/'applied.json').exists())

    @unittest.skipUnless(os.name=='posix','Linux symlink support')
    def test_symlink_target_refused(self):
        victim=self.root/'victim'
        victim.write_bytes(b'do not touch')
        self.runtime.unlink()
        self.runtime.symlink_to(victim)
        with self.assertRaises(RuntimeError): r.baseline(self.payload)
        self.assertEqual(victim.read_bytes(),b'do not touch')


if __name__=='__main__': unittest.main()
