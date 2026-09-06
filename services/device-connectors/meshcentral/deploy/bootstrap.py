#!/usr/bin/env python3
"""Loopback-only preparation. Never launches Docker or creates an admin account."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys

SOURCE = Path(__file__).absolute().parent.parent
DOMAIN = "support.app.rail-time.de"
NATIVE_PORT = 8444
CONNECTOR_PORT = 9442
UID = GID = 1000
PRIVATE_DIRS = (
    "native", "native/data", "native/files", "native/backups", "connector",
    "connector/secrets", "connector/state", "connector/tmp",
)
SECRET_FILES = ("railtime-bearer-token", "railtime-hmac-secret", "meshcentral-login-key")


class InvalidSetup(Exception):
    pass


def no_symlinks(path):
    """Check lexical ancestors before resolution, including a dangling symlink."""
    path = Path(path).absolute()
    if any(character in str(path) for character in "$\r\n\0") or ".." in path.parts:
        raise InvalidSetup("Deployment paths must not contain interpolation or traversal characters.")
    for item in reversed((path, *path.parents)):
        if item.is_symlink():
            raise InvalidSetup("Symlinks are not allowed in deployment paths.")
    return path


def regular(path):
    path = no_symlinks(path)
    if not path.is_file():
        raise InvalidSetup("A required regular deployment file is missing.")
    return path


def bounded_bytes(path, maximum):
    path = regular(path)
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    with os.fdopen(os.open(path, flags), "rb") as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > maximum:
            raise InvalidSetup("A deployment file exceeds its size limit or is not regular.")
        content = handle.read(maximum + 1)
        if len(content) > maximum:
            raise InvalidSetup("A deployment file exceeds its size limit.")
        return content


def read_json(path):
    try:
        parsed = json.loads(bounded_bytes(path, 2_000_000))
        if not isinstance(parsed, dict):
            raise InvalidSetup("A deployment JSON file must contain an object.")
        return parsed
    except (ValueError, UnicodeError):
        # Parser exceptions may contain a line with a secret. Never forward them.
        raise InvalidSetup("A deployment JSON file is invalid.") from None


def validate_image(image):
    if not re.fullmatch(r"node:22(?:\.[0-9]+){0,2}-bookworm-slim@sha256:[a-f0-9]{64}", image):
        raise InvalidSetup("Use an independently verified node:22-bookworm-slim image digest, not a mutable tag.")


def source_manifest(source=SOURCE):
    source = no_symlinks(source)
    package = read_json(source / "package.json")
    lock = read_json(source / "package-lock.json")
    dependencies = package.get("dependencies")
    overrides = package.get("overrides")
    if not isinstance(dependencies, dict) or dependencies.get("meshcentral") != "1.2.5":
        raise InvalidSetup("The source package must pin MeshCentral exactly to 1.2.5.")
    packages = lock.get("packages", {})
    if (not isinstance(packages, dict) or not isinstance(overrides, dict)
            or overrides.get("qs") != "6.16.0"):
        raise InvalidSetup("The reviewed qs 6.16.0 security override is required.")
    if any(not isinstance(entry, dict) for entry in packages.values()):
        raise InvalidSetup("Every npm lock entry must contain an object.")
    qs_entries = [entry for name, entry in packages.items() if name.endswith("node_modules/qs")]
    if not qs_entries or any(entry.get("version") != "6.16.0" for entry in qs_entries):
        raise InvalidSetup("Every locked qs installation must be version 6.16.0.")
    for name, entry in packages.items():
        if name and (not isinstance(entry, dict)
                     or not str(entry.get("resolved", "")).startswith("https://registry.npmjs.org/")
                     or not str(entry.get("integrity", "")).startswith("sha512-")):
            raise InvalidSetup("Every npm dependency must have registry provenance and SHA-512 integrity.")
    mesh = packages.get("node_modules/meshcentral", {})
    root_dependencies = packages.get("", {}).get("dependencies")
    if (lock.get("lockfileVersion") != 3 or mesh.get("version") != "1.2.5"
            or not isinstance(root_dependencies, dict)
            or root_dependencies.get("meshcentral") != "1.2.5"
            or not str(mesh.get("integrity", "")).startswith("sha512-")):
        raise InvalidSetup("The npm lock does not prove the required MeshCentral version/integrity.")
    names = ["package.json", "package-lock.json", "config.example.json"]
    names += [str(p.relative_to(source)).replace("\\", "/") for p in (source / "src").glob("*.js")]
    names += ["deploy/" + name for name in (
        "bootstrap.py", "compose.template.json", "Dockerfile.native", "Dockerfile.connector",
        "Dockerfile.native.dockerignore", "Dockerfile.connector.dockerignore",
    )]
    if not (source / "src/server.js").is_file():
        raise InvalidSetup("Connector source is incomplete.")
    return {name: hashlib.sha256(regular(source / name).read_bytes()).hexdigest() for name in sorted(names)}


def target_path(value, new=False):
    if not Path(value).is_absolute():
        raise InvalidSetup("An explicit absolute deployment directory is required.")
    path = no_symlinks(value)
    # No workspace/home/filesystem root, Windows drive, or vague /srv target.
    if len(path.parts) < 3 or path == Path.home() or path.name in ("App", "RailTime"):
        raise InvalidSetup("Choose a dedicated deployment directory, not a broad root.")
    if not path.parent.is_dir():
        raise InvalidSetup("The explicit deployment parent directory must already exist.")
    if new and path.exists():
        raise InvalidSetup("The deployment target already exists; nothing will be overwritten.")
    return path


def ports_free(connector=False):
    for port in (NATIVE_PORT, CONNECTOR_PORT) if connector else (NATIVE_PORT,):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            try:
                # No SO_REUSEADDR: existing listeners must cause an abort.
                listener.bind(("127.0.0.1", port))
            except OSError:
                raise InvalidSetup(f"Required loopback port {port} is occupied or unavailable.") from None


def render_compose(root, image, source=SOURCE):
    validate_image(image)
    template = read_json(source / "deploy/compose.template.json")

    def replace(value):
        if isinstance(value, dict):
            return {key: replace(item) for key, item in value.items()}
        if isinstance(value, list):
            return [replace(item) for item in value]
        if isinstance(value, str):
            return (value.replace("__SOURCE__", str(source)).replace("__ROOT__", str(root))
                    .replace("__NODE_IMAGE__", image))
        return value

    return replace(template)


def native_configuration():
    return {
        "settings": {
            "cert": DOMAIN, "port": NATIVE_PORT, "portBind": "127.0.0.1", "aliasPort": 443,
            "exactPorts": True, "WANonly": True, "tlsOffload": False, "redirPort": 0,
            "mpsPort": 0, "selfUpdate": False, "plugins": False, "webRTC": False, "agentPong": 300,
            "userAllowedIP": "127.0.0.1,::1", "lockAgentDownload": True,
        },
        "domains": {"": {"title": "RailTime Support Pilot", "newAccounts": False}},
    }


def connector_configuration(source=SOURCE):
    config = read_json(source / "config.example.json")
    config["meshcentral"]["url"] = f"wss://127.0.0.1:{NATIVE_PORT}"
    config["meshcentral"]["platforms"] = ["windows"]
    config["meshcentral"]["remote_support"] = False
    config["meshcentral"]["unattended_remote_support"] = False
    config["commands"]["execute_script"] = False
    config["artifacts"]["allowed_hosts"] = ["app.rail-time.de"]
    return config


def exclusive_json(path, value, container_owner=False):
    # O_EXCL/O_NOFOLLOW and a private newly created parent avoid replacement.
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(path, flags, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(value, handle, indent=2)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
        if container_owner:
            os.fchown(handle.fileno(), UID, GID)


def initialize(root, image, apply=False, source=SOURCE):
    root = target_path(root, new=True)
    validate_image(image)
    manifest = source_manifest(source)
    ports_free(connector=True)
    if not apply:
        return {"status": "dry_run", "writes": False, "services_started": False,
                "ports": [NATIVE_PORT, CONNECTOR_PORT], "connector_enabled": False}
    if sys.platform != "linux" or os.geteuid() != 0:
        raise InvalidSetup("Applying initialization requires Linux and root for UID/GID-safe mounts.")
    # The parent is checked again just before the sole target creation. No recursive cleanup on failure.
    target_path(root, new=True)
    root.mkdir(mode=0o700)
    for relative in PRIVATE_DIRS:
        directory = root / relative
        directory.mkdir(mode=0o700)
        os.chown(directory, UID, GID)
    exclusive_json(root / "native/config.json", native_configuration(), container_owner=True)
    exclusive_json(root / "connector/config.json", connector_configuration(source), container_owner=True)
    exclusive_json(root / "compose.json", render_compose(root, image, source))
    exclusive_json(root / "bootstrap-manifest.json", {
        "version": 1, "source": str(source), "node_image": image, "files": manifest,
    })
    return {"status": "initialized", "writes": True, "services_started": False,
            "connector_enabled": False, "accounts_created": False, "secrets_created": False}


def private_path(path, directory=False, container_owner=True):
    path = no_symlinks(path)
    if (directory and not path.is_dir()) or (not directory and not path.is_file()):
        raise InvalidSetup("A private deployment path is missing or has the wrong type.")
    if sys.platform == "linux":
        info = path.stat()
        if stat.S_IMODE(info.st_mode) & 0o077:
            raise InvalidSetup("A private deployment path grants group/other access.")
        if container_owner and (info.st_uid, info.st_gid) != (UID, GID):
            raise InvalidSetup("A container mount must belong to UID/GID 1000:1000.")


def validate_native(config):
    expected = native_configuration()
    if set(config) != {"settings", "domains"} or set(config.get("domains", {})) != {""}:
        raise InvalidSetup("Native bootstrap permits only the reviewed default domain and settings.")
    settings = config.get("settings", {})
    if set(settings) != set(expected["settings"]):
        raise InvalidSetup("Native settings differ from the reviewed loopback-only bootstrap.")
    if settings != expected["settings"] or config["domains"] != expected["domains"]:
        raise InvalidSetup("Native bootstrap configuration changed; review the next deployment stage separately.")


def validate_connector(root, source):
    config = read_json(root / "connector/config.json")
    # Bootstrap is intentionally narrower than the application's full provider setup.
    if config != connector_configuration(source):
        raise InvalidSetup("Connector bootstrap settings changed; review feature activation separately.")
    for name in SECRET_FILES:
        path = root / "connector/secrets" / name
        private_path(path)
        try:
            value = bounded_bytes(path, 1024).decode("utf-8").strip()
        except UnicodeError:
            raise InvalidSetup("A connector secret file is invalid.") from None
        if name == "meshcentral-login-key":
            valid = re.fullmatch(r"[A-Fa-f0-9]{160}", value) is not None
        else:
            valid = 32 <= len(value) <= 512 and not any(c in value for c in "\r\n\0")
        if not valid:
            raise InvalidSetup("A connector secret file has an invalid format.")


def validate(root, connector=False, before_start=False, source=SOURCE):
    root = target_path(root)
    private_path(root, directory=True, container_owner=False)
    for relative in PRIVATE_DIRS:
        private_path(root / relative, directory=True)
    for relative in ("native/config.json", "connector/config.json"):
        private_path(root / relative)
    for relative in ("compose.json", "bootstrap-manifest.json"):
        private_path(root / relative, container_owner=False)
    saved = read_json(root / "bootstrap-manifest.json")
    if (saved.get("version") != 1 or saved.get("source") != str(source)
            or saved.get("files") != source_manifest(source)):
        raise InvalidSetup("The reviewed source/lock changed; do not start this prepared release.")
    image = saved.get("node_image", "")
    validate_image(image)
    if read_json(root / "compose.json") != render_compose(root, image, source):
        raise InvalidSetup("Compose changed from the reviewed, loopback-only bootstrap.")
    validate_native(read_json(root / "native/config.json"))
    if connector:
        validate_connector(root, source)
    if before_start:
        # Connector is started only after native: do not reject the intended native listener.
        if connector:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
                try:
                    listener.bind(("127.0.0.1", CONNECTOR_PORT))
                except OSError:
                    raise InvalidSetup("Connector loopback port is occupied or unavailable.") from None
        else:
            ports_free()
    return {"status": "validated", "writes": False, "connector_checked": connector,
            "before_start": before_start, "native_version": "1.2.5",
            "live_health_verified": False, "production_approved": False}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="action", required=True)
    init = sub.add_parser("init", help="Dry-run by default; --apply creates a new private deployment only")
    init.add_argument("--root", required=True)
    init.add_argument("--base-image", required=True)
    init.add_argument("--apply", action="store_true")
    check = sub.add_parser("validate", help="Read-only validation; does not contact or start Docker")
    check.add_argument("--root", required=True)
    check.add_argument("--connector", action="store_true")
    check.add_argument("--before-start", action="store_true")
    args = parser.parse_args(argv)
    try:
        if args.action == "init":
            result = initialize(args.root, args.base_image, args.apply)
        else:
            result = validate(args.root, args.connector, args.before_start)
        print(json.dumps(result))
        return 0
    except (InvalidSetup, OSError, KeyError, TypeError, AttributeError):
        # File paths/parser/OS error messages could contain sensitive administrator input.
        # Only our fixed InvalidSetup messages are safe, all other exceptions are generic.
        error = sys.exception()
        message = str(error) if isinstance(error, InvalidSetup) else "Deployment validation failed; check private paths and permissions."
        print(json.dumps({"status": "blocked", "message": message}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
