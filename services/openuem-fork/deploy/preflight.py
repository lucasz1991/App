#!/usr/bin/env python3
"""Fixed-topology diagnostics; no explicit start/config writes or secrets output.

Status TCP probes can activate the already configured local socket proxy.
"""
import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import socket
import stat
import subprocess
import sys

HERE = Path(__file__).resolve().parent
BASE = Path('/srv/railtime-openuem')
PROJECT = 'railtime-openuem'
PORTS = {'postgres': ('15432', '5432/tcp'), 'nats': ('14443', '4222/tcp')}
USERS = {'postgres': '54321:54321', 'nats': '54322:54322'}
NETWORK = 'railtime-openuem-backend'
SUBNET = '172.25.0.0/16'
GATEWAY = '172.25.0.1'
ADDRESSES = {'postgres': '172.25.0.10', 'nats': '172.25.0.11'}
MOUNTS = {
    'postgres': {
        '/var/lib/postgresql/data': ('postgres/data', True),
        '/opt/railtime/postgres-start.sh': ('config/postgres-start.sh', False),
        '/etc/railtime/postgresql.conf': ('config/postgresql.conf', False),
        '/etc/railtime/pg_hba.conf': ('config/pg_hba.conf', False),
        '/etc/passwd': ('config/postgres.passwd', False),
        '/etc/group': ('config/postgres.group', False),
        '/run/railtime-secrets/postgres-bootstrap-password': ('secrets/postgres-bootstrap-password', False),
    },
    'nats': {
        '/data': ('nats/data', True),
        '/etc/nats/nats.conf': ('config/nats.conf', False),
        '/etc/nats/certs': ('secrets/nats', False),
    },
}


def require(ok, code):
    if not ok:
        raise RuntimeError(code)


def trusted_path(path, uid=0, mode=None, directory=False):
    require(path.is_absolute(), 'absolute_path_required')
    for parent in reversed((path, *path.parents)):
        info = parent.lstat()
        require(not stat.S_ISLNK(info.st_mode), 'symlink_rejected')
        if parent != path:
            require(stat.S_ISDIR(info.st_mode), 'ancestor_not_directory')
            require(info.st_uid in (0, uid), 'untrusted_ancestor_owner')
            require(stat.S_IMODE(info.st_mode) & 0o022 == 0, 'writable_ancestor_rejected')
        else:
            require(stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode), 'wrong_file_type')
            require(directory or info.st_nlink == 1, 'hardlink_rejected')
            require(info.st_uid == uid, 'unexpected_owner')
            require(mode is None or stat.S_IMODE(info.st_mode) in mode, 'unsafe_mode')
    return path.stat()


def same_config(installed, source):
    trusted_path(installed, mode=(0o644,))
    # Public baseline configuration contains paths/policies, never credentials.
    require(hashlib.sha256(installed.read_bytes()).digest() == hashlib.sha256(source.read_bytes()).digest(), 'configuration_diff_requires_review')


def invoke(args):
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20, check=False)
    require(result.returncode == 0, 'command_failed_or_object_absent')
    return result.stdout


def docker_json(*args):
    return json.loads(invoke(['docker', *args]))


def validate_compose(config, lock):
    require(config.get('name') == PROJECT, 'compose_project_mismatch')
    require(set(config.get('services', {})) == set(PORTS), 'unexpected_compose_service')
    networks = config.get('networks', {})
    require(set(networks) == {'backend'}, 'unexpected_compose_network')
    network = networks['backend']
    require(network.get('internal') is True and network.get('driver') == 'bridge'
            and network.get('name') == NETWORK and not network.get('enable_ipv6'), 'network_not_isolated_ipv4_bridge')
    require(network.get('ipam', {}).get('config') == [{'subnet': SUBNET, 'gateway': GATEWAY}], 'compose_ipam_mismatch')
    for service, (host_port, container_port) in PORTS.items():
        item = config['services'][service]
        require(item.get('image') == lock[service]['image'], 'compose_image_not_pinned')
        require(item.get('platform') == 'linux/amd64', 'compose_platform_mismatch')
        require(item.get('user') == USERS[service], 'compose_privileged_user')
        require(item.get('read_only') is True and not item.get('privileged'), 'compose_writable_or_privileged')
        require('ALL' in item.get('cap_drop', []), 'compose_capabilities_not_dropped')
        require('no-new-privileges:true' in item.get('security_opt', []), 'compose_privilege_escalation_allowed')
        require(not item.get('environment') and not item.get('env_file'), 'environment_settings_rejected')
        require(not item.get('network_mode') and not item.get('pid') and not item.get('devices'), 'unsafe_namespace_or_device')
        require(not item.get('ports'), 'docker_port_publication_forbidden')
        attached = item.get('networks', {})
        require(set(attached) == {'backend'} and attached['backend'].get('ipv4_address') == ADDRESSES[service], 'compose_static_ip_mismatch')
        seen = set()
        for mount in item.get('volumes', []):
            require(mount.get('type') == 'bind' and str(mount.get('source', '')).startswith(BASE.as_posix() + '/'), 'mount_outside_scope')
            # Compose's canonical JSON omits a false boolean; true is unsafe.
            require(not mount.get('bind', {}).get('create_host_path', False), 'mount_would_create_path')
            target = mount.get('target')
            require(target in MOUNTS[service] and target not in seen, 'unexpected_mount_target')
            source, writable = MOUNTS[service][target]
            require(mount['source'] == (BASE / source).as_posix() and bool(mount.get('read_only')) == (not writable), 'mount_access_or_source_mismatch')
            seen.add(target)
        require(seen == set(MOUNTS[service]), 'missing_mount')
        labels = item.get('labels', {})
        require(labels.get('io.railtime.component') == 'openuem-' + service, 'component_label_mismatch')


def validate_image(image, reference):
    require(image.get('Os') == 'linux' and image.get('Architecture') == 'amd64', 'image_platform_mismatch')
    expected = reference.split('@', 1)[1]
    require(any(v.endswith('@' + expected) for v in image.get('RepoDigests', [])), 'image_digest_mismatch')
    return image['Id']


def validate_container(item, service, image_id, reference):
    require(item.get('Name') == '/railtime-openuem-' + service, 'container_name_mismatch')
    labels = item.get('Config', {}).get('Labels', {})
    require(labels.get('io.railtime.component') == 'openuem-' + service
            and labels.get('com.docker.compose.project') == PROJECT, 'container_labels_mismatch')
    require(item.get('Image') == image_id and item.get('Config', {}).get('Image') == reference, 'container_image_drift')
    require(item.get('Config', {}).get('User') == USERS[service], 'container_user_mismatch')
    host = item.get('HostConfig', {})
    require(host.get('ReadonlyRootfs') is True and not host.get('Privileged'), 'container_privileged_or_writable')
    require('ALL' in host.get('CapDrop', []), 'container_capabilities_not_dropped')
    require('no-new-privileges:true' in host.get('SecurityOpt', []), 'container_privilege_escalation_allowed')
    require(not host.get('PortBindings'), 'container_requested_port_publication_forbidden')
    runtime = item.get('NetworkSettings', {})
    require(not any(runtime.get('Ports', {}).values()), 'container_actual_port_publication_forbidden')
    attached = runtime.get('Networks', {})
    require(set(attached) == {NETWORK}, 'container_network_membership_drift')
    endpoint = attached[NETWORK]
    require(endpoint.get('IPAddress') == ADDRESSES[service] and endpoint.get('IPPrefixLen') == 16
            and endpoint.get('IPAMConfig', {}).get('IPv4Address') == ADDRESSES[service], 'container_static_ip_drift')
    require(item.get('State', {}).get('Running') is True, 'container_not_running')
    seen = set()
    for mount in item.get('Mounts', []):
        if mount.get('Type') == 'tmpfs':
            require(mount.get('Destination') in ('/tmp', '/run/postgresql'), 'unexpected_tmpfs_target')
            continue
        require(mount.get('Type') == 'bind', 'container_mount_outside_scope')
        target = mount.get('Destination')
        require(target in MOUNTS[service] and target not in seen, 'container_mount_target_mismatch')
        source, writable = MOUNTS[service][target]
        require(mount.get('Source') == (BASE / source).as_posix() and mount.get('RW') is writable, 'container_mount_access_or_source_mismatch')
        seen.add(target)
    require(seen == set(MOUNTS[service]), 'container_missing_mount')


def validate_network(network):
    require(network.get('Name') == NETWORK and network.get('Driver') == 'bridge'
            and network.get('Internal') is True and not network.get('EnableIPv6'), 'actual_network_isolation_drift')
    require(network.get('Labels', {}).get('com.docker.compose.project') == PROJECT, 'actual_network_project_drift')
    configs = network.get('IPAM', {}).get('Config', [])
    require(len(configs) == 1 and configs[0].get('Subnet') == SUBNET and configs[0].get('Gateway') == GATEWAY
            and not configs[0].get('IPRange') and not configs[0].get('AuxiliaryAddresses'), 'actual_network_ipam_drift')
    options = network.get('Options', {})
    require(options.get('com.docker.network.bridge.gateway_mode_ipv4', 'nat') == 'nat'
            and options.get('com.docker.network.bridge.inhibit_ipv4', 'false') == 'false', 'actual_network_host_access_drift')
    allowed = {'railtime-openuem-' + name: address + '/16' for name, address in ADDRESSES.items()}
    for container in network.get('Containers', {}).values():
        require(container.get('Name') in allowed and container.get('IPv4Address') == allowed[container['Name']], 'unexpected_network_peer_or_address')


def parse_listeners(raw, ipv6=False):
    found = []
    for line in raw.splitlines()[1:]:
        columns = line.split()
        if len(columns) < 4 or columns[3] != '0A':
            continue
        address, port = columns[1].split(':')
        data = bytes.fromhex(address)
        if ipv6:
            data = b''.join(data[n:n + 4][::-1] for n in range(0, 16, 4))
            host = str(ipaddress.IPv6Address(data))
        else:
            host = str(ipaddress.IPv4Address(data[::-1]))
        found.append((host, int(port, 16)))
    return found


def validate_loopback_listener(listeners, port):
    matching = [address for address, number in listeners if number == port]
    require(matching == ['127.0.0.1'], 'missing_duplicate_or_nonloopback_host_listener')


def loopback_listener(port):
    listeners = parse_listeners(Path('/proc/net/tcp').read_text())
    ipv6 = Path('/proc/net/tcp6')
    if ipv6.exists():
        listeners.extend(parse_listeners(ipv6.read_text(), True))
    validate_loopback_listener(listeners, port)


def proxy_status(service):
    base = 'railtime-openuem-' + service + '-proxy'
    for suffix in ('.socket', '.service'):
        same_config(Path('/etc/systemd/system') / (base + suffix), HERE / (base + suffix))
        require(not invoke(['systemctl', 'show', base + suffix, '--property=DropInPaths', '--value']).strip(), 'unreviewed_proxy_unit_dropin')
        require(invoke(['systemctl', 'show', base + suffix, '--property=NeedDaemonReload', '--value']).strip() == b'no', 'proxy_unit_reload_required')
    require(invoke(['systemctl', 'is-active', base + '.socket']).strip() == b'active', 'proxy_socket_not_active')


def tcp_transport(service):
    # A successful front-door connect alone proves only socket activation. Also
    # check the actual backend TCP listener; protocol/auth remain separate gates.
    port, destination = PORTS[service]
    for endpoint in (('127.0.0.1', int(port)), (ADDRESSES[service], int(destination.split('/')[0]))):
        with socket.create_connection(endpoint, timeout=3):
            pass


def postgres_major():
    trusted_path(BASE / 'postgres/data/PG_VERSION', 54321, (0o600,))
    require((BASE / 'postgres/data/PG_VERSION').read_text().strip() == '17', 'postgres_major_mismatch')


def free_port(port):
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
        probe.bind(('127.0.0.1', port))


def run(phase):
    results = []
    def check(name, operation):
        try:
            operation()
            results.append({'check': name, 'status': 'pass'})
        except Exception as error:
            # Never echo command stderr, configuration contents, certificate data,
            # DSNs or arbitrary exception strings from external tooling.
            code = str(error) if isinstance(error, RuntimeError) else type(error).__name__
            results.append({'check': name, 'status': 'blocked', 'code': code})
    lock = json.loads((HERE / 'images.lock.json').read_text())
    check('linux_root', lambda: require(sys.platform.startswith('linux') and os.geteuid() == 0, 'linux_root_required'))
    check('runtime_root', lambda: trusted_path(BASE, mode=(0o700, 0o711, 0o755), directory=True))
    for source, installed in (('postgres-start.sh', 'postgres-start.sh'), ('postgresql.conf', 'postgresql.conf'), ('pg_hba.conf', 'pg_hba.conf'), ('postgres.passwd', 'postgres.passwd'), ('postgres.group', 'postgres.group')):
        check('config_' + source, lambda s=source, d=installed: same_config(BASE / 'config' / d, HERE / s))
    check('postgres_data', lambda: trusted_path(BASE / 'postgres/data', 54321, (0o700,), True))
    if phase == 'postgres-init':
        check('postgres_empty', lambda: require(not any((BASE / 'postgres/data').iterdir()), 'nonempty_database_init_refused'))
        check('bootstrap_password_file', lambda: trusted_path(BASE / 'secrets/postgres-bootstrap-password', 54321, (0o400, 0o600)))
        check('postgres_port_available', lambda: free_port(15432))
    else:
        check('postgres_major17', postgres_major)
        check('nats_data', lambda: trusted_path(BASE / 'nats/data', 54322, (0o700,), True))
        check('config_nats_baseline', lambda: same_config(BASE / 'config/nats.conf', HERE / 'nats.conf.example'))
        check('nats_secret_directory', lambda: trusted_path(BASE / 'secrets/nats', 54322, (0o700,), True))
        for name in ('ca.crt', 'server.crt', 'server.key'):
            check('nats_' + name, lambda n=name: trusted_path(BASE / 'secrets/nats' / n, 54322, (0o400, 0o600)))
    check('compose_contract', lambda: validate_compose(docker_json('compose', '-f', str(HERE / 'compose.yaml'), 'config', '--format', 'json'), lock))
    if phase == 'status':
        check('actual_private_network', lambda: validate_network(docker_json('network', 'inspect', NETWORK)[0]))
    services = ('postgres',) if phase == 'postgres-init' else tuple(PORTS)
    for service in services:
        reference = lock[service]['image']
        image_id = [None]
        def image_check(s=service, ref=reference, box=image_id):
            box[0] = validate_image(docker_json('image', 'inspect', ref)[0], ref)
        check(service + '_pinned_image_present', image_check)
        if phase == 'status':
            check(service + '_container_contract', lambda s=service, ref=reference, box=image_id:
                  validate_container(docker_json('container', 'inspect', 'railtime-openuem-' + s)[0], s, box[0], ref))
            check(service + '_proxy_socket_contract', lambda s=service: proxy_status(s))
            check(service + '_exact_loopback_listener', lambda s=service: loopback_listener(int(PORTS[s][0])))
            check(service + '_front_and_backend_tcp', lambda s=service: tcp_transport(s))
    return {'phase': phase, 'status': 'pass' if all(x['status'] == 'pass' for x in results) else 'blocked',
            'checks': results, 'secrets_generated': False, 'service_configuration_changed': False,
            'explicit_service_start': False, 'may_activate_local_proxy': phase == 'status',
            'boundary': 'Filesystem/image/network/container/socket configuration and TCP availability only; no DB login, authenticated broker, worker or device acceptance.'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase', choices=('postgres-init', 'start', 'status'), default='start')
    args = parser.parse_args()
    output = run(args.phase)
    print(json.dumps(output, sort_keys=True))
    sys.exit(0 if output['status'] == 'pass' else 1)
