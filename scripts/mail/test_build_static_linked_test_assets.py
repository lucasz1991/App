"""Filesystem and format regression checks for the static linked PNG copier."""

from __future__ import annotations

from copy import deepcopy
import io
import os
from pathlib import Path
import struct
import tempfile
import unittest
from unittest.mock import patch
import zlib

from PIL import Image

import build_static_linked_test_assets as builder


class StaticLinkedAssetBuilderTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="railtime-static-png-")
        self.addCleanup(self.temporary.cleanup)
        self.app = Path(self.temporary.name)
        self.assets = self.app / "public" / "mail-assets"
        self.assets.mkdir(parents=True)
        self.png = self.image_bytes("PNG", "#e90032")
        self.other_png = self.image_bytes("PNG", "#142231")
        self.manifest = self.inventory({"first.png": self.png})

    @staticmethod
    def image_bytes(format: str, color: str) -> bytes:
        stream = io.BytesIO()
        Image.new("RGBA", (2, 3), color).save(stream, format=format)
        return stream.getvalue()

    @staticmethod
    def pin(name: str, content: bytes, *, mime: str = "image/png") -> dict:
        return {"source": name, "sha256": builder.digest(content), "bytes": len(content), "mime": mime, "dimensions": [2, 3]}

    def inventory(self, images: dict[str, bytes]) -> dict:
        manifest = {"profile": builder.PROFILE, "output_namespace": "/m/<first10SHA256>.png", "sources": [], "targets": {}}
        for name, content in images.items():
            (self.assets / name).write_bytes(content)
            pin = self.pin(name, content)
            output = "/m/" + pin["sha256"][:10] + ".png"
            manifest["sources"].append(pin | {"png": name, "png_sha256": pin["sha256"], "output": output})
            manifest["targets"][output] = pin
        return manifest

    def target(self, manifest: dict | None = None) -> Path:
        return self.app / "public" / next(iter((manifest or self.manifest)["targets"])).lstrip("/")

    def test_exact_png_copy_check_and_replay_preserve_originals_and_mtimes(self) -> None:
        original = self.assets / "first.png"
        original_mtime = original.stat().st_mtime_ns
        report = builder.build_assets(self.app, self.manifest)
        target = self.target()
        self.assertEqual(target.read_bytes(), self.png)
        self.assertEqual(report["created"], 1)
        self.assertEqual(report["png_bytes"], len(self.png))
        target_mtime = target.stat().st_mtime_ns
        with patch.object(builder.os, "open", side_effect=AssertionError("check must not open a writable file")):
            check = builder.build_assets(self.app, self.manifest, check=True)
        replay = builder.build_assets(self.app, self.manifest)
        self.assertEqual(check["created"], 0)
        self.assertEqual(replay["created"], 0)
        self.assertEqual(target.stat().st_mtime_ns, target_mtime)
        self.assertEqual(original.stat().st_mtime_ns, original_mtime)
        self.assertEqual(original.read_bytes(), self.png)

    def test_missing_check_creates_no_directory(self) -> None:
        with self.assertRaisesRegex(ValueError, "Missing static linked outputs"):
            builder.build_assets(self.app, self.manifest, check=True)
        self.assertFalse((self.app / "public" / "m").exists())

    def test_original_hash_change_fails_before_any_output_write(self) -> None:
        (self.assets / "first.png").write_bytes(self.other_png)
        with self.assertRaisesRegex(ValueError, "full SHA256 changed"):
            builder.build_assets(self.app, self.manifest)
        self.assertFalse((self.app / "public" / "m").exists())

    def test_conflicting_existing_output_is_not_overwritten_and_missing_output_stays_missing(self) -> None:
        manifest = self.inventory({"first.png": self.png, "second.png": self.other_png})
        destination = self.app / "public" / "m"
        destination.mkdir()
        target = self.target(manifest)
        target.write_bytes(b"foreign existing file")
        with self.assertRaisesRegex(ValueError, "asset bytes or full SHA256 changed"):
            builder.build_assets(self.app, manifest)
        self.assertEqual(target.read_bytes(), b"foreign existing file")
        self.assertEqual(len(list(destination.iterdir())), 1)

    def test_animated_gif_is_validated_but_only_curated_png_is_copied(self) -> None:
        stream = io.BytesIO()
        first = Image.new("RGB", (2, 3), "red")
        second = Image.new("RGB", (2, 3), "blue")
        first.save(stream, format="GIF", save_all=True, append_images=[second], duration=100)
        content = stream.getvalue()
        (self.assets / "first.gif").write_bytes(content)
        png_source = self.manifest["sources"][0]
        self.manifest["sources"].append(self.pin("first.gif", content, mime="image/gif") | {key: png_source[key] for key in ("png", "png_sha256", "output")})
        report = builder.build_assets(self.app, self.manifest)
        self.assertEqual(report["source_assets_verified"], 2)
        self.assertEqual(report["png_targets_verified"], 1)
        self.assertEqual(self.target().read_bytes(), self.png)
        self.assertEqual(list((self.app / "public" / "m").glob("*.gif")), [])

    def test_apng_chunk_is_refused_even_when_all_byte_pins_match(self) -> None:
        payload = struct.pack(">II", 1, 0)
        chunk = struct.pack(">I", len(payload)) + b"acTL" + payload + struct.pack(">I", zlib.crc32(b"acTL" + payload) & 0xFFFFFFFF)
        content = self.png[:33] + chunk + self.png[33:]
        manifest = self.inventory({"animation.png": content})
        with self.assertRaisesRegex(ValueError, "APNG animation chunks"):
            builder.build_assets(self.app, manifest)
        self.assertFalse((self.app / "public" / "m").exists())

    def test_corrupt_png_crc_is_refused_even_with_matching_sha_pin(self) -> None:
        content = self.png[:29] + bytes([self.png[29] ^ 1]) + self.png[30:]
        manifest = self.inventory({"corrupt.png": content})
        with self.assertRaisesRegex(ValueError, "CRC mismatch"):
            builder.build_assets(self.app, manifest)

    def test_prefix_collision_is_refused_before_filesystem_access(self) -> None:
        manifest = self.inventory({"first.png": self.png, "second.png": self.other_png})
        targets = list(manifest["targets"].values())
        targets[0]["sha256"] = "a" * 10 + "1" * 54
        targets[1]["sha256"] = "a" * 10 + "2" * 54
        with self.assertRaisesRegex(ValueError, "prefix collision"):
            builder.build_assets(self.app, manifest)
        self.assertFalse((self.app / "public" / "m").exists())

    def test_escaped_source_filename_is_refused(self) -> None:
        manifest = deepcopy(self.manifest)
        manifest["sources"][0]["source"] = "../first.png"
        with self.assertRaisesRegex(ValueError, "flat namespace"):
            builder.build_assets(self.app, manifest)

    def test_png_dimensions_and_mime_are_decoded_not_trusted_from_manifest(self) -> None:
        pin = self.pin("first.png", self.png)
        with self.assertRaisesRegex(ValueError, "IHDR dimensions"):
            builder.validate_image(self.png, pin | {"dimensions": [3, 2]})
        with self.assertRaisesRegex(ValueError, "Decoded asset MIME"):
            builder.validate_image(self.png, pin | {"mime": "image/gif"})

    def test_trailing_data_is_refused_even_with_matching_sha_pin(self) -> None:
        content = self.png + b"unexpected suffix"
        with self.assertRaisesRegex(ValueError, "trailing bytes"):
            builder.validate_image(content, self.pin("first.png", content))

    def test_source_symlink_is_refused(self) -> None:
        original = self.assets / "first.png"
        alternate = self.app / "original.png"
        original.rename(alternate)
        try:
            os.symlink(alternate, original)
        except OSError as error:
            self.skipTest("OS does not permit symlink creation: " + str(error))
        with self.assertRaisesRegex(ValueError, "Symlink or junction"):
            builder.build_assets(self.app, self.manifest)

    def test_output_parent_symlink_is_refused_without_writing_link_target(self) -> None:
        elsewhere = self.app / "elsewhere"
        elsewhere.mkdir()
        try:
            os.symlink(elsewhere, self.app / "public" / "m", target_is_directory=True)
        except OSError as error:
            self.skipTest("OS does not permit symlink creation: " + str(error))
        with self.assertRaisesRegex(ValueError, "Symlink or junction"):
            builder.build_assets(self.app, self.manifest)
        self.assertEqual(list(elsewhere.iterdir()), [])

    def test_windows_junction_detection_is_fail_closed(self) -> None:
        with patch.object(builder.Path, "is_junction", return_value=True):
            with self.assertRaisesRegex(ValueError, "Symlink or junction"):
                builder.build_assets(self.app, self.manifest)
        self.assertFalse((self.app / "public" / "m").exists())

    def test_application_ancestor_symlink_is_refused(self) -> None:
        link = self.app / "redirect"
        try:
            os.symlink(self.app / "public", link, target_is_directory=True)
        except OSError as error:
            self.skipTest("OS does not permit symlink creation: " + str(error))
        with self.assertRaisesRegex(ValueError, "Symlink or junction"):
            builder.secure_path(link / "mail-assets", "first.png")


if __name__ == "__main__":
    unittest.main()
