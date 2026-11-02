"""Copy/check the four exact approved handoff PNGs; never rasterize or alter pixels."""
from pathlib import Path
import argparse
import hashlib
import struct

ROOT = Path(__file__).resolve().parents[2]
SOURCE_DOCUMENT_SHA256 = '8210c549eb27aec006bca093b4197fbb3f19da21fc853bb49f6ffc00640f2916'
PINS = {
    'email': ('9dfa25b253b065c73173b884939db2a032453c44eb2ba6e9e65434a95a072675', 1004),
    'mobile': ('d92bd192b664c536fe29b1c927023bca44041de90bc9bfe34e6df03be5006d32', 591),
    'web': ('1593d63ccacf8c884b71870fe1478f218ea0f541e918d84115978bc2b75c64ff', 1649),
    'location': ('a38bc7d27c9c8c04c2a20815ad9576c76aebfe0bd651f9c5e90909b83bdf6925', 1226),
}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-dir', type=Path, help='Approved handoff folder, needed only for initial immutable copies.')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if args.source_dir:
        source = args.source_dir.resolve()
        assert hashlib.sha256((source / 'RailTime-Vorlage-mit-Signatur-Mail.html').read_bytes()).hexdigest() == SOURCE_DOCUMENT_SHA256
    for kind, (sha, size) in PINS.items():
        name = f'native-handoff-contact-{kind}-v1.png'
        canonical = ROOT / 'resources/mail-assets' / name
        binary = ((args.source_dir / 'assets' / f'rot-contact-{kind}.png') if args.source_dir else canonical).read_bytes()
        assert len(binary) == size and hashlib.sha256(binary).hexdigest() == sha
        assert binary[:8] == b'\x89PNG\r\n\x1a\n' and struct.unpack('>II', binary[16:24]) == (44, 44)
        for target in [canonical, ROOT / 'resources/mail-templates/assets' / name, ROOT / 'public/mail-assets' / name, ROOT / 'public/m' / (sha[:10] + '.png')]:
            assert not target.is_symlink()
            if args.check or target.exists():
                assert target.read_bytes() == binary, f'Immutable target differs: {target}'
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(binary)
    print('PASS: four exact approved handoff PNGs, sixteen immutable copies, 44x44 RGBA.')


if __name__ == '__main__':
    main()
