#!/usr/bin/env python3
"""Pinned runtime-only Worker update; operator stops Worker separately.

The complete reviewed source ZIP stays privately staged under root-controlled
/srv. Live App source is NOT updated by this helper. Only the existing root-owned
/srv Worker binary is replaced. No credential/config/account/device changes.
Preflight pins the runtime baseline; apply verifies its complete backup and
refuses drift immediately before atomic replacement. Failed partial state is
preserved. This is not a Laravel release or a database migration.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import zipfile

RUNTIME = Path('/srv/railtime-openuem/bin/openuem-worker')
BACKUP = Path('/srv/railtime-openuem-inventory-backup-20260906')
PREFIX = 'services/openuem-fork/'
BINARY = PREFIX + 'bin/openuem-worker-linux-amd64'
APPROVED_BINARIES = {BINARY, PREFIX+'bin/openuem-agent-windows-amd64.exe',
                     PREFIX+'bin/device-enroll-windows-amd64.exe'}


def require(ok, code):
    if not ok:
        raise RuntimeError(code)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(obj):
    return (json.dumps(obj, sort_keys=True, separators=(',', ':')) + '\n').encode()


def protected_directory(path):
    require(path.is_absolute(), 'absolute protected directory required')
    for part in (path, *path.parents):
        info = part.lstat()
        require(not stat.S_ISLNK(info.st_mode), 'symlink refused')
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
                and not info.st_mode & 0o022, 'root-owned protected directory required')


def regular(path):
    protected_directory(path.parent)
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
            and info.st_uid == 0 and not info.st_mode & 0o022,
            'root-owned single-link regular file required')


def valid_name(name):
    p = PurePosixPath(name)
    require(name.startswith(PREFIX) and '\\' not in name and not p.is_absolute()
            and p.as_posix() == name and all(x not in ('', '.', '..', '.git', '__pycache__', 'node_modules') for x in p.parts), 'invalid member')
    lowered = [x.lower() for x in p.parts]
    require(all(x != '.env' and not x.startswith('.env.') and x not in ('secrets', 'credentials') for x in lowered), 'credential member refused')
    require(p.name.lower() not in ('credentials.json', 'credentials.yaml', 'credentials.yml', 'secrets.json', 'railtime.json', 'execution.json', 'bootstrap.json', 'prepared.json', 'agent-hmac.key', 'health-bearer'), 'runtime configuration member refused')
    require(p.suffix.lower() not in ('.key', '.pem', '.crt', '.cer', '.ini', '.log', '.pyc', '.pfx', '.p12', '.env'), 'runtime/secret member refused')
    if name.startswith(PREFIX+'bin/') or p.suffix.lower() in ('.exe', '.dll', '.msi', '.com', '.scr'):
        require(name in APPROVED_BINARIES, 'unapproved executable member refused')


def load(archive, pin):
    require(archive.is_absolute() and Path('/srv') in archive.parents, 'archive must remain staged under /srv')
    regular(archive)
    require(not archive.stat().st_mode & 0o077, 'source archive must be private to root')
    require(archive.stat().st_size <= 150 * 1024 * 1024, 'archive too large')
    raw = archive.read_bytes()
    require(digest(raw) == pin, 'archive hash mismatch')
    require(len(raw) <= 150 * 1024 * 1024, 'archive too large')
    payload = {}
    total = 0
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        for entry in z.infolist():
            name = entry.filename
            valid_name(name)
            require(name not in payload and not entry.is_dir() and entry.file_size <= 128*1024*1024, 'invalid archive entry')
            mode = entry.external_attr >> 16
            require(not stat.S_ISLNK(mode), 'archive link refused')
            total += entry.file_size
            require(total <= 400*1024*1024 and len(payload) < 2500, 'expanded archive too large')
            payload[name] = z.read(entry)
    require(BINARY in payload and len(payload) > 100, 'incomplete source release')
    return payload


def targets(payload):
    # Source files are retained only inside the private reviewed archive, never
    # installed through vhost-writable App paths by this root helper.
    return [('runtime/openuem-worker', RUNTIME, payload[BINARY])]


def baseline(payload):
    rows = []
    for name, target, raw in targets(payload):
        regular(target)
        s = target.stat()
        rows.append({'name':name, 'sha256':digest(target.read_bytes()), 'uid':s.st_uid, 'gid':s.st_gid, 'mode':stat.S_IMODE(s.st_mode)})
    return rows, digest(canonical(rows))


def write_new(path, raw, mode=0o600, uid=0, gid=0):
    protected_directory(path.parent)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'wb') as f:
        f.write(raw)
        f.flush()
        os.fsync(f.fileno())
        os.fchown(f.fileno(), uid, gid)
        os.fchmod(f.fileno(), mode)


def apply(payload, expected, pin):
    rows, current = baseline(payload)
    require(current == expected, 'target drift; repeat review')
    require(not BACKUP.exists() and not BACKUP.is_symlink(), 'existing backup; inspect partial/prior release')
    protected_directory(BACKUP.parent)
    regular(RUNTIME)
    BACKUP.mkdir(mode=0o700)
    protected_directory(BACKUP)
    write_new(BACKUP/'baseline.json', canonical({'archive_sha256':pin, 'baseline_sha256':current, 'files':rows}))
    # Verify both captured and persisted backup bytes before any target change.
    # All ancestors are root-only writers; no vhost-owned path is traversed.
    for index, (_, target, _) in enumerate(targets(payload)):
        before = target.read_bytes()
        require(digest(before) == rows[index]['sha256'], 'runtime drift during backup read')
        backup = BACKUP/f'{index:04d}.before'
        write_new(backup, before)
        regular(backup)
        require(digest(backup.read_bytes()) == rows[index]['sha256'], 'backup hash mismatch; runtime unchanged')
    require(baseline(payload)[1] == expected, 'drift while backing up; targets unchanged')
    for name, target, raw in targets(payload):
        if target.exists() and digest(target.read_bytes()) == digest(raw):
            continue
        regular(target)
        temp = target.with_name(target.name+'.railtime-inventory-new')
        write_new(temp, raw, 0o755)
        require(baseline(payload)[1] == expected, 'runtime drift before replace; staged file preserved')
        regular(temp)
        require(digest(temp.read_bytes()) == digest(raw), 'staged runtime hash mismatch')
        protected_directory(target.parent)
        os.replace(temp, target)
        fd = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    regular(RUNTIME)
    require(digest(RUNTIME.read_bytes()) == digest(payload[BINARY]), 'postwrite hash mismatch')
    result = {'applied':True, 'files_verified':1, 'source_members_validated':len(payload),
              'live_app_source_changed':False, 'source_archive_applied':False,
              'backup':str(BACKUP), 'device_actions':False}
    write_new(BACKUP/'applied.json', canonical({'archive_sha256':pin, **result}))
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('action', choices=('preflight','apply'))
    p.add_argument('--archive', type=Path, required=True)
    p.add_argument('--sha256', required=True)
    p.add_argument('--baseline-sha256')
    args = p.parse_args()
    require(os.geteuid() == 0, 'reviewed Linux root required')
    os.umask(0o077)
    payload = load(args.archive, args.sha256)
    if args.action == 'preflight':
        rows, pin = baseline(payload)
        print(json.dumps({'files':len(rows),'source_members_validated':len(payload),
                          'live_app_source_changed':False, 'baseline_sha256':pin,
                          'runtime_sha256':rows[-1]['sha256']}))
    else:
        state = subprocess.run(['systemctl','is-active','railtime-openuem-worker.service'], capture_output=True, timeout=10)
        require(state.returncode == 3 and state.stdout.strip() == b'inactive', 'Worker must be explicitly stopped first')
        print(json.dumps(apply(payload, args.baseline_sha256, args.sha256)))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Public package errors only; never dump machine config/native logs.
        raise SystemExit('Scoped release stopped; preserved partial state: '+str(error))
