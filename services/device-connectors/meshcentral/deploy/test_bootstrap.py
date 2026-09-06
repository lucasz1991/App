"""Local, isolated fixtures only. Does not run Docker, services, network clients or accounts."""

import contextlib
import copy
import io
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch

import bootstrap as b

IMAGE = "node:22-bookworm-slim@sha256:" + "a" * 64


class BootstrapTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix="railtime-mesh-bootstrap-test-")
        self.addCleanup(self.scratch.cleanup)
        self.base = Path(self.scratch.name)
        self.source = self.base / "source"
        self.source.mkdir()
        for name in b.source_manifest():
            target = self.source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(b.SOURCE / name, target)
        self.root = self.base / "isolated-pilot"

    def save(self, path, value):
        path.write_text(json.dumps(value), encoding="utf-8")

    def init_fixture(self):
        # Windows runner cannot prove Linux ownership. Only emulate ownership calls;
        # permission enforcement is checked separately below and live remains a gate.
        with patch.object(b.sys, "platform", "linux"), \
                patch.object(b.os, "geteuid", return_value=0, create=True), \
                patch.object(b.os, "chown", create=True), \
                patch.object(b.os, "fchown", create=True), \
                patch.object(b, "ports_free"):
            return b.initialize(self.root, IMAGE, apply=True, source=self.source)

    def validate_fixture(self, connector=False):
        with patch.object(b.sys, "platform", "win32"):
            return b.validate(self.root, connector=connector, source=self.source)

    def test_current_source_pins_are_valid(self):
        self.assertIn("package-lock.json", b.source_manifest())

    def test_requires_digest_and_node22(self):
        for value in ("node:22-bookworm-slim", "node:24-bookworm-slim@sha256:" + "a" * 64, "latest"):
            with self.subTest(value=value), self.assertRaises(b.InvalidSetup):
                b.validate_image(value)
        b.validate_image(IMAGE)

    def test_dry_run_writes_nothing(self):
        with patch.object(b, "ports_free"):
            result = b.initialize(self.root, IMAGE, source=self.source)
        self.assertEqual("dry_run", result["status"])
        self.assertFalse(self.root.exists())

    def test_existing_directory_is_never_overwritten(self):
        self.root.mkdir()
        marker = self.root / "keep.txt"
        marker.write_text("preserve", encoding="utf-8")
        with self.assertRaises(b.InvalidSetup):
            b.initialize(self.root, IMAGE, source=self.source)
        self.assertEqual("preserve", marker.read_text(encoding="utf-8"))

    def test_absolute_scoped_path_required(self):
        for value in ("relative-pilot", str(self.base / "$SECRET"), str(self.base / ".." / "escape")):
            with self.subTest(value=value), self.assertRaises(b.InvalidSetup):
                b.target_path(value)

    def test_symlink_component_rejected(self):
        original = Path.is_symlink
        with patch.object(Path, "is_symlink", lambda path: path == self.base or original(path)):
            with self.assertRaises(b.InvalidSetup):
                b.target_path(self.root)

    def test_occupied_port_rejected(self):
        with patch.object(b.socket, "socket") as socket_factory:
            socket_factory.return_value.__enter__.return_value.bind.side_effect = OSError("private detail")
            with self.assertRaisesRegex(b.InvalidSetup, "occupied"):
                b.ports_free()

    def test_wrong_mesh_version_rejected(self):
        path = self.source / "package-lock.json"
        lock = b.read_json(path)
        lock["packages"]["node_modules/meshcentral"]["version"] = "1.2.4"
        self.save(path, lock)
        with self.assertRaises(b.InvalidSetup):
            b.source_manifest(self.source)

    def test_qs_override_and_lock_required(self):
        path = self.source / "package.json"
        package = b.read_json(path)
        package["overrides"]["qs"] = "6.15.0"
        self.save(path, package)
        with self.assertRaises(b.InvalidSetup):
            b.source_manifest(self.source)

    def test_nested_vulnerable_qs_rejected(self):
        path = self.source / "package-lock.json"
        lock = b.read_json(path)
        lock["packages"]["node_modules/test/node_modules/qs"] = {"version": "6.15.0"}
        self.save(path, lock)
        with self.assertRaises(b.InvalidSetup):
            b.source_manifest(self.source)

    def test_registry_and_integrity_required(self):
        path = self.source / "package-lock.json"
        lock = b.read_json(path)
        lock["packages"]["node_modules/meshcentral"]["resolved"] = "https://unreviewed.example/package.tgz"
        self.save(path, lock)
        with self.assertRaises(b.InvalidSetup):
            b.source_manifest(self.source)

    def test_malformed_manifest_and_lock_sections_fail_safely(self):
        package_path = self.source / "package.json"
        lock_path = self.source / "package-lock.json"
        package = b.read_json(package_path)
        lock = b.read_json(lock_path)
        for field in ("dependencies", "overrides"):
            for invalid in (None, []):
                changed = copy.deepcopy(package)
                changed[field] = invalid
                self.save(package_path, changed)
                with self.subTest(field=field, invalid=invalid), self.assertRaises(b.InvalidSetup):
                    b.source_manifest(self.source)
        self.save(package_path, package)
        for field in ("node_modules/qs", "", "node_modules/meshcentral"):
            for invalid in (None, []):
                changed = copy.deepcopy(lock)
                changed["packages"][field] = invalid
                self.save(lock_path, changed)
                with self.subTest(field=field, invalid=invalid), self.assertRaises(b.InvalidSetup):
                    b.source_manifest(self.source)

    def test_compose_is_hardened_and_connector_opt_in(self):
        compose = b.render_compose(self.root, IMAGE, self.source)
        self.assertNotIn("${", json.dumps(compose))
        for name, service in compose["services"].items():
            self.assertEqual("1000:1000", service["user"])
            self.assertTrue(service["read_only"])
            self.assertEqual(["ALL"], service["cap_drop"])
            self.assertEqual(["no-new-privileges:true"], service["security_opt"])
            self.assertNotIn("ports", service)
            self.assertNotIn("environment", service)
            self.assertEqual(IMAGE, service["build"]["args"]["NODE_IMAGE"])
            self.assertTrue(all(not volume["bind"]["create_host_path"] for volume in service["volumes"]))
        self.assertEqual(["connector"], compose["services"]["connector"]["profiles"])
        config_volume = compose["services"]["meshcentral"]["volumes"][1]
        self.assertTrue(config_volume["read_only"])

    def test_init_and_read_only_validation(self):
        result = self.init_fixture()
        self.assertFalse(result["secrets_created"])
        self.assertEqual([], list((self.root / "connector/secrets").iterdir()))
        before = {str(p): p.read_bytes() for p in self.root.rglob("*") if p.is_file()}
        self.assertEqual("validated", self.validate_fixture()["status"])
        after = {str(p): p.read_bytes() for p in self.root.rglob("*") if p.is_file()}
        self.assertEqual(before, after)

    def test_apply_is_linux_root_only(self):
        with patch.object(b.sys, "platform", "win32"), patch.object(b, "ports_free"):
            with self.assertRaises(b.InvalidSetup):
                b.initialize(self.root, IMAGE, apply=True, source=self.source)
        self.assertFalse(self.root.exists())

    def test_native_external_binding_and_proxy_unapproved(self):
        for key, value in (("portBind", "0.0.0.0"), ("port", 8443), ("tlsOffload", True), ("selfUpdate", True)):
            config = b.native_configuration()
            config["settings"][key] = value
            with self.subTest(key=key), self.assertRaises(b.InvalidSetup):
                b.validate_native(config)
        config = b.native_configuration()
        config["domains"][""]["certUrl"] = "https://127.0.0.1/"
        with self.assertRaises(b.InvalidSetup):
            b.validate_native(config)

    def test_source_changes_after_init_block_start(self):
        self.init_fixture()
        (self.source / "src/server.js").write_text("changed", encoding="utf-8")
        with self.assertRaises(b.InvalidSetup):
            self.validate_fixture()

    def test_compose_mutation_rejected(self):
        self.init_fixture()
        path = self.root / "compose.json"
        compose = b.read_json(path)
        compose["services"]["meshcentral"]["privileged"] = True
        self.save(path, compose)
        with self.assertRaises(b.InvalidSetup):
            self.validate_fixture()

    def test_connector_missing_secrets_fails_closed(self):
        self.init_fixture()
        with self.assertRaises(b.InvalidSetup):
            self.validate_fixture(connector=True)

    def test_synthetic_valid_secrets_are_never_returned(self):
        self.init_fixture()
        for name in b.SECRET_FILES:
            (self.root / "connector/secrets" / name).write_text("c" * (160 if name == "meshcentral-login-key" else 48), encoding="utf-8")
        result = self.validate_fixture(connector=True)
        self.assertTrue(result["connector_checked"])
        self.assertNotIn("cccc", json.dumps(result))

    def test_oversize_and_parser_errors_are_safe(self):
        path = self.base / "oversize.json"
        path.write_bytes(b"x" * 2048)
        with self.assertRaises(b.InvalidSetup):
            b.bounded_bytes(path, 1024)
        path.write_text('{"password": "SHOULD_NOT_BE_OUTPUT"', encoding="utf-8")
        with self.assertRaises(b.InvalidSetup) as caught:
            b.read_json(path)
        self.assertNotIn("SHOULD_NOT_BE_OUTPUT", str(caught.exception))

    def test_linux_permissions_fail_closed(self):
        path = self.base / "private"
        path.write_text("test", encoding="utf-8")
        info = copy.copy(path.stat())
        with patch.object(b.sys, "platform", "linux"), patch.object(Path, "stat", return_value=info):
            with self.assertRaises(b.InvalidSetup):
                b.private_path(path)

    def test_cli_error_has_no_raw_os_message(self):
        output = io.StringIO()
        with patch.object(b, "initialize", side_effect=OSError("HIDDEN-CREDENTIAL")), contextlib.redirect_stderr(output):
            code = b.main(["init", "--root", str(self.root), "--base-image", IMAGE])
        self.assertEqual(1, code)
        self.assertNotIn("HIDDEN-CREDENTIAL", output.getvalue())


if __name__ == "__main__":
    unittest.main()
