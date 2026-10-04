"""Build separate Outlook delivery train media without changing authoring assets.

The source GIFs deliberately start with an empty entrance frame. These delivery
copies instead start with the existing complete PNG, so clients that disable
animation still show the whole train. All later coalesced animation frames,
their durations, and the source loop setting are retained. One crop is computed
from the union of every GIF frame and its PNG; GIF and PNG then share a canvas
close to 8:1. Identical derived files are written to public/mail-assets and
resources/mail-templates/assets for both live and inline/export delivery.
No browser, network, or application runtime dependency is needed.

From the application root:
    python scripts/mail/build_delivery_train.py
    python scripts/mail/build_delivery_train.py --check

Existing output files are accepted only if they match the deterministic build.
The script never overwrites a different file or any existing source medium.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
from pathlib import Path

from PIL import Image, ImageChops


APP = Path(__file__).resolve().parents[2]
ASSETS = APP / "public" / "mail-assets"
OUTPUT_DIRS = (ASSETS, APP / "resources" / "mail-templates" / "assets")
TARGET_RATIO = 8.0
VARIANTS = (("light", False), ("dark", False), ("light", True), ("dark", True))


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def union_bounds(first: tuple | None, second: tuple | None) -> tuple | None:
    if first is None:
        return second
    if second is None:
        return first
    return (
        min(first[0], second[0]), min(first[1], second[1]),
        max(first[2], second[2]), max(first[3], second[3]),
    )


def decode_gif(content: bytes) -> tuple[list[Image.Image], list[int], int | None]:
    with Image.open(io.BytesIO(content)) as source:
        loop = source.info.get("loop")
        frames = []
        durations = []
        for index in range(source.n_frames):
            source.seek(index)
            frames.append(source.convert("RGBA").copy())
            durations.append(int(source.info.get("duration", 0)))
    if not frames or any(duration <= 0 for duration in durations):
        raise ValueError("Source GIF must have frames with positive durations.")
    return frames, durations, loop


def encode_gif(frames: list[Image.Image], durations: list[int], loop: int | None) -> bytes:
    width, height = frames[0].size
    # A shared 255-color palette reserves index zero solely for transparency.
    strip = Image.new("RGB", (width, height * len(frames)))
    for index, frame in enumerate(frames):
        strip.paste(frame.convert("RGB"), (0, height * index))
    palette = strip.quantize(colors=255, method=Image.Quantize.MEDIANCUT)
    shifted_palette = [0, 0, 0] + palette.getpalette()[:765]
    quantized = []
    for frame in frames:
        encoded = frame.convert("RGB").quantize(palette=palette, dither=Image.Dither.NONE)
        encoded = encoded.point(lambda value: value + 1)
        encoded.putpalette(shifted_palette)
        transparent = frame.getchannel("A").point(lambda value: 255 if value == 0 else 0)
        encoded.paste(0, mask=transparent)
        encoded.info["transparency"] = 0
        quantized.append(encoded)
    options = {"loop": loop} if loop is not None else {}
    buffer = io.BytesIO()
    quantized[0].save(
        buffer, format="GIF", save_all=True, append_images=quantized[1:],
        duration=durations, disposal=2, transparency=0, background=0,
        optimize=False, **options,
    )
    return buffer.getvalue()


def verify_gif(content: bytes, expected: list[Image.Image], durations: list[int], loop: int | None) -> dict:
    frames, encoded_durations, encoded_loop = decode_gif(content)
    if len(frames) != len(expected):
        raise ValueError("Encoder changed the animation frame count.")
    if encoded_durations != durations or encoded_loop != loop:
        raise ValueError("Encoder changed frame timing or loop behavior.")
    for actual, wanted in zip(frames, expected):
        if actual.size != wanted.size:
            raise ValueError("Unexpected delivery canvas dimensions.")
        actual_alpha = actual.getchannel("A").point(lambda value: 255 if value else 0)
        wanted_alpha = wanted.getchannel("A").point(lambda value: 255 if value else 0)
        if ImageChops.difference(actual_alpha, wanted_alpha).getbbox() is not None:
            raise ValueError("Encoder clipped or introduced visible pixels.")
    first_bounds = frames[0].getchannel("A").getbbox()
    if first_bounds is None or (first_bounds[2] - first_bounds[0]) / frames[0].width < .95:
        raise ValueError("First delivery frame must contain the complete train.")
    return {
        "frames": len(frames), "duration_ms": sum(durations), "loop": loop,
        "first_bounds": first_bounds,
    }


def build_variant(theme: str, mirrored: bool, check_only: bool) -> list[dict]:
    suffix = "-mirrored" if mirrored else ""
    name = f"zug-dampf-v27-{theme}{suffix}"
    source_paths = [ASSETS / f"{name}.{extension}" for extension in ("gif", "png")]
    source_content = [path.read_bytes() for path in source_paths]
    original_hashes = [digest(content) for content in source_content]
    animation, durations, loop = decode_gif(source_content[0])
    with Image.open(io.BytesIO(source_content[1])) as source_png:
        still = source_png.convert("RGBA").copy()
    if any(frame.size != still.size for frame in animation):
        raise ValueError("GIF and PNG authoring dimensions differ.")

    crop = still.getchannel("A").getbbox()
    for frame in animation:
        crop = union_bounds(crop, frame.getchannel("A").getbbox())
    if crop is None:
        raise ValueError("Source train has no visible content.")
    width, content_height = crop[2] - crop[0], crop[3] - crop[1]
    height = max(content_height, math.ceil(width / TARGET_RATIO))

    def crop_to_canvas(frame: Image.Image) -> Image.Image:
        canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
        canvas.alpha_composite(frame.crop(crop), (0, height - content_height))
        return canvas

    cropped_still = crop_to_canvas(still)
    frames = [cropped_still, *(crop_to_canvas(frame) for frame in animation[1:])]
    gif_content = encode_gif(frames, durations, loop)
    gif_metadata = verify_gif(gif_content, frames, durations, loop)
    png_buffer = io.BytesIO()
    cropped_still.save(png_buffer, format="PNG", optimize=True)
    output = {"gif": gif_content, "png": png_buffer.getvalue()}
    results = []
    for extension, content in output.items():
        for output_dir in OUTPUT_DIRS:
            path = output_dir / f"zug-dampf-v27-delivery-{theme}{suffix}.{extension}"
            if path.exists():
                if path.read_bytes() != content:
                    raise ValueError(f"Refusing to overwrite a different existing medium: {path}")
            elif check_only:
                raise ValueError(f"Delivery medium missing: {path}")
            else:
                output_dir.mkdir(parents=True, exist_ok=True)
                with path.open("xb") as target:
                    target.write(content)
            results.append({
                "path": str(path), "mime": f"image/{extension}",
                "sha256": digest(content), "bytes": len(content),
                "width": width, "height": height, "crop": crop,
                **(gif_metadata if extension == "gif" else {}),
            })
    if [digest(path.read_bytes()) for path in source_paths] != original_hashes:
        raise ValueError("Original source media changed during conversion.")
    return results


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Verify existing delivery media without writing")
    args = parser.parse_args()
    for theme, mirrored in VARIANTS:
        for result in build_variant(theme, mirrored, args.check):
            print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    main()
