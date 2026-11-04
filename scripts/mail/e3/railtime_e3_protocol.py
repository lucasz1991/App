"""Pure Postfix policy/Milter sessions; tests drive byte frames, never sockets.

Require Milter v6 HDR_LEADSPC so original colon whitespace participates in the
ticket. Queue ID is required only at EOM. Postfix's own Received is not visible
to the Milter; the after-queue worker admits its one specifically bound trace.
"""
from __future__ import annotations

import ipaddress
import re
import struct

from railtime_e3 import (Admission, Connection, Deferred, E3Error, Envelope, PILOT,
                        CONTEXT, INGRESS, LOCAL, TicketStore, MAX_MESSAGE_BYTES, parse_fields)

HDR_LEADSPC = 0x00100000
ADD_HEADERS = 0x00000001
CHANGE_HEADERS = 0x00000010
CONTINUE, ACCEPT, TEMPFAIL, REJECT = b'c', b'a', b't', b'r'


def policy_request(attributes: dict[str, str], admission: Admission) -> str:
    """Only trusted Postfix policy-socket attributes reach this function."""
    if attributes.get('request') != 'smtpd_access_policy' or attributes.get('protocol_state') != 'RCPT':
        return 'DUNNO'
    try:
        connection = Connection(attributes.get('client_address', ''), attributes.get('encryption_protocol', ''),
                                int(attributes.get('encryption_keysize', '0')))
    except ValueError:
        return '550 5.7.1 E3_TRANSIT_NOT_ADMITTED' if attributes.get('recipient', '').casefold() == PILOT else 'DUNNO'
    envelope = Envelope(attributes.get('sender', ''), ())
    return admission.policy.rcpt_policy(connection, envelope, attributes.get('recipient', ''))


def _address(raw: bytes) -> str:
    try:
        value = raw.decode('ascii')
    except UnicodeError as exc:
        raise E3Error('envelope_invalid') from exc
    if len(value) > 254 or any(c in value for c in '\r\n\x00'):
        raise E3Error('envelope_invalid')
    if value.startswith('<') and value.endswith('>'):
        value = value[1:-1]
    return value


class MilterSession:
    def __init__(self, admission: Admission, *, return_store: TicketStore | None = None):
        self.admission, self.return_store = admission, return_store
        self.peer = ''
        self.macros: dict[str, str] = {}
        self.negotiated = False
        self._reset()

    def _reset(self):
        self.sender = ''
        self.recipients: list[str] = []
        self.headers: list[bytes] = []
        self.header_overflow = False
        self.header_names: set[bytes] = set()
        self.body = bytearray()
        self.active = False
        self.accepted = False
        self.macros.pop('i', None)

    def _connection(self) -> Connection:
        peer = self.macros.get('client_addr', self.peer)
        if self.peer and peer != self.peer:
            raise E3Error('peer_macro_mismatch')
        try:
            bits = int(self.macros.get('cipher_bits', '0'))
        except ValueError as exc:
            raise E3Error('tls_macro_invalid') from exc
        return Connection(peer, self.macros.get('tls_version', ''), bits)

    def _candidate(self) -> bool:
        return self.return_store is not None or PILOT in {r.casefold() for r in self.recipients} or bool(self.header_names & {INGRESS, LOCAL, CONTEXT})

    def handle(self, command: bytes, data: bytes = b'') -> list[bytes]:
        try:
            return self._handle(command, data)
        except Deferred:
            return [TEMPFAIL]
        except (E3Error, ValueError, UnicodeError, IndexError, struct.error):
            return [REJECT]

    def _handle(self, command: bytes, data: bytes) -> list[bytes]:
        if command == b'O':
            if len(data) != 12:
                raise E3Error('protocol_negotiation')
            version, offered_actions, offered_protocol = struct.unpack('!III', data)
            if version < 6 or offered_actions & (ADD_HEADERS | CHANGE_HEADERS) != (ADD_HEADERS | CHANGE_HEADERS) or not offered_protocol & HDR_LEADSPC:
                raise Deferred('protocol_capability_required')
            self.negotiated = True
            return [b'O' + struct.pack('!III', 6, ADD_HEADERS | CHANGE_HEADERS, HDR_LEADSPC)]
        if command == b'D':
            values = data[1:].split(b'\x00')
            if values[-1:] != [b''] or len(values[:-1]) % 2:
                raise E3Error('macro_invalid')
            for key, value in zip(values[0:-1:2], values[1:-1:2]):
                name = key.decode('ascii').strip('{}')
                if name in {'client_addr', 'tls_version', 'cipher_bits', 'cipher', 'i'}:
                    decoded = value.decode('ascii')
                    if len(decoded) > 200 or any(c in decoded for c in '\r\n\x00'):
                        raise E3Error('macro_invalid')
                    self.macros[name] = decoded
            return []
        if not self.negotiated:
            raise Deferred('protocol_not_negotiated')
        if command == b'C':
            hostname, rest = data.split(b'\x00', 1)
            if rest[:1] not in {b'4', b'6'} or len(rest) < 4 or not rest.endswith(b'\x00'):
                raise E3Error('peer_invalid')
            client = self.macros.get('client_addr')
            self.macros.clear()
            if client is not None:
                self.macros['client_addr'] = client
            self._reset()
            self.peer = str(ipaddress.ip_address(rest[3:-1].decode('ascii')))
            return [CONTINUE]
        if command in {b'A', b'Q', b'K'}:
            self._reset()
            if command in {b'Q', b'K'}:
                self.peer = ''
                self.macros.clear()
            return []
        if command == b'H':
            return [CONTINUE]
        if command == b'M':
            # Postfix provides TLS MAIL macros before MAIL; retain those while
            # clearing all preceding message state and the previous queue ID.
            tls = {k: v for k, v in self.macros.items() if k in {'client_addr', 'tls_version', 'cipher_bits', 'cipher'}}
            self._reset()
            self.macros.update(tls)
            self.sender = _address(data.split(b'\x00')[0])
            self.active = True
            return [CONTINUE]
        if not self.active:
            raise E3Error('message_state_invalid')
        if command == b'R':
            recipient = _address(data.split(b'\x00')[0])
            self.recipients.append(recipient)
            if len(self.recipients) > 100:
                raise E3Error('recipient_budget')
            return [CONTINUE]
        if command == b'L':
            name, value, trailing = data.split(b'\x00')
            if trailing or not re.fullmatch(rb'[!-9;-~]{1,100}', name):
                raise E3Error('header_invalid')
            self.header_names.add(name.lower())
            raw = name + b':' + value + b'\r\n'
            if sum(map(len, self.headers)) + len(raw) > 65_536:
                self.header_overflow = True
                if self._candidate():
                    raise E3Error('header_budget')
            else:
                self.headers.append(raw)
            return [CONTINUE]
        if command == b'N':
            if self.header_overflow and self._candidate():
                raise E3Error('header_budget')
            if not self._candidate():
                self.accepted = True
                return [ACCEPT]
            return [CONTINUE]
        if command == b'B':
            if self.accepted:
                return [ACCEPT]
            self.body.extend(data)
            budget = MAX_MESSAGE_BYTES if self.return_store is not None else self.admission.policy.maximum_bytes
            if len(self.body) + sum(map(len, self.headers)) + 2 > budget:
                raise E3Error('message_budget')
            return [CONTINUE]
        if command == b'E':
            if self.accepted:
                return [ACCEPT]
            message = b''.join(self.headers) + b'\r\n' + bytes(self.body)
            envelope = Envelope(self.sender, tuple(self.recipients), self.macros.get('i', ''))
            if self.return_store is not None:
                connection = self._connection()
                if not ipaddress.ip_address(connection.peer).is_loopback:
                    raise E3Error('return_peer_invalid')
                fields, body = parse_fields(message)
                controls = [(key, raw) for key, raw in fields if key == CONTEXT]
                if len(controls) != 1:
                    raise E3Error('return_context_missing')
                value = controls[0][1].split(b':', 1)[1].strip(b' \t\r\n')
                if not re.fullmatch(rb'e3r1; ticket=[0-9a-f]{64}', value) or b'\r' in value or b'\n' in value:
                    raise E3Error('return_context_invalid')
                without = b''.join(raw for key, raw in fields if key != CONTEXT) + b'\r\n' + body
                self.return_store.admit_return(value.split(b'=')[1].decode('ascii'), without, envelope)
                return [b'm' + struct.pack('!I', 1) + b'X-RailTime-Transit-Context\x00\x00', CONTINUE]
            ticket = self.admission.eod(message, self._connection(), envelope)
            if ticket is None:
                return [ACCEPT]
            # HDR_LEADSPC requires us, rather than the MTA, to add this space.
            return [b'hX-RailTime-Local-Admission\x00 e3p1; ticket=' + ticket.encode('ascii') + b'\x00', CONTINUE]
        if command == b'U':
            return [CONTINUE]
        raise E3Error('protocol_command_invalid')
