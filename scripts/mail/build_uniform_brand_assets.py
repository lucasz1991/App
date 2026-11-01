"""Build additive, alpha-only brand-v2 assets from pinned native generators.

No old PNG, vector, font, GIF or route is overwritten. --check recreates all
nine color images in memory and verifies twenty-two appended source entries
and eighteen content-addressed PNG copies without writing anything. Two
social PNGs and three stronger Dispo actions are copied byte-exactly from
the user's approved references. Historical variants remain immutable.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys

from PIL import Image, ImageChops, __version__ as PILLOW_VERSION


sys.dont_write_bytecode = True
APP = Path(__file__).absolute().parents[2]
RED = (233, 0, 50)
WHITE = (255, 255, 255)
SOURCE_PINS = {
    "scripts/build-contact-icons.py": "d1a0948b988e1d2890e269dcf67b8954c0773e1b1f8d6bb69fdd9edd174c22e5",
    "scripts/build-outlook-hotline-assets.py": "79a36457885fad3d161a671ff2648450d4f64118ef8468056a10cac006c87813",
    "scripts/mail/build_dispatch_card_caption.py": "b1928fbf0a3a756c74007e2ee4c996e647c6bd1c4fd735ac10ed270a339796a7",
    "public/adminresources/fontawesome6/webfonts/fa-solid-900.ttf": "52c2286953298a02839e2a08efbf44241dc7e07140acf0bbbb731efb5b9ecc5a",
}
OUTPUT_PINS = {
    "contact-location-brand-v2.png": ("ef78563be5b36fb14bbfb9aec2f08e8ab55493648b31aecac64418693ad4a52b", 1197),
    "contact-phone-brand-v2.png": ("ad1ce232f6020548515acfb00b50bfd79cfc08ba9392553419a699a6d80a207b", 1116),
    "contact-mobile-brand-v2.png": ("237a6ef4b921b7ec54a858137f54879b21c756b3a417be245bfa2d91e4d5a6d2", 547),
    "contact-email-brand-v2.png": ("74e44dae98fa9e3115df12d89fa8c979ac5afadff1fe02899dc0e22614bafaee", 972),
    "contact-web-brand-v2.png": ("aee56057875f1695049f7aa018988742e37a15110b175b4926ffd828ce7c255e", 1606),
    "outlook-dispo-card-caption-alpha-v2.png": ("a7626cc065e31143682998876b17647545238bc336786f3e10c62c0613a84d7f", 4750),
    "outlook-hotline-mail-alpha-v2.png": ("30b48b18c0c4c87b5126db3849653e924234e149a6084e1a1eba5235277477e7", 1644),
    "outlook-hotline-phone-alpha-v2.png": ("b1db855fc30a03e64095dca01b45e5c67315c136762a8356e07c5d805f66ca13", 2894),
    "outlook-dispo-card-caption-alpha-v3.png": ("4a55285090810c491f92cbf1f8ffb928ec50daef77ddc2b36a1d3b4f587151ce", 6530),
}
SOCIAL_PINS = {
    "outlook-social-whatsapp-v1.png": ("97a608a8f92464edf98f5374b503f0eb1943a73aaa2e7eb47cc78b85b824227d", 1625),
    "outlook-social-instagram-v1.png": ("3221c628235c1a0df2851f901fa10fd246d674d7f6a6f6e3345d86b171bc0d09", 3662),
}
REFERENCE_ACTION_PINS = {
    "outlook-dispo-mail-kraeftig-v3.png": ("19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1", 859, "dispo-mail-kraeftig.png"),
    "outlook-dispo-telefon-kraeftig-v3.png": ("1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a", 1333, "dispo-telefon-kraeftig.png"),
    "outlook-dispo-whatsapp-weiss-v3.png": ("f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9", 1515, "dispo-whatsapp-weiss.png"),
}
TRAIN_PINS = {
    "zug-dampf-outlook-brand-v2-light.gif": ("6aeef403af2bd639b8fee076312a37b6b3e27f22c148ae835ca8d5d43fcc3a2e", 182772),
    "zug-dampf-outlook-brand-v2-light.png": ("063984f410dce4d35f422c19aac565d8d5b59c95876f2db1462de487e33be387", 7984),
    "zug-dampf-outlook-brand-v2-dark.gif": ("92913c81a7a44a28560e23f61907ae38b992b31dd10494ab4f3d9d13fc20ca24", 137334),
    "zug-dampf-outlook-brand-v2-dark.png": ("19d3969cd41693aaa35c54503db2c7b412f2b2dca2ed65eb8609019af6840d61", 4988),
    "zug-dampf-outlook-brand-v2-light-mirrored.gif": ("11ded023b6a25d319c87a8fb25f105c98e8b58a5f9b0fcb711cb6ff3dc10bee6", 182778),
    "zug-dampf-outlook-brand-v2-light-mirrored.png": ("f1a2c9e0ba241c210ba9a1331dd406f7fce8d893e3b2b89528f33999853498da", 8056),
    "zug-dampf-outlook-brand-v2-dark-mirrored.gif": ("c5ae93d54d0d2ed7a30ea3b8402a5598f57e25271d267b37719f899361308c5a", 136792),
    "zug-dampf-outlook-brand-v2-dark-mirrored.png": ("e0cd25a52aa69159b10af004d407994cb2c6673a0c459d67856232d1e9a1ef63", 5058),
}


def sha(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def module(relative: str, name: str):
    path = APP / relative
    if sha(path.read_bytes()) != SOURCE_PINS[relative]:
        raise ValueError("Native generator changed: " + relative)
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def rgba(ink: tuple[int, int, int], alpha: Image.Image) -> Image.Image:
    image = Image.new("RGBA", alpha.size, (*ink, 0))
    image.putalpha(alpha)
    return image


def encode(image: Image.Image) -> bytes:
    stream = io.BytesIO()
    image.save(stream, format="PNG", optimize=True, compress_level=9)
    return stream.getvalue()


def reference_caption(font_dir: Path) -> Image.Image:
    """Native Arial outlines at the approved 19/15/38px reference typography."""
    import cairosvg
    from fontTools.pens.boundsPen import BoundsPen
    from fontTools.pens.svgPathPen import SVGPathPen
    from fontTools.pens.transformPen import TransformPen
    from fontTools.ttLib import TTFont

    groups = []
    for text, filename, size, x, baseline in [
        ("Dispo", "arialbd.ttf", 19, 0, 18),
        ("erreichbar", "arial.ttf", 15, 0, 38),
        ("24/7", "arialbi.ttf", 38, None, 35),
    ]:
        with TTFont(font_dir / filename) as font:
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
                x = 214 - right * scale
            rectangle = [x + left * scale, baseline - top * scale, x + right * scale, baseline - bottom * scale]
            if min(rectangle) < 0 or rectangle[2] > 215 or rectangle[3] > 50:
                raise ValueError("Reference caption outline is clipped")
            groups.append(f'<path fill="#ffffff" transform="translate({x:.9f} {baseline}) scale({scale:.12f} {-scale:.12f})" d="{pen.getCommands()}"/>')
    svg = '<svg xmlns="http://www.w3.org/2000/svg" width="215" height="50" viewBox="0 0 215 50">' + "".join(groups) + "</svg>"
    rendered = cairosvg.svg2png(bytestring=svg.encode("utf-8"), output_width=430, output_height=100)

    return rgba(WHITE, Image.open(io.BytesIO(rendered)).getchannel("A"))


def immutable(path: Path, value: bytes, *, check: bool) -> bool:
    if path.is_symlink() or path.parent.is_symlink():
        raise ValueError("No symlink output: " + str(path))
    if path.exists():
        if path.read_bytes() != value:
            raise ValueError("Immutable output differs: " + str(path))
        return False
    if check:
        raise ValueError("Missing immutable output: " + str(path))
    with path.open("xb") as stream:
        stream.write(value)
    return True


def build(*, check: bool, font_dir: Path) -> dict:
    if PILLOW_VERSION != "12.2.0":
        raise ValueError("Pinned raster runtime requires Pillow 12.2.0")
    before = {str(p.relative_to(APP)): sha(p.read_bytes()) for p in (APP / "public/mail-assets").iterdir() if p.is_file()}
    for relative, expected in SOURCE_PINS.items():
        if sha((APP / relative).read_bytes()) != expected:
            raise ValueError("Native source changed: " + relative)
    contacts = module("scripts/build-contact-icons.py", "brand_v2_contact_source")
    hotline = module("scripts/build-outlook-hotline-assets.py", "brand_v2_hotline_source")
    caption = module("scripts/mail/build_dispatch_card_caption.py", "brand_v2_caption_source")
    images = {}
    for name, glyph in contacts.GLYPHS.items():
        old_name = f"contact-{name}.png"
        native = contacts.render(glyph)
        if native != (APP / "public/mail-assets" / old_name).read_bytes():
            raise ValueError("Contact geometry no longer reproduces original: " + name)
        alpha = Image.open(io.BytesIO(native)).getchannel("A")
        images[f"contact-{name}-brand-v2.png"] = (old_name, rgba(RED, alpha), RED)
    # Green is the exact native coverage channel: white ink has G=255 and
    # the pinned red matte has G=0. Replacing the RGB planes removes ALL red
    # matte, including antialiased edge pixels, without changing coverage.
    native_caption = caption.render(font_dir)
    images["outlook-dispo-card-caption-alpha-v2.png"] = (
        "outlook-dispo-card-caption-v1.png",
        rgba(WHITE, Image.open(io.BytesIO(native_caption)).getchannel("G")), WHITE,
    )
    images["outlook-dispo-card-caption-alpha-v3.png"] = (
        "outlook-dispo-card-caption-v1.png", reference_caption(font_dir), WHITE,
    )
    for kind in ("mail", "phone"):
        old_name = f"outlook-hotline-{kind}-v1.png"
        native = hotline.action(kind)
        old = Image.open(APP / "public/mail-assets" / old_name).convert("RGB")
        if ImageChops.difference(native, old).getbbox() is not None:
            raise ValueError("Feather vector no longer reproduces original: " + kind)
        images[f"outlook-hotline-{kind}-alpha-v2.png"] = (old_name, rgba(WHITE, native.getchannel("G")), WHITE)
    created = 0
    records = []
    for filename, (old_name, image, ink) in images.items():
        pixels = list(image.get_flattened_data())
        alpha = image.getchannel("A")
        if any(pixel[:3] != ink for pixel in pixels) or alpha.getextrema() != (0, 255):
            raise ValueError("Nonuniform foreground or missing genuine alpha: " + filename)
        if not any(0 < pixel[3] < 255 for pixel in pixels):
            raise ValueError("Antialiased coverage missing: " + filename)
        value = encode(image)
        digest = sha(value)
        if (digest, len(value)) != OUTPUT_PINS[filename]:
            raise ValueError("Native raster output differs from its frozen v2 pin: " + filename)
        output = "/m/" + digest[:10] + ".png"
        for directory in ("public/mail-assets", "resources/mail-templates/assets"):
            created += immutable(APP / directory / filename, value, check=check)
        created += immutable(APP / "public" / output.lstrip("/"), value, check=check)
        old_sha = sha((APP / "public/mail-assets" / old_name).read_bytes())
        records.append({"filename": filename, "oldFilename": old_name, "oldSha256": old_sha,
            "oldUrl": "/m/" + old_sha[:10] + ".png", "oldAssetUrl": "/mail-assets/" + old_name,
            "url": output, "assetUrl": "/mail-assets/" + filename, "sha256": digest, "bytes": len(value),
            "dimensions": list(image.size), "mime": "image/png", "pngSha256": digest,
            "foreground": "#" + "".join(f"{channel:02x}" for channel in ink), "alphaExtrema": [0, 255],
            "alphaBbox": alpha.getbbox(), "transparentPixels": sum(pixel[3] == 0 for pixel in pixels),
            "edgePixelsUseOnlyForegroundRGB": True, "opaquePixels": sum(pixel[3] == 255 for pixel in pixels),
            "antialiasedPixels": sum(0 < pixel[3] < 255 for pixel in pixels)})
    for filename, (expected, length) in SOCIAL_PINS.items():
        value = (APP / "resources/mail-templates/assets" / filename).read_bytes()
        if sha(value) != expected or len(value) != length:
            raise ValueError("Approved reference social PNG changed: " + filename)
        with Image.open(io.BytesIO(value)) as image:
            if image.size != (60, 60) or image.mode != "RGBA" or image.format != "PNG":
                raise ValueError("Reference social geometry or RGBA changed")
        output = "/m/" + expected[:10] + ".png"
        created += immutable(APP / "public/mail-assets" / filename, value, check=check)
        created += immutable(APP / "public" / output.lstrip("/"), value, check=check)
        kind = filename.removeprefix("outlook-social-").removesuffix("-v1.png")
        records.append({"filename": filename, "oldFilename": f"social-{kind}.png",
            "oldUrl": f"assets/social-{kind}.png", "oldAssetUrl": f"assets/social-{kind}.png",
            "referenceSha256": expected, "url": output, "assetUrl": "/mail-assets/" + filename,
            "sha256": expected, "bytes": length, "dimensions": [60, 60], "mime": "image/png",
            "pngSha256": expected, "referenceBytesExact": True, "referenceRGBAExact": True})
    for filename, (expected, length, reference_name) in REFERENCE_ACTION_PINS.items():
        value = (APP / "resources/mail-templates/assets" / filename).read_bytes()
        if sha(value) != expected or len(value) != length:
            raise ValueError("Approved reference action PNG changed: " + filename)
        with Image.open(io.BytesIO(value)) as image:
            if image.size != (72, 72) or image.mode != "RGBA" or image.format != "PNG":
                raise ValueError("Reference action geometry or RGBA changed")
            if any(pixel[:3] != WHITE for pixel in image.get_flattened_data() if pixel[3]):
                raise ValueError("Reference action visible RGB is not white")
        output = "/m/" + expected[:10] + ".png"
        created += immutable(APP / "public/mail-assets" / filename, value, check=check)
        created += immutable(APP / "public" / output.lstrip("/"), value, check=check)
        records.append({"filename": filename, "oldFilename": reference_name,
            "oldUrl": "assets/" + reference_name, "oldAssetUrl": "assets/" + reference_name,
            "referenceSha256": expected, "url": output, "assetUrl": "/mail-assets/" + filename,
            "sha256": expected, "bytes": length, "dimensions": [72, 72], "mime": "image/png",
            "pngSha256": expected, "referenceBytesExact": True, "referenceRGBAExact": True})
    for filename, (expected, length) in TRAIN_PINS.items():
        value = (APP / "public/mail-assets" / filename).read_bytes()
        if sha(value) != expected or len(value) != length:
            raise ValueError("Previously frozen train source changed: " + filename)
        with Image.open(io.BytesIO(value)) as image:
            if image.size != (695, 151):
                raise ValueError("Frozen train geometry changed")
        png_name = filename.removesuffix(".gif") + ".png" if filename.endswith(".gif") else filename
        png_sha = TRAIN_PINS[png_name][0]
        png = (APP / "public/mail-assets" / png_name).read_bytes()
        output = "/m/" + png_sha[:10] + ".png"
        created += immutable(APP / "public" / output.lstrip("/"), png, check=check)
        old_name = filename.replace("-v2-", "-v1-")
        old_sha = sha((APP / "public/mail-assets" / old_name).read_bytes())
        old_png_sha = sha((APP / "public/mail-assets" / old_name.replace(".gif", ".png")).read_bytes())
        records.append({"filename": filename, "oldFilename": old_name, "oldSha256": old_sha,
            "oldUrl": "/m/" + old_png_sha[:10] + ".png", "oldAssetUrl": "/mail-assets/" + old_name,
            "url": output, "assetUrl": "/mail-assets/" + filename, "sha256": expected, "bytes": length,
            "dimensions": [695, 151], "mime": "image/gif" if filename.endswith(".gif") else "image/png",
            "pngSha256": png_sha})
    for relative, expected in before.items():
        if sha((APP / relative).read_bytes()) != expected:
            raise ValueError("An existing public asset changed: " + relative)
    return {"schema": "outlook-uniform-brand-v2-assets-v1", "mode": "check" if check else "build",
        "red": "#e90032", "oldAssetsUnchanged": True, "nativeContactGeometryExact": True,
        "nativeFeatherGeometryExact": True, "nativeCaptionCoveragePreserved": True,
        "sourceAdditions": len(records), "targetAdditions": len({record["url"] for record in records}),
        "newFilesCreated": created, "sources": records}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--font-dir", type=Path, default=Path("C:/Windows/Fonts"))
    arguments = parser.parse_args()
    try:
        print(json.dumps(build(check=arguments.check, font_dir=arguments.font_dir), indent=2))
    except (OSError, ValueError) as error:
        parser.exit(1, "Uniform brand assets failed: " + str(error) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
