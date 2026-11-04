"""Inactive E3 return-header primitive; no SMTP, trust admission or queue I/O.

Only a caller which has already authenticated the SMTP hop may use this helper.
Every successful return loses the routing header, including unchanged TNEF and
controlled transformer failures. A copied result header is never a bypass.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

from railtime_transit import MAX_MESSAGE_BYTES, Unsupported, _headers

CONTROL_HEADERS = frozenset({
    b'x-railtime-template', b'x-railtime-transit',
    b'x-railtime-transit-ingress', b'x-railtime-transit-context',
    b'x-railtime-transit-result', b'x-railtime-local-admission',
})
RESULT = b'X-RailTime-Transit-Result: v1; '


class ReturnNotSafe(ValueError):
    """Infrastructure caller must defer (EX_TEMPFAIL), never send original."""


@dataclass(frozen=True)
class Completed:
    message: bytes
    removed_fields: int
    transformed: bool


def _fields(message: bytes) -> tuple[list[tuple[bytes, bytes]], int]:
    try:
        root = _headers(message, 0, len(message))
    except Unsupported as exc:
        raise ReturnNotSafe('return_headers_invalid') from exc
    # Strict byte scanner; never unfold/reserialize the retained fields.
    raw = message[:root.body - 2]
    fields = []
    for line in raw.splitlines(keepends=True):
        if not line.endswith(b'\r\n'):
            raise ReturnNotSafe('return_headers_invalid')
        if line[:1] in (b' ', b'\t'):
            if not fields:
                raise ReturnNotSafe('return_headers_invalid')
            key, value = fields[-1]
            fields[-1] = (key, value + line)
            continue
        m = re.match(rb'([!-9;-~]+):', line)
        if not m:
            raise ReturnNotSafe('return_headers_invalid')
        fields.append((m.group(1).lower(), line))
    return fields, root.body


def finalize_return(message: bytes, *, authenticated_ingress: bool,
                    transformed: bool) -> Completed:
    """Remove only named root control fields; preserve every payload byte.

    For a transformed message the caller separately proves author/quote bytes.
    This helper proves only the remaining outer MIME boundary. It refuses to
    invalidate any DKIM/ARC signature covering one of the removed fields.
    """
    if authenticated_ingress is not True or type(transformed) is not bool:
        raise ReturnNotSafe('return_not_authenticated')
    if not isinstance(message, bytes) or not 0 < len(message) <= MAX_MESSAGE_BYTES:
        raise ReturnNotSafe('return_budget')
    fields, body = _fields(message)
    for key, raw in fields:
        if key not in {b'dkim-signature', b'arc-message-signature'}:
            continue
        # A protected-header ambiguity queues; it never justifies corruption.
        value = re.sub(rb'\r\n[ \t]+', b' ', raw.split(b':', 1)[1])
        matches = re.findall(rb'(?:^|;)\s*h\s*=\s*([^;]+)', value, re.I)
        if len(matches) != 1:
            raise ReturnNotSafe('return_signed_headers_ambiguous')
        signed = {v.strip().lower() for v in matches[0].split(b':')}
        if signed & CONTROL_HEADERS:
            raise ReturnNotSafe('return_control_header_signed')
    retained = b''.join(raw for key, raw in fields if key not in CONTROL_HEADERS)
    status = b'transformed' if transformed else b'unchanged'
    output = retained + RESULT + status + b'\r\n\r\n' + message[body:]
    if len(output) > MAX_MESSAGE_BYTES:
        raise ReturnNotSafe('return_budget')
    # Inspect our own output so an upstream caller cannot accidentally reinject
    # a surviving routing/credential field after a malformed header boundary.
    final, _ = _fields(output)
    if any(key in CONTROL_HEADERS - {b'x-railtime-transit-result'} for key, raw in final):
        raise ReturnNotSafe('return_control_header_survived')
    if sum(key == b'x-railtime-transit-result' for key, raw in final) != 1:
        raise ReturnNotSafe('return_result_ambiguous')
    return Completed(output, sum(key in CONTROL_HEADERS for key, raw in fields), transformed)
