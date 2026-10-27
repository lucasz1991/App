"""Build immutable, intrinsic 695x151 Outlook train viewports.

The approved SVG/Canvas render profile is tools/render-zug-einfahrt.mjs,
using resources/mail-templates/assets/zug-dampf-ohne-rauch.svg. Its defaults
are not the approved V19/V27 profile. To retain the exact approved smoke,
engine geometry and 36-frame timeline, this delivery-only build therefore
uses the hash-pinned, coalesced outputs of build_delivery_train.py.

Only a horizontal viewport is selected. There is no scaling, vertical crop,
resampling, smoke synthesis, or change to any original asset. The normal
variant retains the right edge/engine; the mirrored variant retains the left.
At 300 CSS pixels wide the intrinsic height is 65.179856 pixels, independently
of CSS transform/overflow support. SVG remains a trusted build input, never
an email attachment or an addition to the editor's supported image types.

Usage: python scripts/mail/build_intrinsic_outlook_train.py [--check]
Optional proof: --contact-sheet <path-outside-the-two-asset-directories.png>

Python 3.12 / Pillow 12.2.0 is the reproducibility reference. Existing outputs
must match byte-for-byte; a different file is never overwritten.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path

from PIL import GifImagePlugin, Image, ImageChops, ImageDraw, __version__ as PILLOW_VERSION

from build_delivery_train import decode_gif


APP = Path(__file__).resolve().parents[2]
ASSETS = APP / "public" / "mail-assets"
RESOURCE_ASSETS = APP / "resources" / "mail-templates" / "assets"
OUTPUT_DIRS = (ASSETS, RESOURCE_ASSETS)
STEM = "zug-dampf-outlook-intrinsic-v1"
SIZE = (695, 151)
DURATIONS = [250, *([200] * 34), 5950]
SOURCE_HASHES = {
    "light": (
        "e0f8327a1041e23bdbbb2a478978e81521efe4e021767e40ad30034ed61dbe53",
        "e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e",
    ),
    "dark": (
        "380b43ab43da0c724c492abf8f095917ad06408e7de07aaf33134bf82afb50e9",
        "2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb",
    ),
    "light-mirrored": (
        "52c80c7a8a193472d50346a14a2876a857cce4f1dc98b944dfe98a7fbc94a840",
        "b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51",
    ),
    "dark-mirrored": (
        "adb9dbd54e0945c985a96f939867448b787a4990550d8cfefb16fa4c757a20e9",
        "5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c",
    ),
}
VECTOR_HASHES = {
    "resources/mail-templates/source/rt-dampflok.svg": "660980cc354600caa908167b710ee27f95b7bb36992df99c646b1d5c94a36172",
    "resources/mail-templates/assets/zug-dampf-ohne-rauch.svg": "95378020360d7300961f99e8e9c85cc8bf4d49f1e65b12f0b7e130e7336afc4e",
}


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def checked_inputs() -> dict[Path, str]:
    originals = {}
    for relative, expected in VECTOR_HASHES.items():
        path = APP / relative
        if digest(path.read_bytes()) != expected:
            raise ValueError(f"Trusted vector source changed: {path}")
        originals[path] = expected
    for variant, hashes in SOURCE_HASHES.items():
        for extension, expected in zip(("gif", "png"), hashes):
            for directory in OUTPUT_DIRS:
                path = directory / f"zug-dampf-v27-delivery-{variant}.{extension}"
                if digest(path.read_bytes()) != expected:
                    raise ValueError(f"Trusted delivery source changed: {path}")
                originals[path] = expected
    return originals


def alpha_mask(image: Image.Image) -> Image.Image:
    return image.getchannel("A").point(lambda value: 255 if value else 0)


def visible_rgba(image: Image.Image) -> Image.Image:
    """Ignore undefined RGB under fully transparent GIF pixels."""
    result = Image.new("RGBA", image.size, (0, 0, 0, 0))
    result.paste(image, mask=alpha_mask(image))
    return result


def encode_exact_frames(frames: list[Image.Image], disposal: list[int]) -> bytes:
    colors = set()
    for frame in frames:
        for red, green, blue, alpha in frame.get_flattened_data():
            if alpha not in (0, 255):
                raise ValueError("Coalesced GIF unexpectedly contains partial alpha")
            if alpha:
                colors.add((red, green, blue))
    if len(colors) > 255:
        raise ValueError("Lossless shared palette exceeds GIF's 255 visible colors")
    ordered = sorted(colors)
    lookup = {color: index + 1 for index, color in enumerate(ordered)}
    palette = [0, 0, 0, *(channel for color in ordered for channel in color)]
    palette += [0] * (768 - len(palette))
    indexed = []
    for frame in frames:
        pixels = bytes(lookup[(r, g, b)] if a else 0 for r, g, b, a in frame.get_flattened_data())
        image = Image.frombytes("P", frame.size, pixels)
        image.putpalette(palette)
        indexed.append(image)
    # save_all merges identical consecutive frames even with optimize=False.
    # Writing each encoded image block retains every approved frame boundary.
    header, _ = GifImagePlugin.getheader(
        indexed[0], info={"transparency": 0, "background": 0, "optimize": False}
    )
    blocks = [*header]
    for frame, duration, method in zip(indexed, DURATIONS, disposal):
        blocks.extend(GifImagePlugin.getdata(
            frame, duration=duration, disposal=method, transparency=0,
            include_color_table=False, optimize=False,
        ))
    blocks.append(b";")
    return b"".join(blocks)


def build_variant(variant: str) -> tuple[dict[str, bytes], list[Image.Image], dict]:
    source = (ASSETS / f"zug-dampf-v27-delivery-{variant}.gif").read_bytes()
    frames, durations, loop = decode_gif(source)
    if len(frames) != 36 or durations != DURATIONS or loop is not None:
        raise ValueError(f"Approved timeline changed: {variant}")
    with Image.open(io.BytesIO(source)) as gif:
        disposal = []
        for index in range(gif.n_frames):
            gif.seek(index)
            disposal.append(gif.disposal_method)
    with Image.open(ASSETS / f"zug-dampf-v27-delivery-{variant}.png") as png:
        still = png.convert("RGBA").copy()
    if any(frame.size != still.size for frame in frames) or still.height != SIZE[1]:
        raise ValueError("Source GIF/PNG canvas is not the approved full-height delivery")
    left = 0 if variant.endswith("-mirrored") else still.width - SIZE[0]
    crop = (left, 0, left + SIZE[0], SIZE[1])
    cropped = [frame.crop(crop) for frame in frames]
    cropped_still = still.crop(crop)
    visible_bounds = [bounds for frame in cropped if (bounds := alpha_mask(frame).getbbox())]
    alpha_union = (
        min(bounds[0] for bounds in visible_bounds), min(bounds[1] for bounds in visible_bounds),
        max(bounds[2] for bounds in visible_bounds), max(bounds[3] for bounds in visible_bounds),
    )
    if alpha_union[1] <= 0:
        raise ValueError("Smoke touches the top edge of the intrinsic viewport")
    first_bounds = alpha_mask(cropped[0]).getbbox()
    if first_bounds is None or first_bounds[2] - first_bounds[0] < SIZE[0] * .95:
        raise ValueError("First frame lost the complete visible train viewport")
    if ImageChops.difference(alpha_mask(cropped[0]), alpha_mask(cropped_still)).getbbox():
        raise ValueError("First GIF frame and PNG have different visible geometry")
    gif_content = encode_exact_frames(cropped, disposal)
    actual, actual_durations, actual_loop = decode_gif(gif_content)
    if len(actual) != 36 or actual_durations != DURATIONS or actual_loop is not None:
        raise ValueError("Encoder changed frame boundaries, duration or loop")
    for index, (expected, encoded) in enumerate(zip(cropped, actual)):
        if encoded.size != SIZE or ImageChops.difference(visible_rgba(expected), visible_rgba(encoded)).getbbox(alpha_only=False):
            raise ValueError(f"Engine/smoke pixels changed inside viewport frame {index}")
    with Image.open(io.BytesIO(gif_content)) as encoded_gif:
        encoded_disposal = []
        for index in range(encoded_gif.n_frames):
            encoded_gif.seek(index)
            encoded_disposal.append(encoded_gif.disposal_method)
        if encoded_disposal != disposal:
            raise ValueError("Encoder changed disposal behavior")
    png_buffer = io.BytesIO()
    cropped_still.save(png_buffer, format="PNG", optimize=True)
    png_content = png_buffer.getvalue()
    with Image.open(io.BytesIO(png_content)) as encoded:
        if ImageChops.difference(cropped_still, encoded.convert("RGBA")).getbbox(alpha_only=False):
            raise ValueError("PNG crop changed engine geometry or alpha")
    return {"gif": gif_content, "png": png_content}, actual, {
        "variant": variant, "width": SIZE[0], "height": SIZE[1],
        "source_crop": crop, "vertical_rows_retained": SIZE[1],
        "display_height_at_300": 300 * SIZE[1] / SIZE[0],
        "frames": 36, "duration_ms": sum(DURATIONS), "loop": None,
        "first_visible_bounds": first_bounds, "source_disposal_preserved": disposal,
        "alpha_union": alpha_union, "transparent_top_rows": alpha_union[1],
        "visible_pixels_lossless": True, "pillow": PILLOW_VERSION,
    }


def contact_sheet(path: Path, variants: dict[str, list[Image.Image]]) -> None:
    resolved = path.resolve()
    if path.suffix.lower() != ".png" or any(resolved.is_relative_to(directory.resolve()) for directory in OUTPUT_DIRS):
        raise ValueError("Contact sheet must be a PNG outside the asset directories")
    indices = (0, 1, 6, 12, 18, 24, 30, 35)
    sheet = Image.new("RGB", (1280, len(variants) * 200), "#e5e7eb")
    draw = ImageDraw.Draw(sheet)
    for row, (variant, frames) in enumerate(variants.items()):
        background = "#0c1017" if variant.startswith("dark") else "#ffffff"
        for position, index in enumerate(indices):
            x, y = (position % 4) * 320 + 10, row * 200 + (position // 4) * 100 + 25
            draw.rectangle((x, y, x + 300, y + 65), fill=background, outline="#94a3b8")
            preview = frames[index].resize((300, 65), Image.Resampling.LANCZOS)
            sheet.paste(preview, (x, y), preview.getchannel("A"))
            draw.text((x, y - 17), f"{variant} frame {index}", fill="#111827")
    path.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(path, format="PNG")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Verify existing immutable outputs without writes")
    parser.add_argument("--contact-sheet", type=Path)
    args = parser.parse_args()
    if args.check and args.contact_sheet:
        parser.error("--check performs no writes; create the proof sheet in a separate build run")
    originals = checked_inputs()
    outputs = {}
    previews = {}
    reports = []
    for variant in SOURCE_HASHES:
        media, previews[variant], metadata = build_variant(variant)
        for extension, content in media.items():
            for directory in OUTPUT_DIRS:
                path = directory / f"{STEM}-{variant}.{extension}"
                outputs[path] = content
                reports.append({**metadata, "frames": 36 if extension == "gif" else 1,
                                "duration_ms": sum(DURATIONS) if extension == "gif" else None,
                                "path": str(path), "mime": f"image/{extension}",
                                "bytes": len(content), "sha256": digest(content)})
    for path, content in outputs.items():
        if path.exists() and path.read_bytes() != content:
            raise ValueError(f"Refusing to overwrite a different existing output: {path}")
        if args.check and not path.exists():
            raise ValueError(f"Missing immutable output: {path}")
    if not args.check:
        for path, content in outputs.items():
            if not path.exists():
                with path.open("xb") as target:
                    target.write(content)
        if args.contact_sheet:
            contact_sheet(args.contact_sheet, previews)
    for path, expected in originals.items():
        if digest(path.read_bytes()) != expected:
            raise ValueError(f"Original source changed: {path}")
    for report in reports:
        print(json.dumps(report, sort_keys=True))


if __name__ == "__main__":
    main()
