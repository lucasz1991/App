"""Create a new local review ZIP and pins, never install/deploy/activate anything."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile


def build(root: Path, output: Path) -> dict:
    root = root.resolve()
    paths = [p for p in (root/'scripts/mail/e3').iterdir() if p.is_file() and p.suffix != '.pyc']
    required = [
        'app/Support/Mail/OutlookUniversalTransitSnapshotProvider.php',
        'app/Support/Mail/OutlookUniversalTransitCandidate.php',
        'app/Support/Mail/OutlookUniversalTransitTransformer.php',
        'app/Support/OutlookAddin/OutlookUniversalPresentation.php',
        'resources/mail-templates/u2e-universal-v1.html',
        'scripts/mail/railtime_transit.py', 'scripts/mail/railtime_transit_cid.py',
        'scripts/mail/railtime_transit_cid_html.php', 'scripts/mail/railtime_transit_return.py',
        'scripts/mail/test_railtime_transit.py', 'scripts/mail/test_railtime_transit_cid.py',
        'scripts/mail/test_railtime_transit_return.py',
        '.lmzdev/artifacts/temp/codex-uebergabe-signatur/PLAN-STUFE2-CONNECTOR.md',
        'tests/Feature/OutlookUniversalTransitSnapshotProviderTest.php',
    ]
    paths += [root/p for p in required]
    manifest = {'schema': 1, 'state': 'LOCAL-REVIEW-DISABLED-NO-ACTIVATION',
                'requiresExistingLaravelAppAndVendor': True, 'smtpTestsPerformed': False,
                'nativePostfixTestsPerformed': False, 'files': {}}
    entries = {}
    for path in sorted(set(paths)):
        if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(root):
            raise ValueError('E3_PACKAGE_SOURCE_INVALID')
        name = path.relative_to(root).as_posix()
        data = path.read_bytes()
        manifest['files'][name] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(),
                                   'sha256Lf': hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()}
        entries[name] = data
    with zipfile.ZipFile(output, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in entries.items():
            info = zipfile.ZipInfo(name)
            info.external_attr = 0o100600 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data)
        archive.writestr('REVIEW-MANIFEST.json', json.dumps(manifest, ensure_ascii=True, indent=2))
    return {'schema': 1, 'state': manifest['state'], 'files': len(entries),
            'zipSha256': hashlib.sha256(output.read_bytes()).hexdigest(), 'zipBytes': output.stat().st_size}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path, help='New local ZIP only; existing output is refused')
    args = parser.parse_args()
    print(json.dumps(build(Path(__file__).resolve().parents[3], args.output)))
