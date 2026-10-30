"""Build/check the immutable 199x38 display caption using pinned Arial outlines.

Requires fontTools 4.63.0, CairoSVG 2.9.0 and Pillow 12.2.0. Fonts are read from
--font-dir (default C:/Windows/Fonts); they are not redistributed. Old files are
never overwritten. --check reconstructs the exact bytes without writing files.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path

import cairosvg
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image


APP = Path(__file__).absolute().parents[2]
NAME = "outlook-dispo-card-caption-v1.png"
SHA256 = "464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7"
SIZE = 6597
FONTS = {
    "arial.ttf": "c9b76220a5be42ead4733611e417cd65c5fd8aeaa33eb56576ac378a37d130a1",
    "arialbd.ttf": "766f06ac8761f82f25d032a220e89438f6064591af9915061f20b949efdedf69",
    "arialbi.ttf": "e432c1b3568bd207e30e3d52d41e6431ea53a679eb52dd79b1a36aa6a4f3b994",
}


def digest(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def render(font_dir: Path) -> bytes:
    for name, expected in FONTS.items():
        if digest((font_dir / name).read_bytes()) != expected:
            raise ValueError("Installed font does not match the approved outline: " + name)
    groups = []
    for text, name, size, x, y in [
        ("Dispo", "arialbd.ttf", 14, 0, 13),
        ("erreichbar", "arial.ttf", 11, 0, 27),
        ("24/7", "arialbi.ttf", 26, None, 28),
    ]:
        with TTFont(font_dir / name) as font:
            glyphs, cmap = font.getGlyphSet(), font.getBestCmap()
            scale = size / font["head"].unitsPerEm
            pen, bounds = SVGPathPen(glyphs), BoundsPen(glyphs)
            advance = 0
            for char in text:
                glyph = glyphs[cmap[ord(char)]]
                transform = (1, 0, 0, 1, advance, 0)
                glyph.draw(TransformPen(pen, transform))
                glyph.draw(TransformPen(bounds, transform))
                advance += glyph.width
            left, bottom, right, top = bounds.bounds
            if x is None:
                x = 198 - right * scale
            rectangle = [x + left * scale, y - top * scale, x + right * scale, y - bottom * scale]
            if min(rectangle) < 0 or rectangle[2] > 199 or rectangle[3] > 38:
                raise ValueError("Caption outline is clipped")
            groups.append(f'<path fill="#ffffff" transform="translate({x:.9f} {y}) scale({scale:.12f} {-scale:.12f})" d="{pen.getCommands()}"/>')
    svg = '<svg xmlns="http://www.w3.org/2000/svg" width="199" height="38" viewBox="0 0 199 38"><rect width="199" height="38" fill="#e90032"/>' + "".join(groups) + "</svg>"
    rendered = cairosvg.svg2png(bytestring=svg.encode("utf-8"), output_width=398, output_height=76)
    image = Image.open(io.BytesIO(rendered)).convert("RGB")
    output = io.BytesIO()
    image.save(output, format="PNG", optimize=True, compress_level=9)
    value = output.getvalue()
    if len(value) != SIZE or digest(value) != SHA256:
        raise ValueError("Rendered caption differs from the approved bytes")
    return value


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--font-dir", type=Path, default=Path("C:/Windows/Fonts"))
    arguments = parser.parse_args()
    try:
        value = render(arguments.font_dir)
        created = 0
        for directory in ["public/mail-assets", "resources/mail-templates/assets"]:
            path = APP / directory / NAME
            if path.is_symlink() or path.parent.is_symlink():
                raise ValueError("Caption output must not be a symlink")
            if path.exists():
                if path.read_bytes() != value:
                    raise ValueError("A different immutable caption already exists")
            elif arguments.check:
                raise ValueError("Caption output is missing")
            else:
                with path.open("xb") as stream:
                    stream.write(value)
                created += 1
        print(json.dumps({"asset": NAME, "sha256": SHA256, "bytes": SIZE, "dimensions": [398, 76], "display": [199, 38], "opaque_brand_red": True, "created": created, "mode": "check" if arguments.check else "build"}, indent=2))
    except (OSError, ValueError) as error:
        parser.exit(1, "Caption build failed: " + str(error) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
