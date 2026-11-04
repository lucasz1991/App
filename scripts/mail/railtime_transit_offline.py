"""E2 local file runner, deliberately unusable as a Postfix pipe or SMTP daemon."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys

from railtime_transit import HtmlResult, MAX_MESSAGE_BYTES, PILOT, transform_message


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True, help="Must not already exist; the input is never overwritten")
    parser.add_argument("--projection", type=Path, required=True, help="Private local B1 send result, never derived from mail")
    parser.add_argument("--mode", choices=("html", "mime"), required=True)
    parser.add_argument("--template", help="Required for HTML fixtures; MIME always uses its actual header")
    parser.add_argument("--envelope-sender", default=PILOT)
    parser.add_argument("--php", default="php")
    parser.add_argument("--offline-trusted-fixture", action="store_true", required=True, help="Explicit local simulation; not connector authorization")
    args = parser.parse_args()
    try:
        if args.mode == "html" and not args.template:
            raise ValueError("html_header_missing")
        if args.output.exists() or args.input.resolve() == args.output.resolve():
            raise ValueError("output_exists")
        if args.input.stat().st_size > MAX_MESSAGE_BYTES or args.projection.stat().st_size > 2_000_000:
            raise ValueError("input_budget")
        original = args.input.read_bytes()
        projection = json.loads(args.projection.read_text(encoding="utf-8-sig"))
        if not isinstance(projection, dict):
            raise ValueError("projection_invalid")
        bridge = Path(__file__).with_name("railtime_transit_html.php")

        def transform(html: str, headers: dict) -> HtmlResult:
            request = {"html": html, "headers": headers, "projection": projection,
                       "offlineTrustedFixture": True, "envelopeSender": args.envelope_sender}
            completed = subprocess.run([args.php, str(bridge)], input=json.dumps(request).encode(),
                                       capture_output=True, timeout=15, check=True)
            value = json.loads(completed.stdout)
            if not isinstance(value, dict) or type(value.get("changed")) is not bool or not isinstance(value.get("html"), str):
                raise ValueError("bridge_contract")
            return HtmlResult(value["html"], value["changed"], value.get("reason", "transformer_unchanged"))

        if args.mode == "mime":
            result = transform_message(original, transform, trusted_ingress=True, envelope_sender=args.envelope_sender)
            output, changed, reason = result.message, result.changed, result.reason
        else:
            try:
                transformed = transform(original.decode("utf-8"), {"X-RailTime-Template": args.template})
                changed, reason = transformed.changed, transformed.reason
                output = transformed.html.encode("utf-8") if changed else original
            except Exception:
                output, changed, reason = original, False, "transformer_failed"
        # Exclusive creation closes the exists/check race; never overwrite files.
        with args.output.open("xb") as stream:
            stream.write(output)
        print(json.dumps({"mode": "offline_fixture_" + args.mode, "changed": changed, "reason": reason,
                          "inputBytes": len(original), "outputBytes": len(output),
                          "inputSha256": hashlib.sha256(original).hexdigest(),
                          "outputSha256": hashlib.sha256(output).hexdigest(), "mailflow": "inactive"}))
        return 0
    except Exception:
        print(json.dumps({"mode": "offline_fixture", "error": "offline_input_or_io_failed", "mailflow": "inactive"}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
