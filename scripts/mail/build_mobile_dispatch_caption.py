"""Build/check the additive white-alpha 80x18 mobile Dispo caption.

The same pinned native Arial outlines used by the existing caption generator
are rasterized at 2x. Fonts are read locally and are not redistributed. No
historical asset is overwritten; --check recreates bytes without writes.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path

import cairosvg
import fontTools
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image, __version__ as PILLOW_VERSION


APP = Path(__file__).absolute().parents[2]
NAME = "outlook-dispo-mobile-caption-alpha-v1.png"
FONTS = {
    "arialbd.ttf": "766f06ac8761f82f25d032a220e89438f6064591af9915061f20b949efdedf69",
    "arialbi.ttf": "e432c1b3568bd207e30e3d52d41e6431ea53a679eb52dd79b1a36aa6a4f3b994",
}


def sha(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def render(font_dir: Path) -> bytes:
    if (PILLOW_VERSION, cairosvg.__version__, fontTools.__version__) != ("12.2.0", "2.9.0", "4.63.0"):
        raise ValueError("The pinned native raster runtime changed")
    for name, expected in FONTS.items():
        if sha((font_dir / name).read_bytes()) != expected:
            raise ValueError("Approved Arial font changed: " + name)
    groups, x = [], 0.0
    for text, filename in [("Dispo", "arialbd.ttf"), ("24/7", "arialbi.ttf")]:
        with TTFont(font_dir / filename) as font:
            glyphs, cmap = font.getGlyphSet(), font.getBestCmap()
            scale = 14 / font["head"].unitsPerEm
            pen, bounds = SVGPathPen(glyphs), BoundsPen(glyphs)
            advance = 0
            for char in text:
                glyph = glyphs[cmap[ord(char)]]
                transform = (1, 0, 0, 1, advance, 0)
                glyph.draw(TransformPen(pen, transform))
                glyph.draw(TransformPen(bounds, transform))
                advance += glyph.width
            left, bottom, right, top = bounds.bounds
            rectangle = [x + left * scale, 13 - top * scale, x + right * scale, 13 - bottom * scale]
            if min(rectangle) < 0 or rectangle[2] > 80 or rectangle[3] > 18:
                raise ValueError("Mobile caption outline would be clipped")
            groups.append(f'<path fill="#ffffff" transform="translate({x:.9f} 13) scale({scale:.12f} {-scale:.12f})" d="{pen.getCommands()}"/>')
            x += advance * scale + 4
    svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="18" viewBox="0 0 80 18">' + "".join(groups) + "</svg>"
    raster = cairosvg.svg2png(bytestring=svg.encode("utf-8"), output_width=160, output_height=36)
    alpha = Image.open(io.BytesIO(raster)).getchannel("A")
    image = Image.new("RGBA", alpha.size, (255, 255, 255, 0))
    image.putalpha(alpha)
    if alpha.getextrema() != (0, 255) or alpha.getbbox() is None:
        raise ValueError("A real transparent foreground is required")
    output = io.BytesIO()
    image.save(output, format="PNG", optimize=True, compress_level=9)
    return output.getvalue()


def immutable(path: Path, value: bytes, check: bool) -> int:
    if path.is_symlink() or path.parent.is_symlink():
        raise ValueError("Asset outputs must not be symlinks")
    if path.exists():
        if path.read_bytes() != value:
            raise ValueError("Immutable asset differs: " + str(path))
        return 0
    if check:
        raise ValueError("Immutable asset is missing: " + str(path))
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("xb") as stream:
        stream.write(value)
    return 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--font-dir", type=Path, default=Path("C:/Windows/Fonts"))
    args = parser.parse_args()
    try:
        before = {p: sha(p.read_bytes()) for directory in ("public/mail-assets", "public/m", "resources/mail-templates/assets") for p in (APP / directory).iterdir() if p.is_file()}
        value = render(args.font_dir)
        digest = sha(value)
        output = "/m/" + digest[:10] + ".png"
        created = sum(immutable(APP / directory / NAME, value, args.check) for directory in ("resources/mail-assets", "resources/mail-templates/assets", "public/mail-assets"))
        created += immutable(APP / "public" / output.lstrip("/"), value, args.check)
        if any(sha(path.read_bytes()) != expected for path, expected in before.items()):
            raise ValueError("An existing historical asset changed")
        image = Image.open(io.BytesIO(value))
        pixels = list(image.get_flattened_data())
        print(json.dumps({"schema": "outlook-mobile-dispatch-caption-alpha-v1", "filename": NAME,
            "assetUrl": "/mail-assets/" + NAME, "url": output, "sha256": digest, "bytes": len(value),
            "mime": "image/png", "dimensions": [160, 36], "display": [80, 18], "foreground": "#ffffff",
            "alphaExtrema": image.getchannel("A").getextrema(), "alphaBbox": image.getchannel("A").getbbox(),
            "transparentPixels": sum(p[3] == 0 for p in pixels), "opaquePixels": sum(p[3] == 255 for p in pixels),
            "antialiasedPixels": sum(0 < p[3] < 255 for p in pixels), "allPixelsPureWhite": all(p[:3] == (255, 255, 255) for p in pixels),
            "historicalAssetsUnchanged": True, "created": created, "mode": "check" if args.check else "build"}, indent=2))
    except (OSError, ValueError) as error:
        parser.exit(1, "Mobile caption build failed: " + str(error) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
