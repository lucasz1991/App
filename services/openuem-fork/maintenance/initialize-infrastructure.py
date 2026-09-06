#!/usr/bin/env python3
"""Explicit new-only private infrastructure preparation; never touches devices.

Run on the reviewed Plesk Linux host as root, after reviewing deploy/README.md.
This creates NEW local machine credentials in /srv only. It does not start a
container, create a database role, enroll a device, or change RailTime settings.
Never print generated configuration, private keys, SQL or credential files.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import secrets
import shutil
import stat
import subprocess
import sys
import grp

BASE = Path('/srv/railtime-openuem')
WORKER_USER = 'railtime-openuem'
PG_UID = 54321
NATS_UID = 54322
WORKER_UID = 54323
CONFIG_NAMES = {'postgres-start.sh': 'postgres-start.sh', 'postgresql.conf': 'postgresql.conf',
                'pg_hba.conf': 'pg_hba.conf', 'nats.conf.example': 'nats.conf',
                'compose.yaml': 'compose.yaml', 'images.lock.json': 'images.lock.json',
                'postgres.passwd': 'postgres.passwd', 'postgres.group': 'postgres.group',
                'railtime-openuem-worker.service': 'railtime-openuem-worker.service',
                'railtime-openuem-postgres-proxy.socket': 'railtime-openuem-postgres-proxy.socket',
                'railtime-openuem-postgres-proxy.service': 'railtime-openuem-postgres-proxy.service',
                'railtime-openuem-nats-proxy.socket': 'railtime-openuem-nats-proxy.socket',
                'railtime-openuem-nats-proxy.service': 'railtime-openuem-nats-proxy.service'}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def trusted(path, *, owner=0, private=False):
    for item in [path, *path.parents]:
        info = item.lstat()
        require(not stat.S_ISLNK(info.st_mode), 'symlink path refused')
        require(info.st_uid in (0, owner) and not info.st_mode & 0o022, 'untrusted path refused')
        if item == path:
            require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, 'regular single-link file required')
            if private:
                require(not info.st_mode & 0o077, 'private file permissions required')


def mkdir(path, mode=0o700, uid=0, gid=0):
    path.mkdir(mode=mode)
    os.chown(path, uid, gid)
    path.chmod(mode)


def write_new(path, data, *, uid=0, gid=0, mode=0o600):
    payload = data.encode() if isinstance(data, str) else data
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'wb') as file:
        file.write(payload)
        file.flush()
        os.fsync(file.fileno())
        os.fchown(file.fileno(), uid, gid)
        os.fchmod(file.fileno(), mode)


def command(arguments):
    # Child stderr may contain native connection details: no raw output in logs.
    result = subprocess.run(arguments, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            check=False, timeout=120)
    require(result.returncode == 0, 'infrastructure preparation command failed; inspect privately')
    return result.stdout


def prepare(source):
    require(not BASE.exists() and not BASE.is_symlink(), 'existing runtime refused; no overwrite or implicit repair')
    require(Path('/srv').resolve() == Path('/srv'), 'unexpected /srv target')
    info = Path('/srv').stat()
    require(info.st_uid == 0 and not info.st_mode & 0o022, '/srv must be root-owned and protected')
    try:
        pwd.getpwnam(WORKER_USER)
    except KeyError:
        pass
    else:
        raise RuntimeError('existing worker account refused; investigate prior installation')
    # Root must first stage the reviewed source in a root-controlled directory.
    source = source.absolute()
    payloads = {}
    for original, target in CONFIG_NAMES.items():
        trusted(source / original)
        payloads[target] = (source / original).read_bytes()
    require(all(b'\r' not in data for data in payloads.values()), 'deployment files require LF newlines')
    require(shutil.which('openssl') and shutil.which('useradd') and shutil.which('groupadd'), 'openssl and account tools required')
    # Container numeric ownership must not grant an existing host account read
    # access to these secrets (Plesk commonly uses UID999 and UID10001 itself).
    for account_id in (PG_UID, NATS_UID, WORKER_UID):
        for lookup in (pwd.getpwuid, grp.getgrgid):
            try:
                lookup(account_id)
            except KeyError:
                continue
            raise RuntimeError('dedicated service UID or GID is already occupied')
    try:
        grp.getgrnam(WORKER_USER)
    except KeyError:
        pass
    else:
        raise RuntimeError('existing worker group refused')
    mkdir(BASE, 0o711)
    command(['groupadd', '--gid', str(WORKER_UID), WORKER_USER])
    command(['useradd', '--system', '--uid', str(WORKER_UID), '--gid', str(WORKER_UID), '--no-create-home', '--home-dir',
             str(BASE / 'worker'), '--shell', '/usr/sbin/nologin', WORKER_USER])
    user = pwd.getpwnam(WORKER_USER)
    require(user.pw_uid == WORKER_UID and user.pw_gid == WORKER_UID, 'worker identity mismatch')
    for name in ('postgres', 'nats', 'secrets'):
        mkdir(BASE / name, 0o711)
    mkdir(BASE / 'config', 0o755)
    mkdir(BASE / 'bin', 0o755)
    mkdir(BASE / 'postgres/data', uid=PG_UID, gid=PG_UID)
    mkdir(BASE / 'nats/data', uid=NATS_UID, gid=NATS_UID)
    mkdir(BASE / 'secrets/nats', uid=NATS_UID, gid=NATS_UID)
    mkdir(BASE / 'secrets/ca')
    mkdir(BASE / 'worker', uid=user.pw_uid, gid=user.pw_gid)
    for name, data in payloads.items():
        write_new(BASE / 'config' / name, data, mode=0o644)

    bootstrap_password, runtime_password = secrets.token_hex(32), secrets.token_hex(32)
    write_new(BASE / 'secrets/postgres-bootstrap-password', bootstrap_password+'\n', uid=PG_UID, gid=PG_UID)
    # Never interpolate operator-entered SQL. Both identifiers are fixed and the
    # only secret interpolated here is newly generated hex data, not input text.
    write_new(BASE / 'secrets/create-runtime-database.sql',
              "CREATE ROLE openuem LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '"
              + runtime_password + "';\nCREATE DATABASE openuem OWNER openuem;\n"
              + "REVOKE CONNECT ON DATABASE openuem FROM PUBLIC;\n")
    database_url = 'postgres://openuem:'+runtime_password+'@127.0.0.1:15432/openuem?sslmode=disable'
    write_new(BASE / 'worker/bootstrap.json', json.dumps({'database_url': database_url,
              'tenant_name': 'RailTime', 'site_name': 'RailTime Devices',
              'site_domain': 'app.rail-time.de'})+'\n', uid=user.pw_uid, gid=user.pw_gid)
    ca = BASE / 'secrets/ca'
    command(['openssl', 'genrsa', '-traditional', '-out', str(ca/'ca.key'), '3072'])
    command(['openssl', 'req', '-x509', '-key', str(ca/'ca.key'), '-sha256',
             '-days', '3650', '-out', str(ca/'ca.crt'),
             '-subj', '/CN=RailTime OpenUEM Internal CA', '-addext', 'basicConstraints=critical,CA:TRUE',
             '-addext', 'keyUsage=critical,keyCertSign,cRLSign'])
    for name, subject, san, usage in (
        ('server', 'RailTime OpenUEM NATS', 'IP:127.0.0.1', 'serverAuth'),
        ('worker', 'RailTime OpenUEM Worker', 'DNS:railtime-openuem-worker', 'clientAuth'),
    ):
        # The pinned native OpenUEM utility explicitly requires PKCS#1 PEM,
        # not OpenSSL3's default PKCS#8 "PRIVATE KEY" output.
        command(['openssl', 'genrsa', '-traditional', '-out', str(ca/(name+'.key')), '3072'])
        command(['openssl', 'req', '-new', '-key', str(ca/(name+'.key')),
                 '-out', str(ca/(name+'.csr')), '-subj', '/CN='+subject])
        write_new(ca/(name+'.ext'), 'basicConstraints=critical,CA:FALSE\n'
                  'keyUsage=critical,digitalSignature,keyEncipherment\n'
                  'extendedKeyUsage='+usage+'\nsubjectAltName='+san+'\n')
        command(['openssl', 'x509', '-req', '-in', str(ca/(name+'.csr')), '-CA', str(ca/'ca.crt'),
                 '-CAkey', str(ca/'ca.key'), '-set_serial', hex(secrets.randbits(159) or 1), '-days',
                 '365', '-sha256', '-extfile', str(ca/(name+'.ext')), '-out', str(ca/(name+'.crt'))])
        command(['openssl', 'verify', '-CAfile', str(ca/'ca.crt'), '-purpose',
                 'sslserver' if name == 'server' else 'sslclient', str(ca/(name+'.crt'))])
    for name in ('ca.crt', 'server.crt', 'server.key'):
        write_new(BASE/'secrets/nats'/name, (ca/name).read_bytes(), uid=NATS_UID, gid=NATS_UID)
    for source_name, target_name in (('ca.crt', 'ca.crt'), ('worker.crt', 'client.crt'), ('worker.key', 'client.key')):
        write_new(BASE/'worker'/target_name, (ca/source_name).read_bytes(), uid=user.pw_uid, gid=user.pw_gid)
    write_new(BASE/'secrets/health-bearer', secrets.token_hex(32)+'\n')
    write_new(BASE/'prepared.json', json.dumps({'format': 1, 'worker_uid': user.pw_uid,
              'config_sha256': {k: hashlib.sha256(v).hexdigest() for k, v in payloads.items()},
              'ca_sha256': hashlib.sha256((ca/'ca.crt').read_bytes()).hexdigest()})+'\n')
    print(json.dumps({'prepared': True, 'database_started': False, 'broker_started': False,
                      'worker_started': False, 'devices_enrolled': False}))


def finalize(tenant_id, site_id):
    require(tenant_id > 0 and site_id > 0, 'real bootstrap tenant/site IDs required')
    user = pwd.getpwnam(WORKER_USER)
    trusted(BASE/'prepared.json', private=True)
    trusted(BASE/'worker/bootstrap.json', owner=user.pw_uid, private=True)
    trusted(BASE/'secrets/health-bearer', private=True)
    bootstrap = json.loads((BASE/'worker/bootstrap.json').read_text())
    bearer = (BASE/'secrets/health-bearer').read_text().strip()
    config = {'enabled': True, 'provisioning_only': True, 'listen': '127.0.0.1:9441',
              'command_ttl_seconds': 900, 'device_keys': [],
              'principals': [{'id': 'railtime-health', 'bearer_sha256': hashlib.sha256(bearer.encode()).hexdigest(),
                              'tenant_id': tenant_id, 'site_id': site_id, 'agent_ids': [], 'profile_ids': []}],
              'native': {'database_url': bootstrap['database_url'], 'nats_servers': 'tls://127.0.0.1:14443',
                         'ca_certificate': str(BASE/'worker/ca.crt'), 'client_certificate': str(BASE/'worker/client.crt'),
                         'client_private_key': str(BASE/'worker/client.key')}}
    write_new(BASE/'worker/railtime.json', json.dumps(config)+'\n', uid=user.pw_uid, gid=user.pw_gid)
    print(json.dumps({'configured': True, 'provisioning_only': True, 'device_commands_enabled': False}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='action', required=True)
    start = sub.add_parser('prepare-new')
    start.add_argument('--reviewed-deploy-dir', type=Path, required=True)
    start.add_argument('--create-private-machine-credentials', action='store_true', required=True)
    end = sub.add_parser('finalize-infrastructure-only')
    end.add_argument('--tenant-id', type=int, required=True)
    end.add_argument('--site-id', type=int, required=True)
    args = parser.parse_args()
    require(sys.platform == 'linux' and os.geteuid() == 0, 'reviewed Linux root operator required')
    os.umask(0o077)
    if args.action == 'prepare-new':
        prepare(args.reviewed_deploy_dir)
    else:
        finalize(args.tenant_id, args.site_id)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Preserve partial private runtime for operator inspection; never erase
        # keys or reset an initialized DB in an automatic error handler.
        print('Preparation stopped; private partial state preserved. Inspect securely, do not retry or erase blindly.', file=sys.stderr)
        if isinstance(error, RuntimeError):
            print(str(error), file=sys.stderr)
        sys.exit(1)
