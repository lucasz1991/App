#!/usr/bin/env python3
"""Provision one private Speech Service token as the target PHP user.

The helper deliberately prints only the SHA-256 digest that belongs in the
daemon configuration.  The clear-text token is written atomically to a private
directory inside the application's Plesk webspace.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import secrets
import stat
import sys
import tempfile
from pathlib import Path
from typing import Callable, Optional


class ProvisioningError(ValueError):
    """Raised when the target cannot be provisioned safely."""


def token_sha256(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def validate_private_parent(parent: Path, expected_uid: int) -> None:
    """Require a real, private directory owned by the invoking PHP user."""

    try:
        details = parent.lstat()
    except OSError as exc:
        raise ProvisioningError("the token parent directory does not exist") from exc

    if stat.S_ISLNK(details.st_mode) or not stat.S_ISDIR(details.st_mode):
        raise ProvisioningError("the token parent must be a real directory")
    if details.st_uid != expected_uid:
        raise ProvisioningError("the token parent must belong to the invoking PHP user")
    if details.st_mode & (stat.S_IRWXG | stat.S_IRWXO):
        raise ProvisioningError("the token parent must have mode 0700")


def provision_token(
    output: Path,
    *,
    expected_uid: Optional[int] = None,
    enforce_private_parent: bool = True,
    token_factory: Callable[[int], str] = secrets.token_urlsafe,
) -> str:
    """Atomically replace ``output`` and return only its SHA-256 digest."""

    output = Path(output)
    if not output.is_absolute() or not output.name:
        raise ProvisioningError("the token output path must be absolute")

    parent = output.parent
    if enforce_private_parent:
        if os.name != "posix" or not hasattr(os, "geteuid"):
            raise ProvisioningError("private ownership checks require a POSIX host")
        uid = os.geteuid() if expected_uid is None else expected_uid
        validate_private_parent(parent, uid)

    token = token_factory(48)
    if not isinstance(token, str) or len(token) < 48 or any(character.isspace() for character in token):
        raise ProvisioningError("the token generator returned an unsafe value")

    descriptor = -1
    temporary_name = ""
    try:
        descriptor, temporary_name = tempfile.mkstemp(
            prefix=".%s." % output.name,
            suffix=".tmp",
            dir=str(parent),
        )
        if hasattr(os, "fchmod"):
            os.fchmod(descriptor, 0o600)
        with os.fdopen(descriptor, "wb", closefd=True) as stream:
            descriptor = -1
            stream.write(token.encode("utf-8"))
            stream.flush()
            os.fsync(stream.fileno())

        os.replace(temporary_name, output)
        temporary_name = ""
        if os.name == "posix":
            directory_flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
            directory_descriptor = os.open(parent, directory_flags)
            try:
                os.fsync(directory_descriptor)
            finally:
                os.close(directory_descriptor)
    except OSError as exc:
        raise ProvisioningError("the token file could not be written atomically") from exc
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if temporary_name:
            try:
                os.unlink(temporary_name)
            except FileNotFoundError:
                pass

    return token_sha256(token)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Create or rotate one private LMZ Speech Service client token"
    )
    parser.add_argument("--output", required=True, type=Path, help="absolute token file path")
    args = parser.parse_args()

    try:
        if os.name != "posix" or not hasattr(os, "geteuid"):
            raise ProvisioningError("this deployment helper requires Linux or another POSIX host")
        if os.geteuid() == 0:
            raise ProvisioningError("run this helper as the target Plesk PHP user, not as root")
        digest = provision_token(args.output, expected_uid=os.geteuid())
    except ProvisioningError as exc:
        print("Provisioning error: %s" % exc, file=sys.stderr)
        return 2

    print("token_sha256=%s" % digest)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
