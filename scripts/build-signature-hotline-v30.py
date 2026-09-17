"""Build NEW v30 hotline GIF slices. Never edits the historical train generator.

python scripts/build-signature-hotline-v30.py --output <new-or-existing-v30-folder>
Requires Pillow. Labels stay in fixed linked wagons; only wheels/smoke move.
No loop extension: the final frame is a smoke-free, readable still.
"""
import argparse
import hashlib
import json
import math
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageChops

ROOT = Path(__file__).resolve().parents[1]
RED = (230, 0, 50)
WHITE = (255, 255, 255)
WIDTHS = [360, 220, 220, 320]
HEIGHT = 224


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build(output):
    output = output.resolve()
    if output == ROOT or ROOT / 'public' in output.parents or ROOT / 'scripts' in output.parents:
        raise ValueError('Use a separate artifact output folder, never historical source/media folders.')
    output.mkdir(parents=True, exist_ok=True)
    originals = [ROOT / 'scripts/build-signature-trains.py', *sorted((ROOT / 'public/mail-assets').glob('zug-*'))]
    before = {str(p): sha(p) for p in originals if p.is_file()}
    source = Image.open(ROOT / 'public/mail-assets/zug-dampf-v19-light.png').convert('RGBA')
    # Reuse the real RailTime locomotive/tender; preserve its proportions.
    loco = source.crop((889, 72, 1210, 171))
    white_loco = Image.new('RGBA', loco.size, WHITE + (0,))
    ink = loco.convert('L').point(lambda value: min(255, max(0, (255 - value) * 6)))
    white_loco.putalpha(ImageChops.multiply(ink, loco.getchannel('A')))
    white_loco.thumbnail((306, 110), Image.Resampling.LANCZOS)
    font = ImageFont.truetype('C:/Windows/Fonts/arialbd.ttf', 40)
    small = ImageFont.truetype('C:/Windows/Fonts/arialbd.ttf', 24)
    frames = []
    for frame in range(25):
        image = Image.new('RGB', (sum(WIDTHS), HEIGHT), RED)
        d = ImageDraw.Draw(image)
        x = 0
        for index, width in enumerate(WIDTHS[:3]):
            d.line((x, 184, x + width, 184), fill=WHITE, width=3)
            d.rounded_rectangle((x + 14, 100, x + width - 14, 179), radius=7, outline=WHITE, width=3)
            d.line((x + 26, 94, x + width - 26, 94), fill=WHITE, width=3)
            d.line((x + 27, 105, x + 27, 175), fill=(255, 160, 180), width=2)
            d.line((x + width - 27, 105, x + width - 27, 175), fill=(255, 160, 180), width=2)
            for wx in [x + 48, x + width - 48]:
                d.ellipse((wx - 14, 184, wx + 14, 212), fill=RED, outline=WHITE, width=3)
                angle = (frame if frame < 24 else 0) * math.pi / 5
                dx, dy = 10 * math.cos(angle), 10 * math.sin(angle)
                d.line((wx - dx, 198 - dy, wx + dx, 198 + dy), fill=WHITE, width=2)
            cx = x + width // 2
            if index == 0:
                d.text((cx, 119), '24/7', font=font, fill=WHITE, anchor='mm')
                d.text((cx, 157), 'HOTLINE', font=small, fill=WHITE, anchor='mm')
            elif index == 1:
                d.line([(cx-22, 119), (cx-17, 137), (cx-4, 150), (cx+15, 156)], fill=WHITE, width=9, joint='curve')
                d.polygon([(cx-29,114),(cx-14,109),(cx-7,127),(cx-21,133)], fill=WHITE)
                d.polygon([(cx+10,143),(cx+29,151),(cx+23,165),(cx+5,157)], fill=WHITE)
            else:
                d.rounded_rectangle((cx - 33, 119, cx + 33, 158), radius=4, outline=WHITE, width=4)
                d.line((cx - 31, 122, cx, 143, cx + 31, 122), fill=WHITE, width=4)
            x += width
        image.paste(white_loco, (x + 4, 213 - white_loco.height), white_loco)
        # Compact plumes well inside the canvas; fade out before the last frame.
        if frame < 20:
            smoke = Image.new('RGBA', image.size)
            sd = ImageDraw.Draw(smoke)
            for n in range(3):
                phase = ((frame + n * 6) % 20) / 20
                sx, sy = x + 281 - phase * 70, 111 - phase * 70
                radius = 4 + phase * 9
                alpha = int(95 * (1 - phase) * min(1, (20 - frame) / 6))
                sd.ellipse((sx-radius, sy-radius, sx+radius, sy+radius), fill=WHITE + (alpha,))
            image = Image.alpha_composite(image.convert('RGBA'), smoke).convert('RGB')
        frames.append(image)
    durations = [100] * 24 + [600]
    manifest = []
    left = 0
    names = ['hotline', 'anrufen', 'email', 'lok']
    palette = frames[0].quantize(colors=48)
    for name, width in zip(names, WIDTHS):
        slices = [f.crop((left, 0, left + width, HEIGHT)).quantize(palette=palette, dither=Image.Dither.NONE) for f in frames]
        target = output / f'v30-zug-{name}.gif'
        slices[0].save(target, save_all=True, append_images=slices[1:], duration=durations, optimize=True, disposal=1)
        with Image.open(target) as saved:
            if 'loop' in saved.info:
                raise ValueError('Unexpected looping GIF')
            manifest.append({'name': target.name, 'width': width, 'height': HEIGHT, 'bytes': target.stat().st_size, 'frames': saved.n_frames, 'sha256': sha(target)})
        left += width
    frames[0].save(output / 'v30-zug-erster-frame.png', optimize=True)
    frames[-1].save(output / 'v30-zug-standbild.png', optimize=True)
    # Subtle route-line illustration: source SVG plus mail-compatible PNG.
    backdrop = Image.new('RGB', (1200, 420), WHITE)
    draw = ImageDraw.Draw(backdrop)
    paths = []
    for n in range(7):
        points = [(650+n*38, 0), (650+n*38, 120), (870+n*38, 340), (1200, 340+n*8)]
        draw.line(points, fill=(243, 245, 247), width=2)
        paths.append('<polyline points="'+' '.join(f'{x},{y}' for x,y in points)+'"/>')
    draw.line([(905, 0), (905, 94), (1140, 329), (1200, 329)], fill=(252, 231, 236), width=3)
    backdrop.save(output / 'v30-streckennetz.png', optimize=True)
    (output / 'v30-streckennetz.svg').write_text('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="420" viewBox="0 0 1200 420"><rect width="1200" height="420" fill="white"/><g fill="none" stroke="#f3f5f7" stroke-width="2">'+''.join(paths)+'</g><path d="M905 0V94L1140 329H1200" fill="none" stroke="#fce7ec" stroke-width="3"/></svg>', encoding='utf-8')
    after = {str(p): sha(p) for p in originals if p.is_file()}
    if before != after:
        raise ValueError('Historical media changed!')
    report = {'version': 'v30', 'animation': 'fixed linked wagons; moving wheels and intro smoke; smoke-free end; no repeat', 'media': manifest, 'total_gif_bytes': sum(m['bytes'] for m in manifest), 'originals_unchanged': True, 'original_hashes': before}
    (output / 'gif-report.json').write_text(json.dumps(report, indent=2)+'\n', encoding='utf-8')
    print(json.dumps({'total_gif_bytes': report['total_gif_bytes'], 'originals_unchanged': True}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    build(parser.parse_args().output)
