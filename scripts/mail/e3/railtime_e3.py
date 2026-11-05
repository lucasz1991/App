"""C-K17 local E3 primitives. No listener, mail send or activation on import.

The SMTP adapter supplies real Postfix connection/envelope facts. MIME fields
never supply peer/TLS/tenant authorization. All persisted mail is private spool,
not development diagnostics. External I/O is supplied by explicit callbacks.
"""
from __future__ import annotations

import base64
from contextlib import contextmanager
from dataclasses import dataclass
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import time
from typing import Callable

from railtime_transit import MAX_MESSAGE_BYTES, PILOT, TEMPLATE, HtmlResult
from railtime_transit_cid import AdmittedMedia, PINNED_MEDIA, APP_ORIGIN, transform_message_with_cid
from railtime_transit_return import CONTROL_HEADERS, ReturnNotSafe, _fields, finalize_return


LOCAL = b'x-railtime-local-admission'
INGRESS = b'x-railtime-transit-ingress'
TEMPLATE_KEY = b'x-railtime-template'
CONTEXT = b'x-railtime-transit-context'
HEX64 = re.compile(r'[0-9a-f]{64}\Z')
QUEUE = re.compile(r'[A-Za-z0-9]{5,32}\Z')
TLS = frozenset({'TLSv1.2', 'TLSv1.3'})
REJECT = '550 5.7.1 E3_TRANSIT_NOT_ADMITTED'
DEFER = '451 4.3.0 E3_TRANSIT_TEMPORARILY_UNAVAILABLE'


class E3Error(ValueError):
    """Finite codes only; never place raw SMTP/MIME/configuration in messages."""


class Deferred(E3Error):
    pass


def digest(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def canonical(value: object) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode('ascii')


def _unfolded(raw: bytes) -> str:
    if not raw.endswith(b'\r\n') or b'\r' in raw[:-2] or b'\n' in raw[:-2]:
        raise E3Error('header_folded')
    try:
        return raw.split(b':', 1)[1][:-2].strip(b' \t').decode('ascii')
    except (UnicodeError, IndexError) as exc:
        raise E3Error('header_invalid') from exc


def parse_fields(message: bytes) -> tuple[list[tuple[bytes, bytes]], bytes]:
    try:
        fields, body = _fields(message)
    except ReturnNotSafe as exc:
        raise E3Error('headers_invalid') from exc
    return fields, message[body:]


@dataclass(frozen=True)
class Connection:
    peer: str
    tls_version: str
    cipher_bits: int


@dataclass(frozen=True)
class Envelope:
    sender: str
    recipients: tuple[str, ...]
    queue_id: str = ''


@dataclass(frozen=True)
class PilotPolicy:
    tenant_id: str
    user_id: int
    approved_header: str
    microsoft_networks: tuple[str, ...]
    local_host: str = 'plesk.follow-flow.de'
    maximum_bytes: int = 9_000_000

    def __post_init__(self):
        if (not re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', self.tenant_id)
                or type(self.user_id) is not int or self.user_id <= 0 or not TEMPLATE.fullmatch(self.approved_header)
                or not self.microsoft_networks or self.local_host != 'plesk.follow-flow.de'
                or not 1024 <= self.maximum_bytes <= 9_000_000):
            raise E3Error('policy_invalid')
        try:
            for network in self.microsoft_networks:
                if ipaddress.ip_network(network).prefixlen == 0:
                    raise E3Error('peer_network_too_broad')
        except ValueError as exc:
            raise E3Error('peer_network_invalid') from exc

    def peer_allowed(self, connection: Connection) -> bool:
        try:
            peer = ipaddress.ip_address(connection.peer)
            return (connection.tls_version in TLS and type(connection.cipher_bits) is int
                    and connection.cipher_bits >= 128
                    and any(peer in ipaddress.ip_network(n) for n in self.microsoft_networks))
        except (ValueError, TypeError):
            return False

    def rcpt_policy(self, connection: Connection, envelope: Envelope, recipient: str) -> str:
        """Place before reject_unauth_destination, never a general Microsoft relay permit."""
        if recipient.casefold() != PILOT:
            return 'DUNNO'
        if envelope.sender != PILOT or recipient != PILOT or not self.peer_allowed(connection):
            return REJECT
        return 'OK'

    def check_envelope(self, connection: Connection, envelope: Envelope) -> None:
        if (not self.peer_allowed(connection) or envelope.sender != PILOT
                or envelope.recipients != (PILOT,) or not QUEUE.fullmatch(envelope.queue_id)):
            raise E3Error('envelope_not_admitted')


def _strict_snapshot(value: dict, policy: PilotPolicy) -> dict:
    if not isinstance(value, dict) or value.get('schema') != 1 or type(value.get('ok')) is not bool:
        raise Deferred('resolver_contract')
    if not value['ok']:
        if (value.get('retryable') is False and value.get('code') in {
                'U2E_TRANSIT_VERSION_NOT_CURRENT', 'U2E_TRANSIT_SOURCE_REJECTED'}):
            return {'schema': 1, 'ok': False, 'code': value['code'], 'retryable': False}
        raise Deferred('resolver_unavailable')
    projection, media = value.get('projection'), value.get('media')
    if (not isinstance(projection, dict) or projection.get('mode') != 'send'
            or projection.get('header') != policy.approved_header
            or projection.get('templateHash') != '942f8bf2e36712fb616d5acd3096d642baf3731c7952f72cadd1a474796600ba'
            or not isinstance(media, list) or not 1 <= len(media) <= 13):
        raise Deferred('resolver_snapshot_invalid')
    seen = set()
    for item in media:
        if not isinstance(item, dict) or set(item) != {'source', 'mime', 'sha256', 'dataBase64'}:
            raise Deferred('resolver_media_invalid')
        source = item['source']
        if not isinstance(source, str) or not source.startswith(APP_ORIGIN + '/m/'):
            raise Deferred('resolver_media_invalid')
        name = source.removeprefix(APP_ORIGIN + '/m/')
        if name not in PINNED_MEDIA or source in seen or item['mime'] != 'image/png' or item['sha256'] != PINNED_MEDIA[name]:
            raise Deferred('resolver_media_invalid')
        try:
            content = base64.b64decode(item['dataBase64'], validate=True)
        except (ValueError, TypeError) as exc:
            raise Deferred('resolver_media_invalid') from exc
        if not content or len(content) > 2_000_000 or digest(content) != item['sha256']:
            raise Deferred('resolver_media_invalid')
        seen.add(source)
    if {item.get('url') for item in projection.get('media', [])} != seen:
        raise Deferred('resolver_media_mismatch')
    return value


def _private_directory(path: Path) -> None:
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    st = path.lstat()
    if path.is_symlink() or not stat.S_ISDIR(st.st_mode):
        raise Deferred('spool_directory_invalid')
    if os.name != 'nt' and (st.st_uid != os.geteuid() or st.st_mode & 0o077):
        raise Deferred('spool_directory_permissions')


def _write_private(path: Path, data: bytes) -> None:
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(path, 0o400)
        if os.name != 'nt':
            directory = os.open(path.parent, os.O_RDONLY | getattr(os, 'O_DIRECTORY', 0))
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
    except BaseException:
        # Only this exclusive creation may be removed; never another message.
        path.unlink(missing_ok=True)
        raise


class TicketStore:
    """Immutable signed admission/capture separate from an atomic mutable journal."""
    def __init__(self, root: Path, key: bytes, now: Callable[[], float] = time.time, *, max_jobs: int = 32):
        if not isinstance(key, bytes) or len(key) != 32:
            raise E3Error('ticket_key_invalid')
        self.root, self.key, self.now = root.absolute(), key, now
        if type(max_jobs) is not int or not 1 <= max_jobs <= 32:
            raise E3Error('spool_quota_invalid')
        self.max_jobs = max_jobs
        if any(parent.is_symlink() for parent in [self.root, *self.root.parents]):
            raise Deferred('spool_directory_invalid')
        _private_directory(self.root)
        self.db = self.root / 'journal.sqlite3'
        if self.db.is_symlink():
            raise Deferred('journal_invalid')
        if not self.db.exists():
            fd = os.open(self.db, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
            os.close(fd)
        if os.name != 'nt' and self.db.stat().st_mode & 0o077:
            raise Deferred('journal_permissions')
        with self.connect() as db:
            db.execute('CREATE TABLE IF NOT EXISTS jobs (ticket TEXT PRIMARY KEY, state TEXT NOT NULL, lease REAL NOT NULL DEFAULT 0, lease_owner TEXT, attempts INTEGER NOT NULL DEFAULT 0, reserved_bytes INTEGER NOT NULL, queue_hash TEXT, output_hash TEXT, return_token TEXT, return_queue TEXT, completed REAL, code TEXT, result_code TEXT)')

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.db, timeout=5)
        db.execute('PRAGMA synchronous=FULL')
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    def _path(self, ticket: str, suffix: str) -> Path:
        if not HEX64.fullmatch(ticket) or suffix not in {'json', 'eml', 'out', 'queued'}:
            raise Deferred('ticket_invalid')
        return self.root / (ticket + '.' + suffix)

    def issue(self, message: bytes, connection: Connection, envelope: Envelope, snapshot: dict, policy: PilotPolicy) -> str:
        fields, body = parse_fields(message)
        ticket = secrets.token_hex(32)
        content = {'schema': 1, 'ticket': ticket, 'created': self.now(), 'expires': self.now() + 7 * 86400,
                   'tenantId': policy.tenant_id, 'userId': policy.user_id,
                   'queueId': envelope.queue_id, 'peer': connection.peer, 'tlsVersion': connection.tls_version,
                   'cipherBits': connection.cipher_bits, 'sender': envelope.sender, 'recipients': list(envelope.recipients),
                   'templateHeader': policy.approved_header, 'originalHash': digest(message), 'bodyHash': digest(body),
                   'fields': [base64.b64encode(raw).decode('ascii') for _, raw in fields], 'snapshot': snapshot}
        signed = {'content': content, 'hmac': hmac.new(self.key, canonical(content), hashlib.sha256).hexdigest()}
        reserved = len(message) + len(canonical(signed)) + 2*MAX_MESSAGE_BYTES
        try:
            with self.connect() as db:
                db.execute('BEGIN IMMEDIATE')
                if db.execute('SELECT COUNT(*) FROM jobs').fetchone()[0] >= self.max_jobs:
                    raise Deferred('spool_quota')
                if db.execute('SELECT COALESCE(SUM(reserved_bytes),0) FROM jobs').fetchone()[0] + reserved > 800_000_000:
                    raise Deferred('spool_quota')
                _write_private(self._path(ticket, 'eml'), message)
                _write_private(self._path(ticket, 'json'), canonical(signed))
                db.execute('INSERT INTO jobs(ticket,state,reserved_bytes) VALUES(?,?,?)', (ticket, 'pending', reserved))
        except (OSError, sqlite3.Error) as exc:
            # No final SMTP250 has been issued; remove only this failed exclusive creation.
            for suffix in ('json', 'eml'):
                path = self._path(ticket, suffix)
                if path.is_file() and not path.is_symlink():
                    if os.name == 'nt':
                        os.chmod(path, 0o600)
                    path.unlink()
            raise Deferred('ticket_write_failed') from exc
        return ticket

    def read(self, ticket: str, *, allow_expired: bool = False) -> dict:
        path = self._path(ticket, 'json')
        if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1 or path.stat().st_size > 3_000_000:
            raise Deferred('ticket_invalid')
        try:
            signed = json.loads(path.read_bytes())
            content = signed['content']
            expected = hmac.new(self.key, canonical(content), hashlib.sha256).hexdigest()
            if not isinstance(signed['hmac'], str) or not hmac.compare_digest(expected, signed['hmac']):
                raise Deferred('ticket_integrity')
            if content['ticket'] != ticket or content['schema'] != 1 or (not allow_expired and self.now() >= content['expires']):
                raise Deferred('ticket_expired_or_invalid')
            original = self._path(ticket, 'eml')
            if original.is_symlink() or not original.is_file() or digest(original.read_bytes()) != content['originalHash']:
                raise Deferred('capture_integrity')
            return content
        except (KeyError, ValueError, TypeError, OSError) as exc:
            if isinstance(exc, Deferred):
                raise
            raise Deferred('ticket_invalid') from exc

    def purge_completed(self) -> dict:
        """No message is deleted while pending/ambiguous, even after admission expiry."""
        removed = unresolved = 0
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            rows = db.execute('SELECT ticket,state FROM jobs').fetchall()
            for row in rows:
                if row['state'] == 'purging':
                    continue
                content = self.read(row['ticket'], allow_expired=True)
                if self.now() < content['expires']:
                    continue
                if row['state'] != 'submitted':
                    unresolved += 1
                    continue
                # Durable tombstone BEFORE any nontransactional filesystem deletion.
                db.execute('UPDATE jobs SET state=? WHERE ticket=? AND state=?', ('purging', row['ticket'], 'submitted'))
        with self.connect() as db:
            pending = db.execute('SELECT ticket FROM jobs WHERE state=?', ('purging',)).fetchall()
        for row in pending:
            self._purge_artifacts(row['ticket'])
            with self.connect() as db:
                db.execute('DELETE FROM jobs WHERE ticket=? AND state=?', (row['ticket'], 'purging'))
            removed += 1
        return {'removed': removed, 'expiredUnresolved': unresolved, 'requiresManualQueueReview': unresolved > 0}

    def _purge_artifacts(self, ticket: str) -> None:
        # Idempotent replay of a durably authenticated expired/submitted tombstone.
        # Never glob/delete a Postfix queue or an unresolved admission ticket.
        for suffix in ('out', 'queued', 'eml', 'json'):
            path = self._path(ticket, suffix)
            if path.is_symlink():
                raise Deferred('cleanup_path_invalid')
            if path.exists():
                if os.name == 'nt':
                    os.chmod(path, 0o600)
                path.unlink()
        if os.name != 'nt':
            directory = os.open(self.root, os.O_RDONLY | getattr(os, 'O_DIRECTORY', 0))
            try:
                os.fsync(directory)
            finally:
                os.close(directory)

    def bind(self, message: bytes, envelope: Envelope, peer: str, local_host: str) -> tuple[str, dict]:
        fields, body = parse_fields(message)
        local = [_unfolded(raw) for key, raw in fields if key == LOCAL]
        if len(local) != 1 or not re.fullmatch(r'e3p1; ticket=[0-9a-f]{64}', local[0]):
            raise Deferred('local_ticket_missing')
        ticket = local[0].split('=', 1)[1]
        content = self.read(ticket)
        if (content['queueId'] != envelope.queue_id or content['sender'] != envelope.sender
                or content['recipients'] != list(envelope.recipients) or content['peer'] != peer
                or digest(body) != content['bodyHash']):
            raise Deferred('ticket_binding')
        actual = [(key, raw) for key, raw in fields if key != LOCAL]
        expected = [base64.b64decode(raw, validate=True) for raw in content['fields']]
        # Postfix hides ONLY its own Received field from the before-queue Milter.
        # Permit exactly one newly prepended local trace, with this host AND queue ID.
        if len(actual) == len(expected) + 1:
            key, raw = actual.pop(0)
            trace = re.sub(rb'\r\n[ \t]+', b' ', raw)
            host = re.escape(local_host.encode('ascii'))
            queue = re.escape(envelope.queue_id.encode('ascii'))
            if (key != b'received' or not re.search(rb'\bby '+host+rb' \(Postfix\) with [A-Z0-9]+ id '+queue+rb'\b', trace)):
                raise Deferred('local_trace_invalid')
        if [raw for _, raw in actual] != expected:
            raise Deferred('ticket_headers_changed')
        return ticket, content

    def claim(self, ticket: str) -> tuple[str, str]:
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT * FROM jobs WHERE ticket=?', (ticket,)).fetchone()
            if row is None:
                raise Deferred('journal_missing')
            if row['state'] == 'submitted':
                return 'submitted', ''
            if row['state'] in {'submitting', 'uncertain'}:
                raise Deferred('return_uncertain_review_required')
            if row['lease'] > self.now():
                raise Deferred('job_busy')
            owner = secrets.token_hex(32)
            db.execute('UPDATE jobs SET lease=?,lease_owner=?,attempts=attempts+1 WHERE ticket=?', (self.now() + 120, owner, ticket))
            return row['state'], owner

    def capture_queue(self, ticket: str, message: bytes, owner: str) -> None:
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT * FROM jobs WHERE ticket=?', (ticket,)).fetchone()
            if not row or row['state'] not in {'pending', 'prepared'} or row['lease_owner'] != owner or row['lease'] <= self.now():
                raise Deferred('lease_changed')
            path = self._path(ticket, 'queued')
            if path.exists():
                if path.is_symlink() or path.read_bytes() != message or row['queue_hash'] not in {None, digest(message)}:
                    raise Deferred('queue_capture_changed')
                if row['queue_hash'] is None:
                    # Recover a crash after durable exclusive creation but before DB commit.
                    # bind() and the current lease already authenticate these exact bytes.
                    db.execute('UPDATE jobs SET queue_hash=? WHERE ticket=? AND lease_owner=?', (digest(message), ticket, owner))
            else:
                _write_private(path, message)
                db.execute('UPDATE jobs SET queue_hash=? WHERE ticket=? AND lease_owner=?', (digest(message), ticket, owner))

    def prepare(self, ticket: str, output: bytes, owner: str, result_code: str = 'controlled_unchanged') -> None:
        if not re.fullmatch(r'[a-z_]{1,80}', result_code):
            raise Deferred('result_code_invalid')
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT state,lease_owner,lease FROM jobs WHERE ticket=?', (ticket,)).fetchone()
            if not row or row['state'] != 'pending' or row['lease_owner'] != owner or row['lease'] <= self.now():
                raise Deferred('lease_changed')
            path = self._path(ticket, 'out')
            if path.exists():
                if path.is_symlink() or path.read_bytes() != output:
                    raise Deferred('prepared_output_changed')
            else:
                _write_private(path, output)
            db.execute('UPDATE jobs SET state=?,output_hash=?,result_code=? WHERE ticket=? AND lease_owner=?', ('prepared', digest(output), result_code, ticket, owner))

    def prepared(self, ticket: str, owner: str) -> bytes:
        with self.connect() as db:
            row = db.execute('SELECT output_hash,state,lease_owner,lease FROM jobs WHERE ticket=?', (ticket,)).fetchone()
        path = self._path(ticket, 'out')
        if (not row or row['state'] != 'prepared' or row['lease_owner'] != owner or row['lease'] <= self.now()
                or not row['output_hash'] or path.is_symlink() or not path.is_file()):
            raise Deferred('prepared_output_missing')
        output = path.read_bytes()
        if digest(output) != row['output_hash']:
            raise Deferred('prepared_output_integrity')
        return output

    def begin_return(self, ticket: str, owner: str) -> str:
        token = secrets.token_hex(32)
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            if db.execute('UPDATE jobs SET state=?,return_token=?,return_queue=NULL WHERE ticket=? AND state=? AND lease_owner=? AND lease>?',
                          ('submitting', digest(token.encode()), ticket, 'prepared', owner, self.now())).rowcount != 1:
                raise Deferred('return_state_invalid')
        return token

    def admit_return(self, token: str, message_without_context: bytes, envelope: Envelope) -> None:
        if not HEX64.fullmatch(token) or envelope.sender != PILOT or envelope.recipients != (PILOT,) or not QUEUE.fullmatch(envelope.queue_id):
            raise E3Error('return_not_admitted')
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT * FROM jobs WHERE return_token=? AND state=?', (digest(token.encode()), 'submitting')).fetchone()
            if row is None or row['return_queue'] is not None or row['output_hash'] != digest(message_without_context):
                raise E3Error('return_ticket_invalid')
            # The ticket remains private; the single return queue is bound before its SMTP250.
            self.read(row['ticket'])
            db.execute('UPDATE jobs SET return_queue=? WHERE ticket=?', (envelope.queue_id, row['ticket']))

    def complete(self, ticket: str, acknowledged_queue: str, owner: str) -> None:
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT return_queue,state,lease_owner FROM jobs WHERE ticket=?', (ticket,)).fetchone()
            if not row or row['state'] != 'submitting' or row['return_queue'] != acknowledged_queue or row['lease_owner'] != owner:
                raise Deferred('return_ack_mismatch')
            db.execute('UPDATE jobs SET state=?,completed=?,lease=0,lease_owner=NULL,return_token=NULL WHERE ticket=?', ('submitted', self.now(), ticket))

    def retry(self, ticket: str, owner: str, *, uncertain: bool, code: str) -> None:
        if not re.fullmatch(r'[a-z_]{1,80}', code):
            code = 'operation_failed'
        with self.connect() as db:
            db.execute('UPDATE jobs SET state=?,lease=0,lease_owner=NULL,code=?,return_token=NULL,return_queue=NULL WHERE ticket=? AND state=? AND lease_owner=?',
                       ('uncertain' if uncertain else 'prepared', code, ticket, 'submitting', owner))


class Admission:
    def __init__(self, policy: PilotPolicy, store: TicketStore, ingress_secret: bytes | None, resolver: Callable[[dict], dict]):
        if ingress_secret is not None and (not isinstance(ingress_secret, bytes) or not re.fullmatch(rb'[0-9a-f]{64}', ingress_secret)):
            raise E3Error('ingress_secret_invalid')
        self.policy, self.store, self.secret, self.resolver = policy, store, ingress_secret, resolver

    def eod(self, message: bytes, connection: Connection, envelope: Envelope) -> str | None:
        if self.secret is None:
            raise Deferred('new_admission_disabled')
        fields, _ = parse_fields(message)
        # Every IT recipient is a transit candidate; invalid secrets never fall through to normal relay.
        candidate = PILOT in {r.casefold() for r in envelope.recipients} or any(k in {INGRESS, LOCAL, CONTEXT} for k, _ in fields)
        if not candidate:
            return None
        if len(message) > self.policy.maximum_bytes:
            raise E3Error('message_too_large')
        self.policy.check_envelope(connection, envelope)
        if any(k in CONTROL_HEADERS - {TEMPLATE_KEY, INGRESS} for k, _ in fields):
            raise E3Error('forged_control_header')
        ingress = [_unfolded(v) for k, v in fields if k == INGRESS]
        version = [_unfolded(v) for k, v in fields if k == TEMPLATE_KEY]
        if len(ingress) != 1 or not hmac.compare_digest(ingress[0].encode('ascii'), self.secret):
            raise E3Error('ingress_not_admitted')
        if version != [self.policy.approved_header]:
            raise E3Error('version_not_admitted')
        request = {'schema': 1, 'tenantId': self.policy.tenant_id, 'userId': self.policy.user_id,
                   'envelopeSender': envelope.sender, 'templateHeader': version[0]}
        try:
            snapshot = _strict_snapshot(self.resolver(request), self.policy)
        except E3Error:
            raise
        except Exception as exc:
            raise Deferred('resolver_unavailable') from exc
        return self.store.issue(message, connection, envelope, snapshot, self.policy)


class ReinjectFailure(Deferred):
    def __init__(self, *, uncertain: bool):
        super().__init__('reinject_uncertain' if uncertain else 'reinject_unavailable')
        self.uncertain = uncertain


class PipeWorker:
    def __init__(self, store: TicketStore, policy: PilotPolicy,
                 transform: Callable[[str, dict, dict, dict], HtmlResult], send: Callable[[bytes, Envelope, str], str]):
        self.store, self.policy, self.transform, self.send = store, policy, transform, send

    def process(self, message: bytes, envelope: Envelope, client_address: str) -> dict:
        ticket, content = self.store.bind(message, envelope, client_address, self.policy.local_host)
        state, owner = self.store.claim(ticket)
        if state == 'submitted':
            return {'status': 'already-submitted'}
        self.store.capture_queue(ticket, message, owner)
        if state == 'pending':
            snapshot = content['snapshot']
            if snapshot['ok']:
                media = [AdmittedMedia(m['source'], base64.b64decode(m['dataBase64'], validate=True)) for m in snapshot['media']]
                callback_failed = []
                def callback(html, headers, cids):
                    try:
                        return self.transform(html, headers, snapshot['projection'], cids)
                    except Exception:
                        callback_failed.append(True)
                        raise
                result = transform_message_with_cid(message, callback, media, trusted_ingress=True, envelope_sender=envelope.sender)
                if callback_failed:
                    raise Deferred('transform_bridge_unavailable')
                output, changed = result.message, result.changed
                result_code = result.reason if re.fullmatch(r'[a-z_]{1,80}', result.reason) else 'controlled_unchanged'
            else:
                output, changed = message, False
                result_code = 'source_not_current' if snapshot['code'] == 'U2E_TRANSIT_VERSION_NOT_CURRENT' else 'source_not_admitted'
            try:
                completed = finalize_return(output, authenticated_ingress=True, transformed=changed)
            except ReturnNotSafe as exc:
                raise Deferred('return_headers_not_safe') from exc
            self.store.prepare(ticket, completed.message, owner, result_code)
        output = self.store.prepared(ticket, owner)
        token = self.store.begin_return(ticket, owner)
        try:
            acknowledged = self.send(output, envelope, token)
            self.store.complete(ticket, acknowledged, owner)
        except ReinjectFailure as exc:
            self.store.retry(ticket, owner, uncertain=exc.uncertain, code=str(exc))
            raise
        except Exception as exc:
            self.store.retry(ticket, owner, uncertain=True, code='return_ack_uncertain')
            raise Deferred('return_ack_uncertain') from exc
        return {'status': 'submitted'}
