#!/usr/bin/env python3
"""Bounded local infrastructure acceptance. Never executes a device command.

Credentials stay in protected files and process memory. No headers, DSNs,
certificate contents or raw exception messages are printed. The POST probe runs
only after the authenticated API proves provisioning-only mode with no capability.
"""
import http.client
import json
import os
from pathlib import Path
import socket
import ssl
import stat
import subprocess
import sys
import time

BASE = Path('/srv/railtime-openuem')


def require(ok, code):
    if not ok:
        raise RuntimeError(code)


def private_file(path, owner):
    for item in (path, *path.parents):
        info = item.lstat()
        require(not stat.S_ISLNK(info.st_mode), 'symlink_refused')
        require(info.st_uid in (0, owner) and not info.st_mode & 0o022, 'untrusted_path')
        if item == path:
            require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == owner
                    and not info.st_mode & 0o077, 'private_file_required')


def request(method, path, token=None):
    conn = http.client.HTTPConnection('127.0.0.1', 9441, timeout=10)
    headers = {} if token is None else {'Authorization': 'Bearer ' + token}
    try:
        conn.request(method, path, body=b'{}' if method == 'POST' else None, headers=headers)
        response = conn.getresponse()
        raw = response.read(8193)
        require(len(raw) <= 8192, 'oversized_response')
        return response.status, json.loads(raw)
    finally:
        conn.close()


def check_health(status, body):
    require(type(body) is dict and all(type(body.get(field)) is bool for field in
            ('ready', 'storage_ready', 'broker_ready', 'provisioning_only')),
            'strict_boolean_health_fields_required')
    require(status == 503 and body == {
        'protocol': 'railtime.execution.v1', 'ready': False,
        'storage_ready': True, 'broker_ready': True,
        'provisioning_only': True, 'capabilities': [],
    }, 'infrastructure_only_health_contract_failed')


def broker_accepts(client_certificate):
    deadline = time.monotonic() + 12
    def remaining():
        value = deadline - time.monotonic()
        require(value > 0, 'broker_probe_deadline_exceeded')
        return min(value, 5)
    context = ssl.create_default_context(cafile=str(BASE / 'worker/ca.crt'))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    if client_certificate:
        context.load_cert_chain(str(BASE / 'worker/client.crt'), str(BASE / 'worker/client.key'))
    with socket.create_connection(('127.0.0.1', 14443), timeout=5) as raw:
        info = b''
        while not info.endswith(b'\r\n') and len(info) <= 8192:
            raw.settimeout(remaining())
            value = raw.recv(1)
            require(value, 'broker_closed_before_info')
            info += value
        require(info.startswith(b'INFO ') and len(info) <= 8192, 'broker_info_missing')
        details = json.loads(info[5:])
        require(details.get('tls_required') is True, 'broker_tls_not_required')
        raw.settimeout(remaining())
        with context.wrap_socket(raw, server_hostname='127.0.0.1') as secured:
            secured.settimeout(remaining())
            secured.sendall(b'CONNECT {"verbose":true,"pedantic":true,"tls_required":true,"name":"railtime-infrastructure-check"}\r\nPING\r\n')
            reply = b''
            while len(reply) <= 8192:
                secured.settimeout(remaining())
                chunk = secured.recv(1024)
                if not chunk:
                    return False
                reply += chunk
                if b'-ERR' in reply:
                    return False
                if b'PONG\r\n' in reply:
                    return True
            return False


def database_counts():
    query = "SELECT json_build_object('tenants',(SELECT count(*) FROM tenants),'sites',(SELECT count(*) FROM sites),'agents',(SELECT count(*) FROM agents),'profiles',(SELECT count(*) FROM profiles),'runs',(SELECT count(*) FROM railtime_execution_runs),'outbox',(SELECT count(*) FROM railtime_execution_outbox),'results',(SELECT count(*) FROM railtime_execution_results))"
    result = subprocess.run(['docker', 'exec', '--user', '54321:54321',
        'railtime-openuem-postgres', '/usr/lib/postgresql/17/bin/psql', '--no-psqlrc',
        '-h', '/run/postgresql', '-U', 'postgres', '-d', 'openuem', '-Atc', query],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15, check=False)
    require(result.returncode == 0, 'database_count_probe_failed')
    value = json.loads(result.stdout)
    require(value == {'tenants': 1, 'sites': 1, 'agents': 0, 'profiles': 0,
                      'runs': 0, 'outbox': 0, 'results': 0}, 'unexpected_database_state')
    return value


def run():
    require(sys.platform == 'linux' and os.geteuid() == 0, 'linux_root_required')
    private_file(BASE / 'secrets/health-bearer', 0)
    for name in ('ca.crt', 'client.crt', 'client.key'):
        private_file(BASE / 'worker' / name, 54323)
    token = (BASE / 'secrets/health-bearer').read_text().strip()
    require(len(token) == 64 and all(c in '0123456789abcdef' for c in token), 'invalid_private_bearer')
    check_health(*request('GET', '/railtime/v1/health', token))
    before = database_counts()
    require(request('GET', '/railtime/v1/health') == (401, {'error': 'unauthorized'}), 'anonymous_request_not_denied')
    require(request('GET', '/railtime/v1/health', 'invalid-infrastructure-probe') == (401, {'error': 'unauthorized'}), 'invalid_bearer_not_denied')
    require(request('POST', '/railtime/v1/runs', token) == (503, {'error': 'provisioning_only'}), 'commands_not_blocked')
    require(broker_accepts(True), 'authorized_broker_probe_failed')
    try:
        anonymous = broker_accepts(False)
    except ssl.SSLError:
        anonymous = False
    require(not anonymous, 'broker_without_client_certificate_accepted')
    after = database_counts()
    require(before == after, 'database_counts_changed')
    return {'status': 'pass', 'authenticated_health_http': 503, 'storage_ready': True,
            'broker_ready': True, 'provisioning_only': True, 'anonymous_api_denied': True,
            'invalid_bearer_denied': True, 'command_submission_denied': True,
            'mtls_client_accepted': True, 'no_client_certificate_denied': True,
            'database_counts_unchanged': after, 'device_execution_tested': False}


if __name__ == '__main__':
    try:
        print(json.dumps(run(), sort_keys=True))
    except Exception as error:
        code = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        print(json.dumps({'status': 'failed', 'code': code, 'device_execution_tested': False}))
        sys.exit(1)
