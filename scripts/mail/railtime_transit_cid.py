"""Inactive CID-aware byte adapter for the IT-only B1 transit pilot.

This module never fetches media, sends mail or serializes a MIME/HTML tree.
``transform_message_with_cid`` delegates HTML changes to the existing E2
transformer. Its callback receives (original_decoded_html, headers, cid_urls).
The callback MUST replace IMG sources only in the admitted B1 send projection,
BEFORE transplanting opaque original author bytes. It must not apply a global
URL replacement to incoming/result HTML: author content and quotes are opaque.

Media bytes must be supplied from the separately admitted local B1 media
manifest. Only the current pinned PNG files below are allowed. Compatible
resources in the HTML body's surrounding related entity are reused. New ones
are appended there; otherwise the HTML leaf is wrapped in related. Siblings,
attachments, delimiters and thread/envelope-independent headers untouched.
Single-HTML messages are supported by moving only MIME entity headers to the
inner HTML entity; ordinary message headers keep their original raw bytes.

Reparenting an HTML entity carrying Content-ID/Content-Location/Content-Base is
deliberately unsupported. TNEF, signatures, ambiguity and unsupported input
return the complete original message. Transport trust, publication/version
admission, durable queueing and trusted loop completion remain caller duties.
Decoded HTML author/quote bytes are protected by the HTML callback contract;
the encoded HTML payload and necessary MIME structural headers may change.
"""
from __future__ import annotations

import base64
import binascii
from dataclasses import dataclass
import hashlib
import re
from typing import Callable, Sequence

from railtime_transit import (
    HtmlResult, MAX_MESSAGE_BYTES, PILOT, Part, Result, Unsupported,
    _headers, _walk, transform_message,
)

APP_ORIGIN = "https://app.rail-time.de"
# Same immutable media identities as OutlookUniversalPresentation (C-K15/v2).
PINNED_MEDIA = {
    "b983af0049.png": "b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a",
    "9dfa25b253.png": "9dfa25b253b065c73173b884939db2a032453c44eb2ba6e9e65434a95a072675",
    "d92bd192b6.png": "d92bd192b664c536fe29b1c927023bca44041de90bc9bfe34e6df03be5006d32",
    "1593d63cca.png": "1593d63ccacf8c884b71870fe1478f218ea0f541e918d84115978bc2b75c64ff",
    "a38bc7d27c.png": "a38bc7d27c9c8c04c2a20815ad9576c76aebfe0bd651f9c5e90909b83bdf6925",
    "8a9ee1e161.png": "8a9ee1e1614f8e48fc374fb99ccd088ea33b4f3f156a63ed5294d23ab840e50d",
    "4b1a2aaba8.png": "4b1a2aaba8a4720445c7b80d1599765da5b728776eb876ea2e31facd8866db1c",
    "19125261c2.png": "19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1",
    "f9d88b899d.png": "f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9",
    "1111670ac8.png": "1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a",
    "b4b1fbea07.png": "b4b1fbea07044c782344b98404fc91c41b5a9e844ef5535f150d7649d62f3160",
    "f1b083c20b.png": "f1b083c20b84f813007ca8d1b08c01ef77e08a819f12dc701a1ed94a2d7fd3fc",
    "14d7943c88.png": "14d7943c888b83659542baaa26172dde70e51df8fc893a4c0cc48b14e47f6351",
}
CID = re.compile(r"[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+\Z")
ENTITY_KEYS = {b"content-type", b"content-transfer-encoding", b"content-disposition"}


@dataclass(frozen=True)
class AdmittedMedia:
    source: str
    data: bytes


@dataclass(frozen=True)
class CidResult:
    message: bytes
    changed: bool
    reason: str
    added_cids: tuple[str, ...] = ()
    reused_cids: tuple[str, ...] = ()
    # Original-input span, potentially enlarged from payload to MIME entity.
    replaced_span: tuple[int, int] | None = None


@dataclass
class _Node:
    part: Part
    parent: "_Node | None"


def _validated_media(media: Sequence[AdmittedMedia]) -> dict[str, tuple[str, bytes, str]]:
    if not isinstance(media, (list, tuple)) or not 1 <= len(media) <= len(PINNED_MEDIA):
        raise Unsupported("cid_media_invalid")
    output = {}
    for entry in media:
        if not isinstance(entry, AdmittedMedia) or not isinstance(entry.source, str) or not isinstance(entry.data, bytes):
            raise Unsupported("cid_media_invalid")
        name = entry.source.removeprefix(APP_ORIGIN + "/m/")
        expected = PINNED_MEDIA.get(name)
        if (entry.source != APP_ORIGIN + "/m/" + name or expected is None
                or entry.source in output or not entry.data or len(entry.data) > 2_000_000
                or hashlib.sha256(entry.data).hexdigest() != expected):
            raise Unsupported("cid_media_invalid")
        output[entry.source] = (name, entry.data, expected)
    return output


def _nodes(message: bytes) -> tuple[list[_Node], Part]:
    root = _headers(message, 0, len(message))
    state = {"parts": 0, "html": [], "boundaries": set()}
    _walk(message, root, state)
    if len(state["html"]) != 1:
        raise Unsupported("cid_html_ambiguous")
    output = []

    def collect(part: Part, parent: _Node | None) -> None:
        node = _Node(part, parent)
        output.append(node)
        if not part.headers.get_content_type().startswith("multipart/"):
            return
        boundary = part.headers.get_boundary().encode("ascii")
        marks = list(re.finditer(rb"(?m)^--" + re.escape(boundary) + rb"(--)?[ \t]*\r\n", message[part.body:part.end]))
        for before, after in zip(marks, marks[1:]):
            child = _headers(message, part.body + before.end(), part.body + after.start() - 2)
            collect(child, node)

    collect(root, None)
    return output, state["html"][0]


def _existing_cids(message: bytes, nodes: list[_Node]) -> dict[str, _Node]:
    ids = {}
    for node in nodes:
        values = node.part.headers.get_all("Content-ID", [])
        if not values:
            continue
        if len(values) != 1:
            raise Unsupported("cid_ambiguous")
        value = str(values[0])
        if not value.startswith("<") or not value.endswith(">"):
            raise Unsupported("cid_invalid")
        cid = value[1:-1]
        if len(cid) > 200 or not CID.fullmatch(cid) or cid in ids:
            raise Unsupported("cid_ambiguous")
        ids[cid] = node
    return ids


def _related_ancestor(node: _Node) -> _Node | None:
    parent = node.parent
    while parent:
        if parent.part.headers.get_content_type() == "multipart/related":
            return parent
        parent = parent.parent
    return None


def _resource_bytes(message: bytes, node: _Node) -> bytes | None:
    header = node.part.headers
    if header.get_content_type() != "image/png" or header.get_content_disposition() == "attachment":
        return None
    if str(header.get("Content-Transfer-Encoding", "")).strip().lower() != "base64":
        return None
    encoded = re.sub(rb"[ \t\r\n]", b"", message[node.part.body:node.part.end])
    try:
        decoded = base64.b64decode(encoded, validate=True)
        return decoded if base64.b64encode(decoded) == encoded else None
    except (ValueError, binascii.Error):
        return None


def _media_plan(message: bytes, media: dict, nodes: list[_Node], html: Part):
    ids = _existing_cids(message, nodes)
    html_node = next(node for node in nodes if node.part.start == html.start)
    related = _related_ancestor(html_node)
    urls, additions, reused = {}, {}, {}
    for source, (name, data, digest) in media.items():
        match = None
        if related:
            for cid, node in ids.items():
                if (node.parent is related
                        and _resource_bytes(message, node) == data):
                    match = cid
                    break
        cid = match or "railtime-transit-u2e-" + digest + "@inline.rail-time.de"
        if match is None and cid in ids:
            raise Unsupported("cid_conflict")
        urls[source] = "cid:" + cid
        if match is None:
            additions[cid] = (name, data)
        else:
            reused[cid] = True
    return urls, additions, reused


def _header_fields(message: bytes, part: Part) -> list[tuple[bytes, bytes]]:
    # Keep each existing field, including folding and order, as raw bytes.
    raw = message[part.start:part.body - 2]
    fields = []
    for line in raw.splitlines(keepends=True):
        if line[:1] in (b" ", b"\t"):
            if not fields:
                raise Unsupported("cid_headers_invalid")
            key, previous = fields[-1]
            fields[-1] = (key, previous + line)
        else:
            if b":" not in line:
                raise Unsupported("cid_headers_invalid")
            fields.append((line.split(b":", 1)[0].lower(), line))
    return fields


def _resource_entity(cid: str, name: str, data: bytes) -> bytes:
    encoded = base64.b64encode(data)
    payload = b"\r\n".join(encoded[i:i + 76] for i in range(0, len(encoded), 76))
    return (b"Content-Type: image/png\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <"
            + cid.encode("ascii") + b'>\r\nContent-Disposition: inline; filename="'
            + name.encode("ascii") + b'"\r\n\r\n' + payload)


def _append_related(message: bytes, result: Result, related: _Node, additions: dict):
    boundary = related.part.headers.get_boundary().encode("ascii")
    pattern = rb"(?m)^--" + re.escape(boundary) + rb"--[ \t]*\r\n"
    marks = list(re.finditer(pattern, message[related.part.body:related.part.end]))
    if len(marks) != 1:
        raise Unsupported("cid_related_boundary_invalid")
    original_close = related.part.body + marks[0].start()
    changed_close = original_close + len(result.message) - len(message)
    extra = b"".join(b"--" + boundary + b"\r\n" + _resource_entity(cid, name, data) + b"\r\n"
                     for cid, (name, data) in additions.items())
    return result.message[:changed_close] + extra + result.message[changed_close:], original_close


def _wrapped(message: bytes, result: Result, part: Part, additions: dict) -> bytes:
    if any(part.headers.get_all(key, []) for key in ("Content-ID", "Content-Location", "Content-Base")):
        raise Unsupported("cid_reparent_identity_unsupported")
    # E2 changed only the leaf's encoded payload; find its new end without a
    # full-message parse or serialization. Preserve all remaining raw bytes.
    new_end = part.end + len(result.message) - len(message)
    encoded_html = result.message[part.body:new_end]
    entity_headers = message[part.start:part.body]
    prefix = message[:part.start]
    is_root = part.start == 0
    fields = _header_fields(message, part)
    if is_root:
        if any(key.startswith(b"content-") and key not in ENTITY_KEYS for key, _ in fields):
            raise Unsupported("cid_root_headers_unsupported")
        entity_headers = b"".join(raw for key, raw in fields if key in ENTITY_KEYS) + b"\r\n"
    seed = hashlib.sha256(message + encoded_html).hexdigest()[:32]
    boundary = ("=_railtime_cid_" + seed).encode("ascii")
    if re.search(rb"(?m)^--" + re.escape(boundary) + rb"(?:--)?[ \t]*(?:\r\n|$)", encoded_html):
        raise Unsupported("cid_boundary_conflict")
    wrapper_header = b'Content-Type: multipart/related; boundary="' + boundary + b'"; type="text/html"\r\n'
    if is_root:
        outer = []
        for key, raw in fields:
            if key == b"content-type":
                outer.append(wrapper_header)
            elif key not in ENTITY_KEYS:
                outer.append(raw)
        prefix = b"".join(outer) + b"\r\n"
    else:
        prefix += wrapper_header + b"\r\n"
    body = b"--" + boundary + b"\r\n" + entity_headers + encoded_html
    for cid, (name, data) in additions.items():
        body += b"\r\n--" + boundary + b"\r\n" + _resource_entity(cid, name, data)
    # The inner closing delimiter has its own CRLF. The unchanged parent
    # delimiter's leading CRLF is separate (RFC 2046), so keep both when nested.
    body += b"\r\n--" + boundary + b"--\r\n"
    return prefix + body + message[part.end:]


def transform_message_with_cid(
    message: bytes,
    transform_html: Callable[[str, dict[str, str], dict[str, str]], HtmlResult],
    admitted_media: Sequence[AdmittedMedia],
    *,
    trusted_ingress: bool = False,
    envelope_sender: str = "",
) -> CidResult:
    """Prepare safe CIDs, call the admitted B1 HTML transformer, add resources.

    Example callback: construct a CID-only variant of the independently admitted
    B1 projection using cid_urls, then call OutlookUniversalTransitTransformer on
    the original HTML. Never replace URLs in original author/quote bytes.
    All errors/unsupported cases return the complete original, without logging
    message data or exceptions. The function performs no delivery or I/O.
    """
    def unchanged(reason: str) -> CidResult:
        return CidResult(message, False, reason)

    if not trusted_ingress:
        return unchanged("untrusted_ingress")
    if envelope_sender != PILOT:
        return unchanged("outside_pilot")
    context = {}

    def callback(html: str, headers: dict[str, str]) -> HtmlResult:
        try:
            media = _validated_media(admitted_media)
            nodes, part = _nodes(message)
            urls, additions, reused = _media_plan(message, media, nodes, part)
        except Unsupported as exc:
            return HtmlResult(html, False, str(exc))
        transformed = transform_html(html, headers, dict(urls))
        if not isinstance(transformed, HtmlResult) or not transformed.changed:
            return transformed
        used = {url[4:] for url in urls.values() if url in transformed.html}
        if not used:
            return HtmlResult(html, False, "cid_not_used")
        html_node = next(node for node in nodes if node.part.start == part.start)
        context.update(part=part,
                       related=_related_ancestor(html_node),
                       additions={cid: item for cid, item in additions.items() if cid in used},
                       reused={cid: value for cid, value in reused.items() if cid in used})
        return transformed

    try:
        result = transform_message(message, callback, trusted_ingress=True, envelope_sender=envelope_sender)
        if not result.changed:
            return unchanged(result.reason)
        part = context["part"]
        additions, reused = context["additions"], context["reused"]
        output, span = result.message, result.replaced_span
        if additions:
            if context["related"] is not None:
                output, close = _append_related(message, result, context["related"], additions)
                span = (part.body, close)
            else:
                output = _wrapped(message, result, part, additions)
                span = (part.start, part.end)
        if len(output) > MAX_MESSAGE_BYTES:
            return unchanged("mime_budget")
        return CidResult(output, True, "transformed_with_cid", tuple(additions), tuple(reused),
                         span)
    except Unsupported as exc:
        return unchanged(str(exc))
    except Exception:
        return unchanged("cid_adapter_failed")
