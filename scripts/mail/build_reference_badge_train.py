"""Build a new immutable animated train with the approved reference badge.

The 695x151 canvas, 36 frame boundaries, original motion, smoke, durations,
loop and disposal come from the pinned brand-v1 GIFs. Only the moving badge
panel and its exact reference SVG frame/logo are replaced. No train render,
canvas crop, layout, network or application bootstrap is performed.

Python 3.12 / Pillow 12.2 / CairoSVG 2.9 are the reproducibility reference.
GIF alpha is binary: reference-overlay edges are composited against the same
light/dark backdrop as the approved source and palette-quantized only inside
the badge mask. All pixels and alpha outside that mask must remain exact.

Usage: python -B scripts/mail/build_reference_badge_train.py [--check]
Existing files are never overwritten with different bytes. --check writes
nothing. Both GIF and decoded-first-frame PNG are emitted under new v2 names.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import sys
from pathlib import Path

sys.dont_write_bytecode = True

import cairosvg
from PIL import Image, ImageChops, ImageDraw, ImageOps, __version__ as PILLOW_VERSION

import branded_train_registration as registration
from build_branded_outlook_train import OUTPUT_PINS, SOURCE_PINS, encoded_gif, secure_path

APP = Path(__file__).resolve().parents[2]
DIRECTORIES = ('public/mail-assets', 'resources/mail-templates/assets')
VARIANTS = ('light', 'dark', 'light-mirrored', 'dark-mirrored')
SIZE = (695, 151)
PANEL = (535, 86, 581, 132)
OVERLAY = 'resources/mail-templates/source/rt-train-reference-badge-v2.svg'
OVERLAY_SHA = '456a886b9efc1a71fc6914ab484e7a479b50b226c16ef3c3f33ed5f10daf3885'
EXPECTED_OUTPUTS = {'zug-dampf-outlook-brand-v2-light.gif': {'sha256': '6aeef403af2bd639b8fee076312a37b6b3e27f22c148ae835ca8d5d43fcc3a2e',
                                          'bytes': 182772},
 'zug-dampf-outlook-brand-v2-light.png': {'sha256': '063984f410dce4d35f422c19aac565d8d5b59c95876f2db1462de487e33be387',
                                          'bytes': 7984},
 'zug-dampf-outlook-brand-v2-dark.gif': {'sha256': '92913c81a7a44a28560e23f61907ae38b992b31dd10494ab4f3d9d13fc20ca24',
                                         'bytes': 137334},
 'zug-dampf-outlook-brand-v2-dark.png': {'sha256': '19d3969cd41693aaa35c54503db2c7b412f2b2dca2ed65eb8609019af6840d61',
                                         'bytes': 4988},
 'zug-dampf-outlook-brand-v2-light-mirrored.gif': {'sha256': '11ded023b6a25d319c87a8fb25f105c98e8b58a5f9b0fcb711cb6ff3dc10bee6',
                                                   'bytes': 182778},
 'zug-dampf-outlook-brand-v2-light-mirrored.png': {'sha256': 'f1a2c9e0ba241c210ba9a1331dd406f7fce8d893e3b2b89528f33999853498da',
                                                   'bytes': 8056},
 'zug-dampf-outlook-brand-v2-dark-mirrored.gif': {'sha256': 'c5ae93d54d0d2ed7a30ea3b8402a5598f57e25271d267b37719f899361308c5a',
                                                  'bytes': 136792},
 'zug-dampf-outlook-brand-v2-dark-mirrored.png': {'sha256': 'e0cd25a52aa69159b10af004d407994cb2c6673a0c459d67856232d1e9a1ef63',
                                                  'bytes': 5058}}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def exact_outside(old, new, mask):
    diff = ImageChops.difference(old, new)
    for channel in diff.split():
        if ImageChops.multiply(channel, ImageOps.invert(mask)).getbbox():
            raise ValueError('Train or smoke pixels outside the registered badge changed')


def composite_badge(frame, overlay, dx, mirrored, backdrop):
    panel = PANEL if not mirrored else (695 - PANEL[2], PANEL[1], 695 - PANEL[0], PANEL[3])
    shifted = (panel[0] + dx, panel[1], panel[2] + dx, panel[3])
    mask = Image.new('L', SIZE, 0)
    draw = ImageDraw.Draw(mask)
    draw.rectangle((shifted[0], shifted[1], shifted[2] - 1, shifted[3] - 1), fill=255)
    placed = Image.new('RGBA', SIZE, (0, 0, 0, 0))
    placed.paste(overlay, (dx, 0))
    mask = ImageChops.lighter(mask, placed.getchannel('A').point(lambda a: 255 if a else 0))
    result = frame.copy()
    result.paste((0, 0, 0, 0), shifted)
    result = Image.alpha_composite(result, placed)
    # Keep binary transparency and the historical backdrop-based antialiasing
    # convention. This affects only newly rendered reference-badge pixels.
    pix = result.load()
    points = set()
    bounds = mask.getbbox()
    if bounds:
        for y in range(bounds[1], bounds[3]):
            for x in range(bounds[0], bounds[2]):
                if not mask.getpixel((x, y)):
                    continue
                points.add((x, y))
                red, green, blue, alpha = pix[x, y]
                if alpha:
                    pix[x, y] = tuple(round(c * alpha / 255 + b * (1 - alpha / 255))
                                      for c, b in zip((red, green, blue), backdrop)) + (255,)
                else:
                    pix[x, y] = (0, 0, 0, 0)
    exact_outside(frame, result, mask)
    return result, points, mask


def build(check=False):
    before = {}
    for folder in DIRECTORIES:
        for name, expected in SOURCE_PINS.items():
            path = secure_path(folder + '/' + name)
            data = path.read_bytes()
            if sha(data) != expected:
                raise ValueError('Original motion registration input changed: ' + name)
            before[str(path)] = expected
        for variant in VARIANTS:
            for extension in ('gif', 'png'):
                name = 'zug-dampf-outlook-brand-v1-' + variant + '.' + extension
                path = secure_path(folder + '/' + name)
                data = path.read_bytes()
                if sha(data) != OUTPUT_PINS[name]['sha256']:
                    raise ValueError('Approved immutable train input changed: ' + name)
                before[str(path)] = sha(data)
    overlay_path = secure_path(OVERLAY)
    overlay_raw = overlay_path.read_bytes()
    # Git may check out SVG text with CRLF on Windows; render and pin the
    # canonical LF markup just like the historical official-logo builder.
    overlay_data = overlay_raw.replace(b'\r\n', b'\n')
    if sha(overlay_data) != OVERLAY_SHA:
        raise ValueError('Reference SVG frame/logo changed')
    overlay = Image.open(io.BytesIO(cairosvg.svg2png(bytestring=overlay_data,
                                                  output_width=695, output_height=151))).convert('RGBA')
    if overlay.size != SIZE or overlay.getchannel('A').getbbox() != (530, 87, 584, 136):
        raise ValueError('Reference badge overlay escaped its approved geometry')
    report, _originals, _masks, _references = registration.run_registration()
    del _originals, _masks, _references
    outputs = {}
    stats = {}
    for variant in VARIANTS:
        mirrored = variant.endswith('mirrored')
        badge = ImageOps.mirror(overlay) if mirrored else overlay
        backdrop = (12, 16, 23) if variant.startswith('dark') else (255, 255, 255)
        frames, changed, masks, durations, disposals = [], [], [], [], []
        with Image.open(secure_path('public/mail-assets/zug-dampf-outlook-brand-v1-' + variant + '.gif')) as gif:
            palette = gif.getpalette()
            loop = gif.info.get('loop')
            for index in range(gif.n_frames):
                gif.seek(index)
                frame = gif.convert('RGBA')
                row = report['variants'][variant]['frames'][index]
                if row.get('dx') is None:
                    new, points, mask = frame.copy(), set(), Image.new('L', SIZE, 0)
                else:
                    new, points, mask = composite_badge(frame, badge, row['dx'], mirrored, backdrop)
                frames.append(frame)
                changed.append(new)
                masks.append(points)
                durations.append(gif.info.get('duration'))
                disposals.append(gif.disposal_method)
        if len(frames) != 36 or durations != [250, *([200] * 34), 5950] or loop is not None:
            raise ValueError('Approved motion timeline changed')
        content, palette_size = encoded_gif(changed, masks, durations, disposals, loop, palette)
        tops, first, all_bounds = [], None, []
        with Image.open(io.BytesIO(content)) as gif:
            if gif.n_frames != 36 or gif.info.get('loop') != loop:
                raise ValueError('Encoded GIF frame count or loop changed')
            for index, old in enumerate(frames):
                gif.seek(index)
                if gif.info.get('duration') != durations[index] or gif.disposal_method != disposals[index]:
                    raise ValueError('Encoded GIF frame duration/disposal changed')
                actual = gif.convert('RGBA')
                mask = Image.new('L', SIZE, 0)
                if masks[index]:
                    mask.putdata([255 if (n % 695, n // 695) in masks[index] else 0 for n in range(695 * 151)])
                exact_outside(old, actual, mask)
                # Smoke above the body (y<56) must be byte-identical even in
                # frames where the locomotive badge is outside the viewport.
                if ImageChops.difference(old.crop((0, 0, 695, 56)), actual.crop((0, 0, 695, 56))).getbbox():
                    raise ValueError('Full original smoke changed')
                bounds = actual.getchannel('A').getbbox()
                if bounds:
                    tops.append(bounds[1]); all_bounds.append(bounds)
                if index == 0:
                    first = actual.copy()
        buffer = io.BytesIO()
        first.save(buffer, format='PNG', optimize=True)
        stem = 'zug-dampf-outlook-brand-v2-' + variant
        outputs[stem + '.gif'] = content
        outputs[stem + '.png'] = buffer.getvalue()
        stats[variant] = {'frames': 36, 'durationMs': sum(durations), 'outsideBadgePixelChanges': 0,
                          'outsideBadgeAlphaChanges': 0, 'smokeZoneByteExact': True,
                          'canvas': list(SIZE), 'alphaUnion': [min(b[0] for b in all_bounds), min(tops),
                                                            max(b[2] for b in all_bounds), max(b[3] for b in all_bounds)],
                          'paletteEntries': palette_size, 'motionOffsets': [r.get('dx') for r in report['variants'][variant]['frames']]}
    pins = {name: {'sha256': sha(data), 'bytes': len(data)} for name, data in outputs.items()}
    if EXPECTED_OUTPUTS and pins != EXPECTED_OUTPUTS:
        raise ValueError('Reconstruction differs from frozen output pins')
    pending = []
    for folder in DIRECTORIES:
        for name, data in outputs.items():
            path = secure_path(folder + '/' + name)
            if path.exists():
                if path.read_bytes() != data:
                    raise ValueError('Refusing different existing output: ' + name)
            elif check:
                raise ValueError('Missing immutable output: ' + name)
            else:
                pending.append((path, data))
    for path, data in pending:
        with path.open('xb') as target:
            target.write(data)
    if any(sha(Path(path).read_bytes()) != expected for path, expected in before.items()):
        raise ValueError('Original media input changed')
    if overlay_path.read_bytes() != overlay_raw:
        raise ValueError('Reference SVG input changed during the build')
    return {'schema': 'reference-badge-train-v2-proof-v1', 'mode': 'check' if check else 'build',
            'Pillow': PILLOW_VERSION, 'CairoSVG': cairosvg.__version__, 'overlaySha256': OVERLAY_SHA,
            'originalInputsUnchanged': True, 'newFilesCreated': len(pending), 'all144FramesVerified': True,
            'mirrorSemantics': 'Historical whole-train mirroring, including the reference badge',
            'variants': stats, 'outputs': pins}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    try:
        print(json.dumps(build(check=args.check), indent=2))
    except (OSError, ValueError, AssertionError) as error:
        print('Reference train verification failed: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
