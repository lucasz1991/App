#!/usr/bin/env python3
"""Build only the four fixed Outlook-mobile hotline PNGs.

Uses the repository's existing Feather outline vectors and installed Arial
fonts. Font/vector hashes are pinned: no font files are redistributed, no
network access or package installation occurs, and no mail source is edited.
The text PNG supplies visual typography only; callers must retain semantic
alt text and the separate mail/tel anchor actions.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import xml.etree.ElementTree as ET

from fontTools.pens.basePen import BasePen
from fontTools.svgLib.path import parse_path
from PIL import Image, ImageDraw, ImageFont, __version__ as PILLOW_VERSION


RED = (233, 0, 50)  # #e90032: the existing native frame's left border.
WHITE = (255, 255, 255)
SCALE = 3
HEIGHT = 34
ROOT = Path(__file__).resolve().parents[1]
FONT_HASHES = {
    "arialbi.ttf": "e432c1b3568bd207e30e3d52d41e6431ea53a679eb52dd79b1a36aa6a4f3b994",
    "arialbd.ttf": "766f06ac8761f82f25d032a220e89438f6064591af9915061f20b949efdedf69",
}
VECTOR_HASHES = {
    "mail": "12c012e83f3c1d5bcc255208a401ec1cda7d15afb2dc0cfdc414436b4576e806",
    "phone": "0149f0cb2327664f602d951b3b2504f6dac81c5880020a9825c5e704bb2bb259",
}


def pinned(path: Path, expected: str) -> Path:
    actual = hashlib.sha256(path.read_bytes()).hexdigest()
    if actual != expected:
        raise RuntimeError(f"Input changed: {path.name}; expected {expected}, got {actual}")
    return path


def canvas(width: int) -> Image.Image:
    return Image.new("RGB", (width * SCALE, HEIGHT * SCALE), RED)


def label(font_dir: Path) -> Image.Image:
    image = canvas(152)
    draw = ImageDraw.Draw(image)
    main = ImageFont.truetype(str(pinned(font_dir / "arialbi.ttf", FONT_HASHES["arialbi.ttf"])), 24 * SCALE)
    secondary = ImageFont.truetype(str(pinned(font_dir / "arialbd.ttf", FONT_HASHES["arialbd.ttf"])), 14 * SCALE)
    first = main.getbbox("24/7")
    second = secondary.getbbox("erreichbar")
    first_width = first[2] - first[0]
    second_width = second[2] - second[0]
    gap = 9 * SCALE
    left = (image.width - first_width - gap - second_width) / 2
    middle = image.height / 2
    draw.text((left - first[0], middle - (first[1] + first[3]) / 2), "24/7", font=main, fill=WHITE)
    draw.text((left + first_width + gap - second[0], middle - (second[1] + second[3]) / 2), "erreichbar", font=secondary, fill=WHITE)
    # The reviewed glyphs start at physical x=44 on the centred source canvas.
    # Remove only that blank gutter, preserving every glyph pixel, font size,
    # baseline and inter-word gap. 137 logical pixels keep the following mail
    # icon's optical gap within one third of a pixel of the original artwork.
    return image.crop((44, 0, 44 + 137 * SCALE, image.height))


class OutlinePen(BasePen):
    """Sample the unchanged Feather SVG curves for Pillow's native stroke."""

    def __init__(self) -> None:
        super().__init__(None)
        self.paths: list[list[tuple[float, float]]] = []
        self.current: list[tuple[float, float]] = []

    def _moveTo(self, point):
        self._endPath()
        self.current = [point]

    def _lineTo(self, point):
        self.current.append(point)

    def _curveToOne(self, first, second, end):
        start = self.current[-1]
        for step in range(1, 25):
            t = step / 24
            u = 1 - t
            self.current.append(tuple(u**3 * start[axis] + 3 * u**2 * t * first[axis] + 3 * u * t**2 * second[axis] + t**3 * end[axis] for axis in (0, 1)))

    def _qCurveToOne(self, control, end):
        start = self.current[-1]
        for step in range(1, 25):
            t = step / 24
            u = 1 - t
            self.current.append(tuple(u**2 * start[axis] + 2 * u * t * control[axis] + t**2 * end[axis] for axis in (0, 1)))

    def _closePath(self):
        self.current.append(self.current[0])
        self._endPath()

    def _endPath(self):
        if self.current:
            self.paths.append(self.current)
            self.current = []


def action(kind: str) -> Image.Image:
    path = ROOT / "public/build/libs/feather-icons/icons" / f"{kind}.svg"
    svg = ET.parse(pinned(path, VECTOR_HASHES[kind])).getroot()
    if svg.attrib.get("viewBox") != "0 0 24 24" or svg.attrib.get("stroke-width") != "2":
        raise RuntimeError("Unexpected canonical Feather geometry")
    pen = OutlinePen()
    for element in svg:
        tag = element.tag.rsplit("}", 1)[-1]
        if tag == "path":
            parse_path(element.attrib["d"], pen)
            pen._endPath()
        elif tag == "polyline":
            numbers = [float(value) for value in element.attrib["points"].replace(",", " ").split()]
            pen.paths.append(list(zip(numbers[::2], numbers[1::2])))
        else:
            raise RuntimeError(f"Unknown Feather primitive: {tag}")
    # Render at 12 logical pixels per unit, then downsample to the requested
    # 3x asset. Rounded caps/joins preserve the existing outline icon language.
    sample = 12
    image = Image.new("RGB", (42 * sample, HEIGHT * sample), RED)
    draw = ImageDraw.Draw(image)
    radius = sample
    for outline in pen.paths:
        points = [((x + 9) * sample, (y + 5) * sample) for x, y in outline]
        draw.line(points, fill=WHITE, width=2 * sample, joint="curve")
        for x, y in points:
            draw.ellipse((x - radius, y - radius, x + radius, y + radius), fill=WHITE)
    return image.resize((42 * SCALE, HEIGHT * SCALE), Image.Resampling.LANCZOS)


def validate(image: Image.Image, kind: str) -> dict:
    if image.mode != "RGB":
        raise RuntimeError("Hotline assets must be opaque RGB, without alpha")
    edges = [image.getpixel((x, y)) for x in range(image.width) for y in (0, image.height - 1)]
    vertical_edges = (image.width - 1,) if kind == "label" else (0, image.width - 1)
    edges += [image.getpixel((x, y)) for y in range(image.height) for x in vertical_edges]
    if any(pixel != RED for pixel in edges):
        raise RuntimeError("Every asset edge must match the native red border")
    whites = sum(pixel == WHITE for pixel in image.get_flattened_data())
    if kind != "fill" and whites < 100:
        raise RuntimeError("The intended white glyph pixels are missing")
    return {"physical": list(image.size), "mode": image.mode, "white_pixels": whites}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--font-dir", type=Path, default=Path("C:/Windows/Fonts"))
    parser.add_argument("--only", action="append", choices=("fill", "label", "mail", "phone"), help="Write only these assets; the default writes all four")
    args = parser.parse_args()
    if PILLOW_VERSION != "12.2.0":
        raise RuntimeError("Deterministic builder expects the existing Pillow 12.2.0 runtime")
    assets = {"fill": Image.new("RGB", (1, HEIGHT), RED), "label": label(args.font_dir), "mail": action("mail"), "phone": action("phone")}
    target = ROOT / "public/mail-assets"
    if not target.is_dir():
        raise RuntimeError("Run from the existing repository; output directory is missing")
    receipt = {}
    for kind, image in assets.items():
        if args.only and kind not in args.only:
            continue
        proof = validate(image, kind)
        path = target / f"outlook-hotline-{kind}-v1.png"
        image.save(path, format="PNG", optimize=False, compress_level=9)
        binary = path.read_bytes()
        receipt[path.name] = {**proof, "bytes": len(binary), "sha256": hashlib.sha256(binary).hexdigest()}
    print(json.dumps({"red": "#e90032", "white": "#ffffff", "scale": SCALE, "assets": receipt}, indent=2))


if __name__ == "__main__":
    main()
