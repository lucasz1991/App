"""Inactive E2 MIME adapter: explicit caller trust, no SMTP, network or mailbox I/O.

Only one unambiguous UTF-8/ASCII HTML body is replaced. All other MIME bytes
stay original; quoted HTML bytes are the HTML transformer's responsibility.
Transfer encoding may change within that one body. TNEF needs a separately
proved ingress/conversion contract and is deliberately not decoded here.
"""

from __future__ import annotations

import base64
import binascii
from dataclasses import dataclass
from email import policy
from email.parser import BytesHeaderParser
import quopri
import re
from typing import Callable

MAX_MESSAGE_BYTES = 10_000_000
MAX_HTML_BYTES = 1_000_000
PILOT = "it-technik@rail-time.de"
TEMPLATE = re.compile(r"u2e-[0-9a-f]{24}\Z")
PROTECTED = {
    "multipart/signed", "multipart/encrypted", "application/pkcs7-mime",
    "application/x-pkcs7-mime", "application/pkcs7-signature",
    "application/x-pkcs7-signature", "application/pgp-encrypted",
    "application/pgp-signature",
}


@dataclass(frozen=True)
class Result:
    message: bytes
    changed: bool
    reason: str
    # Original offsets of the only replaced encoded payload, never mail text.
    replaced_span: tuple[int, int] | None = None


@dataclass(frozen=True)
class HtmlResult:
    html: str
    changed: bool
    reason: str


class Unsupported(ValueError):
    pass


@dataclass
class Part:
    start: int
    body: int
    end: int
    headers: object


def _headers(data: bytes, start: int, end: int) -> Part:
    split = data.find(b"\r\n\r\n", start, min(end, start + 65_536))
    if split < 0:
        raise Unsupported("mime_headers_invalid")
    raw = data[start:split + 2]
    # This offline adapter intentionally accepts SMTP CRLF only.
    if re.search(rb"(?<!\r)\n|\r(?!\n)|\x00", raw):
        raise Unsupported("mime_headers_invalid")
    header = BytesHeaderParser(policy=policy.default).parsebytes(raw + b"\r\n")
    if header.defects or any(getattr(value, "defects", ()) for value in header.values()):
        raise Unsupported("mime_headers_invalid")
    for key in ("Content-Type", "Content-Transfer-Encoding", "Content-Disposition", "Content-ID", "MIME-Version"):
        if len(header.get_all(key, [])) > 1:
            raise Unsupported("mime_headers_ambiguous")
    return Part(start, split + 4, end, header)


def _walk(data: bytes, part: Part, state: dict, depth: int = 0, attached: bool = False, active_body: bool = True) -> None:
    state["parts"] += 1
    if depth > 8 or state["parts"] > 100:
        raise Unsupported("mime_budget")
    header = part.headers
    media = header.get_content_type()
    if media in PROTECTED:
        raise Unsupported("protected_message")
    if media in {"application/ms-tnef", "application/vnd.ms-tnef"} or (header.get_filename() or "").lower() == "winmail.dat":
        raise Unsupported("tnef_requires_ingress_proof")
    attached = attached or header.get_content_disposition() == "attachment" or header.get_filename() is not None
    encoding = str(header.get("Content-Transfer-Encoding", "7bit")).strip().lower()
    if media.startswith("multipart/"):
        if media not in {"multipart/mixed", "multipart/alternative", "multipart/related"}:
            raise Unsupported("multipart_unsupported")
        if encoding not in {"7bit", "8bit", "binary"}:
            raise Unsupported("multipart_encoding_invalid")
        boundary = header.get_boundary()
        if not boundary or not re.fullmatch(r"[0-9A-Za-z'()+_,./:=? -]{1,70}", boundary) or boundary.endswith(" "):
            raise Unsupported("mime_boundary_invalid")
        if boundary in state["boundaries"]:
            raise Unsupported("mime_boundary_ambiguous")
        state["boundaries"].add(boundary)
        pattern = re.compile(rb"(?m)^--" + re.escape(boundary.encode("ascii")) + rb"(--)?[ \t]*\r\n")
        marks = []
        for mark in pattern.finditer(data, part.body, part.end):
            marks.append(mark)
            if len(marks) > 101:
                raise Unsupported("mime_budget")
        if len(marks) < 2 or marks[0].group(1) or not marks[-1].group(1) or any(m.group(1) for m in marks[:-1]):
            raise Unsupported("mime_boundary_invalid")
        children = []
        for before, after in zip(marks, marks[1:]):
            child_end = after.start()
            if data[child_end - 2:child_end] != b"\r\n":
                raise Unsupported("mime_boundary_invalid")
            # RFC 2046: the CRLF immediately before a delimiter belongs to it.
            children.append(_headers(data, before.end(), child_end - 2))
        root_index = None
        if media == "multipart/related":
            start = header.get_param("start")
            ids = [str(child.headers.get("Content-ID", "")).strip() for child in children]
            present_ids = [value for value in ids if value]
            if len(set(present_ids)) != len(present_ids):
                raise Unsupported("related_root_ambiguous")
            if start is None:
                root_index = 0
            elif not isinstance(start, str) or not re.fullmatch(r"<[^<>\s]+>", start) or ids.count(start) != 1:
                raise Unsupported("related_root_invalid")
            else:
                root_index = ids.index(start)
            declared_type = header.get_param("type")
            if declared_type is not None and (not isinstance(declared_type, str) or declared_type.lower() != children[root_index].headers.get_content_type()):
                raise Unsupported("related_type_mismatch")
        for index, child in enumerate(children):
            _walk(data, child, state, depth + 1, attached, active_body and (root_index is None or root_index == index))
    elif media == "text/html" and not attached and active_body:
        state["html"].append(part)
    # Attached message/rfc822 and all other leaves remain opaque original bytes.


def _decode(data: bytes, part: Part) -> tuple[bytes, str, str]:
    payload = data[part.body:part.end]
    encoding = str(part.headers.get("Content-Transfer-Encoding", "7bit")).strip().lower()
    charset = (part.headers.get_content_charset() or "us-ascii").lower()
    if charset not in {"utf-8", "utf8", "us-ascii", "ascii"}:
        raise Unsupported("charset_unsupported")
    try:
        if encoding == "base64":
            compact = re.sub(rb"[ \t\r\n]", b"", payload)
            decoded = base64.b64decode(compact, validate=True)
            if base64.b64encode(decoded) != compact:
                raise Unsupported("transfer_encoding_invalid")
        elif encoding == "quoted-printable":
            if re.search(rb"=(?![0-9a-fA-F]{2}|\r\n)", payload) or any(byte > 127 for byte in payload):
                raise Unsupported("transfer_encoding_invalid")
            decoded = quopri.decodestring(payload)
        elif encoding in {"7bit", "8bit"}:
            decoded = payload
            if encoding == "7bit" and any(byte > 127 for byte in decoded):
                raise Unsupported("transfer_encoding_invalid")
        else:
            raise Unsupported("transfer_encoding_unsupported")
        decoded.decode(charset, errors="strict")
    except (ValueError, UnicodeError, binascii.Error) as exc:
        if isinstance(exc, Unsupported):
            raise
        raise Unsupported("transfer_encoding_invalid") from exc
    if not decoded or len(decoded) > MAX_HTML_BYTES or b"\x00" in decoded:
        raise Unsupported("html_budget")
    return decoded, encoding, charset


def _encode(html: str, encoding: str, charset: str) -> bytes:
    try:
        data = html.encode(charset, errors="strict")
    except UnicodeError as exc:
        raise Unsupported("output_charset_invalid") from exc
    if not data or len(data) > MAX_HTML_BYTES or b"\x00" in data:
        raise Unsupported("html_budget")
    if encoding == "base64":
        encoded = base64.b64encode(data)
        return b"\r\n".join(encoded[i:i + 76] for i in range(0, len(encoded), 76))
    if encoding == "quoted-printable":
        # Encode line endings as data too: HTML may contain intentional mixed
        # line endings in its opaque quote. Never normalize those decoded bytes.
        lines, line = [], bytearray()
        for byte in data:
            token = bytes([byte]) if 33 <= byte <= 126 and byte != 61 else f"={byte:02X}".encode()
            if len(line) + len(token) > 75:
                lines.append(bytes(line) + b"=")
                line.clear()
            line.extend(token)
        return b"\r\n".join(lines + [bytes(line)])
    if encoding == "7bit" and any(byte > 127 for byte in data):
        raise Unsupported("output_encoding_invalid")
    # Preserve the existing transfer-encoding header; do not invent SMTPUTF8 or
    # 8BITMIME permissions. Raw MIME output must still meet SMTP line limits.
    if re.search(rb"(?<!\r)\n|\r(?!\n)", data) or any(len(line) > 998 for line in data.split(b"\r\n")):
        raise Unsupported("output_encoding_invalid")
    return data


def transform_message(
    message: bytes,
    transform_html: Callable[[str, dict[str, str]], HtmlResult],
    *,
    trusted_ingress: bool = False,
    envelope_sender: str = "",
) -> Result:
    """No trust is derived from From, Received, X headers or a source IP.

    A production caller must prove connector TLS/tenant/envelope and resolve the
    published per-user B1 projection. No production caller is installed by E2.
    Callback failure and every unsupported format return the entire original.
    """
    def unchanged(reason: str) -> Result:
        return Result(message, False, reason)

    if not trusted_ingress:
        return unchanged("untrusted_ingress")
    if envelope_sender != PILOT:
        return unchanged("outside_pilot")
    if not message or len(message) > MAX_MESSAGE_BYTES:
        return unchanged("mime_budget")
    try:
        root = _headers(message, 0, len(message))
        values = root.headers.get_all("X-RailTime-Template", [])
        if len(values) != 1:
            return unchanged("header_missing" if not values else "header_ambiguous")
        template = str(values[0]).strip(" \t")
        # Reject folded identity headers too; no parser unfolding as admission.
        raw_values = [v for k, v in root.headers.raw_items() if k.lower() == "x-railtime-template"]
        if any("\r" in v or "\n" in v for v in raw_values) or raw_values[0].strip(" \t") != template or not TEMPLATE.fullmatch(template):
            return unchanged("header_invalid")
        if any(root.headers.get_all(name, []) for name in ("DKIM-Signature", "ARC-Seal", "ARC-Message-Signature")):
            return unchanged("signed_transport_requires_resigning_contract")
        if root.headers.get_all("X-RailTime-Transit", []):
            return unchanged("transit_state_requires_ingress_proof")
        state = {"parts": 0, "html": [], "boundaries": set()}
        _walk(message, root, state)
        if len(state["html"]) != 1:
            return unchanged("html_absent" if not state["html"] else "html_ambiguous")
        part = state["html"][0]
        decoded, encoding, charset = _decode(message, part)
        try:
            transformed = transform_html(decoded.decode(charset), {"X-RailTime-Template": template})
            if not isinstance(transformed, HtmlResult) or type(transformed.changed) is not bool or not isinstance(transformed.html, str):
                return unchanged("transformer_contract_invalid")
            if not transformed.changed:
                return unchanged(transformed.reason if re.fullmatch(r"[a-z_]{1,80}", transformed.reason) else "transformer_unchanged")
            if transformed.html == decoded.decode(charset):
                return unchanged("transformer_unchanged")
        except Exception:
            return unchanged("transformer_failed")
        replacement = _encode(transformed.html, encoding, charset)
        # Reject any new delimiter collision without rewriting MIME boundaries.
        if any(re.search(rb"(?m)^--" + re.escape(b.encode()) + rb"(?:--)?[ \t]*(?:\r\n|$)", replacement) for b in state["boundaries"]):
            return unchanged("output_boundary_collision")
        output = message[:part.body] + replacement + message[part.end:]
        if len(output) > MAX_MESSAGE_BYTES:
            return unchanged("mime_budget")
        return Result(output, True, "transformed", (part.body, part.end))
    except Unsupported as exc:
        return unchanged(str(exc))
    except Exception:
        # Parsing is fail-closed for transformation, not a new delivery path.
        return unchanged("mime_invalid")
