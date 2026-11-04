"""Disabled-by-default E3 daemon/pipe entry point. No action occurs on import.

Every operational action requires a separately approved root-owned activation
receipt bound to exact private configuration bytes. The example has neither.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import smtplib
import socketserver
import stat
import struct
import subprocess
import sys
import time
import threading

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from railtime_transit import HtmlResult, PILOT, MAX_MESSAGE_BYTES
from railtime_e3 import (Admission, Deferred, E3Error, Envelope, PilotPolicy,
                        PipeWorker, ReinjectFailure, TicketStore, digest)
from railtime_e3_protocol import MilterSession, TEMPFAIL, policy_request


def read_private(path: Path, maximum: int, *, root_owned: bool = False) -> bytes:
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > maximum:
        raise Deferred('private_file_invalid')
    if os.name != 'nt' and (info.st_mode & 0o037 or info.st_uid not in ({0} if root_owned else {0, os.geteuid()})):
        raise Deferred('private_file_permissions')
    descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
    with os.fdopen(descriptor, 'rb') as stream:
        data = stream.read(maximum + 1)
    if len(data) > maximum:
        raise Deferred('private_file_budget')
    return data


def runtime_window(receipt: dict, config_hash: str, now: float, *, drain: bool) -> float:
    if (receipt.get('schema') != 1 or receipt.get('scope') != 'IT-only'
            or receipt.get('approvedConfigSha256') != config_hash
            or not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', str(receipt.get('approvalId', '')))
            or receipt.get('approvalId') == '00000000-0000-0000-0000-000000000000'
            or any(type(receipt.get(key)) not in (int, float) for key in ('created', 'admissionUntil', 'drainUntil'))
            or not receipt['created'] <= now
            or not receipt['created'] < receipt['admissionUntil'] <= receipt['created'] + 86400
            or not receipt['admissionUntil'] + 7 * 86400 <= receipt['drainUntil'] <= receipt['created'] + 8 * 86400
            or now >= receipt['drainUntil' if drain else 'admissionUntil']):
        raise Deferred('separate_activation_approval_required')
    return receipt['drainUntil' if drain else 'admissionUntil']


def load_runtime(config_path: Path, activation_path: Path, *, drain: bool = False) -> tuple[dict, PilotPolicy, TicketStore, Admission]:
    if os.name == 'nt' or os.geteuid() == 0:
        raise Deferred('unprivileged_linux_runtime_required')
    raw = read_private(config_path, 65536, root_owned=True)
    config = json.loads(raw)
    receipt = json.loads(read_private(activation_path, 4096, root_owned=True))
    if config.get('schema') != 1 or config.get('enabled') is not True:
        raise Deferred('separate_activation_approval_required')
    runtime_window(receipt, digest(raw), time.time(), drain=drain)
    if config.get('returnHost') != '127.0.0.1' or config.get('returnPort') != 10027 or config.get('returnMx') != 'railtime-de01b.mail.protection.outlook.com':
        raise Deferred('fixed_return_route_required')
    observed = dt.datetime.fromisoformat(config['networkObservedAtUtc'].replace('Z', '+00:00')).timestamp()
    if not drain and not 0 <= time.time() - observed <= 7 * 86400:
        raise Deferred('peer_network_snapshot_stale')
    policy = PilotPolicy(config['tenantId'], config['userId'], config['approvedTemplateHeader'], tuple(config['microsoftNetworks']))
    key = read_private(Path(config['ticketKeyPath']), 128).strip()
    secret = None if drain else read_private(Path(config['ingressSecretPath']), 128).strip()
    if not re.fullmatch(rb'[0-9a-f]{64}', key):
        raise Deferred('ticket_key_invalid')
    store = TicketStore(Path(config['spoolRoot']), bytes.fromhex(key.decode('ascii')))
    resolver_command = config['resolverCommand']
    transform_command = config['transformCommand']
    for command in (resolver_command, transform_command):
        if (not isinstance(command, list) or not command or len(command) > 8
                or any(not isinstance(s, str) or not s or any(c in s for c in '\r\n\x00') for s in command)
                or not Path(command[0]).is_absolute()):
            raise Deferred('fixed_local_command_required')

    def resolver(request):
        return local_json(resolver_command, request)
    return config, policy, store, Admission(policy, store, secret, resolver)


def local_json(command: list[str], request: dict) -> dict:
    try:
        result = subprocess.run(command, input=json.dumps(request).encode('utf-8'), stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=20, check=False, shell=False,
                                env={k: v for k, v in os.environ.items() if k in {'PATH', 'LANG', 'LC_ALL'}})
        if result.returncode != 0 or len(result.stdout) > 3_000_000:
            raise Deferred('local_callback_failed')
        value = json.loads(result.stdout)
        if not isinstance(value, dict) or value.get('schema') != 1:
            raise Deferred('local_callback_contract')
        return value
    except (OSError, ValueError, subprocess.TimeoutExpired) as exc:
        raise Deferred('local_callback_failed') from exc


def smtp_reinject(message: bytes, envelope: Envelope, token: str) -> str:
    """Local auth ticket is checked/removed by the return Milter BEFORE250.

    The return listener's queued transport is fixed to the tenant MX; this
    process never selects an external recipient MX or accepts header RCPTs.
    """
    sending_data = False
    try:
        if not message.endswith(b'\r\n'):
            # smtplib would append CRLF and defeat the return-ticket byte binding.
            raise ReinjectFailure(uncertain=False)
        with smtplib.SMTP('127.0.0.1', 10027, timeout=25) as client:
            client.ehlo('railtime-transit.local')
            header = message.split(b'\r\n\r\n', 1)[0]
            options = []
            if any(value > 127 for value in message):
                if not client.has_extn('8bitmime'):
                    raise ReinjectFailure(uncertain=False)
                options.append('BODY=8BITMIME')
            if any(value > 127 for value in header):
                if not client.has_extn('smtputf8'):
                    raise ReinjectFailure(uncertain=False)
                options.append('SMTPUTF8')
            code, _ = client.mail(envelope.sender, options=options)
            if code != 250:
                raise ReinjectFailure(uncertain=False)
            for recipient in envelope.recipients:
                code, _ = client.rcpt(recipient)
                if code != 250:
                    raise ReinjectFailure(uncertain=False)
            wrapped = b'X-RailTime-Transit-Context: e3r1; ticket=' + token.encode('ascii') + b'\r\n' + message
            sending_data = True
            code, response = client.data(wrapped)
            if code != 250:
                raise ReinjectFailure(uncertain=False)
            match = re.search(rb'\bqueued as ([A-Za-z0-9]{5,32})(?:\s|$)', response)
            if not match:
                raise ReinjectFailure(uncertain=True)
            return match[1].decode('ascii')
    except ReinjectFailure:
        raise
    except smtplib.SMTPDataError as exc:
        raise ReinjectFailure(uncertain=False) from exc
    except (OSError, smtplib.SMTPException) as exc:
        raise ReinjectFailure(uncertain=sending_data) from exc


if hasattr(socketserver, 'ThreadingUnixStreamServer'):
    class PrivateUnixServer(socketserver.ThreadingUnixStreamServer):
        daemon_threads = True
        allow_reuse_address = False

        def __init__(self, *args, **kwargs):
            self.slots = threading.BoundedSemaphore(8)
            super().__init__(*args, **kwargs)

        def process_request(self, request, address):
            if not self.slots.acquire(blocking=False):
                self.shutdown_request(request)
                return
            try:
                super().process_request(request, address)
            except BaseException:
                self.slots.release()
                raise

        def process_request_thread(self, request, address):
            try:
                super().process_request_thread(request, address)
            finally:
                self.slots.release()

        def handle_error(self, request, address):
            # Socketserver's default prints private stack/context. Finite only.
            sys.stderr.write('{"schema":1,"code":"E3_PROTOCOL_UNAVAILABLE"}\n')
else:
    PrivateUnixServer = None


def _read_exact(stream, length: int) -> bytes:
    output = bytearray()
    while len(output) < length:
        part = stream.read(length - len(output))
        if not part:
            raise EOFError
        output.extend(part)
    return bytes(output)


def serve(path: Path, action: str, admission: Admission, store: TicketStore, expires: float):
    if PrivateUnixServer is None:
        raise Deferred('linux_unix_socket_runtime_required')
    import grp
    parent = path.parent.lstat()
    if (path.parent != Path('/var/spool/postfix/railtime-e3') or path.name not in {'policy.sock', 'ingress.sock', 'return.sock'}
            or path.parent.is_symlink() or not stat.S_ISDIR(parent.st_mode) or parent.st_uid != os.geteuid()
            or parent.st_gid != grp.getgrnam('postfix').gr_gid or stat.S_IMODE(parent.st_mode) != 0o2750):
        raise Deferred('socket_directory_permissions')
    if path.exists() or path.is_symlink():
        raise Deferred('socket_already_exists')

    class Handler(socketserver.StreamRequestHandler):
        def handle(self):
            self.connection.settimeout(60)
            if action == 'policy':
                values = {}
                for _ in range(100):
                    line = self.rfile.readline(2049)
                    if not line or len(line) > 2048:
                        return
                    if line == b'\n':
                        response = '451 4.3.0 E3_SNAPSHOT_EXPIRED' if time.time() >= expires else policy_request(values, admission)
                        self.wfile.write(('action=' + response + '\n\n').encode('ascii'))
                        self.wfile.flush()
                        values = {}
                    else:
                        key, value = line.decode('ascii').rstrip('\n').split('=', 1)
                        if key in values:
                            return
                        values[key] = value
                return
            session = MilterSession(admission, return_store=store if action == 'return-milter' else None)
            try:
                for _ in range(10000):
                    length = struct.unpack('!I', _read_exact(self.rfile, 4))[0]
                    if not 1 <= length <= 1_000_000:
                        return
                    frame = _read_exact(self.rfile, length)
                    responses = [TEMPFAIL] if time.time() >= expires else session.handle(frame[:1], frame[1:])
                    for response in responses:
                        self.wfile.write(struct.pack('!I', len(response)) + response)
                    self.wfile.flush()
                    if frame[:1] == b'Q':
                        return
            except (EOFError, OSError, ValueError):
                return
    with PrivateUnixServer(str(path), Handler) as server:
        os.chmod(path, 0o660)
        inode = path.lstat().st_ino
        try:
            server.serve_forever()
        finally:
            if path.is_socket() and path.lstat().st_ino == inode:
                path.unlink()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['policy', 'milter', 'return-milter', 'pipe', 'inspect', 'cleanup'])
    parser.add_argument('--config', type=Path, default=Path('/etc/railtime-e3/runtime.json'))
    parser.add_argument('--activation', type=Path, default=Path('/etc/railtime-e3/activation.json'))
    parser.add_argument('--queue-id')
    parser.add_argument('--client-address')
    parser.add_argument('--sender')
    parser.add_argument('recipients', nargs='*')
    args = parser.parse_args()
    try:
        if args.action == 'inspect':
            config = json.loads(args.config.read_bytes())
            print(json.dumps({'schema': 1, 'state': 'disabled' if config.get('enabled') is not True else 'approval-required', 'activationPerformed': False}))
            return 0
        if args.action == 'cleanup':
            if os.name == 'nt' or os.geteuid() == 0:
                raise Deferred('unprivileged_linux_runtime_required')
            config = json.loads(read_private(args.config, 65536, root_owned=True))
            key = read_private(Path(config['ticketKeyPath']), 128).strip()
            if not re.fullmatch(rb'[0-9a-f]{64}', key):
                raise Deferred('ticket_key_invalid')
            result = TicketStore(Path(config['spoolRoot']), bytes.fromhex(key.decode())).purge_completed()
            print(json.dumps({'schema': 1, **result}))
            return 75 if result['requiresManualQueueReview'] else 0
        config, policy, store, admission = load_runtime(args.config, args.activation, drain=args.action in {'pipe', 'return-milter'})
        if args.action == 'pipe':
            original = sys.stdin.buffer.read(MAX_MESSAGE_BYTES + 1)
            if len(original) > MAX_MESSAGE_BYTES:
                raise Deferred('input_budget')
            envelope = Envelope(args.sender or '', tuple(args.recipients), args.queue_id or '')
            def transform(html, headers, projection, cids):
                result = local_json(config['transformCommand'], {'schema': 1, 'html': html, 'headers': headers,
                                    'projection': projection, 'cidUrls': cids, 'envelopeSender': envelope.sender})
                if type(result.get('changed')) is not bool or not isinstance(result.get('html'), str) or not isinstance(result.get('reason'), str):
                    raise Deferred('transform_contract')
                return HtmlResult(result['html'], result['changed'], result['reason'])
            outcome = PipeWorker(store, policy, transform, smtp_reinject).process(original, envelope, args.client_address or '')
            print(json.dumps({'schema': 1, 'status': outcome['status']}))
            return 0
        observed = dt.datetime.fromisoformat(config['networkObservedAtUtc'].replace('Z', '+00:00')).timestamp()
        receipt = json.loads(read_private(args.activation, 4096, root_owned=True))
        expires = receipt['drainUntil'] if args.action == 'return-milter' else min(observed + 7 * 86400, receipt['admissionUntil'])
        serve(Path(config['sockets'][args.action]), args.action, admission, store, expires)
        return 0
    except Exception:
        # Private data, callback stderr, exception text and credentials are never logged.
        print('{"schema":1,"status":"deferred","code":"E3_OPERATION_UNAVAILABLE"}', file=sys.stderr)
        return 75


if __name__ == '__main__':
    raise SystemExit(main())
