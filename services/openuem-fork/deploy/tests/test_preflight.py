import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock

HERE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('preflight', HERE / 'preflight.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class ContractTests(unittest.TestCase):
    def setUp(self):
        self.lock = json.loads((HERE / 'images.lock.json').read_text())

    def compose(self):
        obj = {'name': p.PROJECT, 'networks': {'backend': {'name': p.NETWORK, 'driver': 'bridge', 'internal': True,
                'ipam': {'config': [{'subnet': p.SUBNET, 'gateway': p.GATEWAY}]}}}, 'services': {}}
        for name, (host_port, target_port) in p.PORTS.items():
            obj['services'][name] = {
                'image': self.lock[name]['image'], 'platform': 'linux/amd64', 'user': p.USERS[name],
                'read_only': True, 'cap_drop': ['ALL'], 'security_opt': ['no-new-privileges:true'],
                'networks': {'backend': {'ipv4_address': p.ADDRESSES[name]}},
                'labels': {'io.railtime.component': 'openuem-' + name},
                'volumes': [{'type': 'bind', 'source': (p.BASE / source).as_posix(), 'target': target,
                             'read_only': not writable, 'bind': {}}
                            for target, (source, writable) in p.MOUNTS[name].items()],
            }
        return obj

    def container(self, name='postgres'):
        host_port, container_port = p.PORTS[name]
        return {'Name': '/railtime-openuem-' + name, 'Image': 'sha256:synthetic-image',
                'Config': {'Image': self.lock[name]['image'], 'User': p.USERS[name],
                           'Labels': {'io.railtime.component': 'openuem-' + name, 'com.docker.compose.project': p.PROJECT}},
                'State': {'Running': True},
                'NetworkSettings': {'Ports': {container_port: None}, 'Networks': {p.NETWORK: {
                    'IPAddress': p.ADDRESSES[name], 'IPPrefixLen': 16, 'IPAMConfig': {'IPv4Address': p.ADDRESSES[name]}}}},
                'HostConfig': {'ReadonlyRootfs': True, 'Privileged': False, 'CapDrop': ['ALL'],
                               'SecurityOpt': ['no-new-privileges:true'],
                               'PortBindings': {}},
                'Mounts': [{'Type': 'bind', 'Source': (p.BASE / source).as_posix(), 'Destination': target, 'RW': writable}
                           for target, (source, writable) in p.MOUNTS[name].items()]}

    def test_baseline_compose_contract(self):
        p.validate_compose(self.compose(), self.lock)

    def test_no_environment_settings_accepted(self):
        obj = self.compose()
        obj['services']['postgres']['environment'] = {'PASSWORD': 'synthetic'}
        with self.assertRaisesRegex(RuntimeError, 'environment'):
            p.validate_compose(obj, self.lock)

    def test_any_docker_publication_is_rejected(self):
        obj = self.compose()
        obj['services']['postgres']['ports'] = [{'host_ip': '127.0.0.1', 'target': 5432, 'published': '15432'}]
        with self.assertRaisesRegex(RuntimeError, 'publication_forbidden'):
            p.validate_compose(obj, self.lock)

    def test_compose_static_ip_and_internal_network_are_mandatory(self):
        obj = self.compose()
        obj['services']['postgres']['networks']['backend']['ipv4_address'] = '172.25.0.99'
        with self.assertRaisesRegex(RuntimeError, 'static_ip'):
            p.validate_compose(obj, self.lock)
        obj = self.compose()
        obj['networks']['backend']['internal'] = False
        with self.assertRaisesRegex(RuntimeError, 'isolated'):
            p.validate_compose(obj, self.lock)

    def test_missing_or_writable_secret_mount_rejected(self):
        obj = self.compose()
        obj['services']['postgres']['volumes'][-1]['read_only'] = False
        with self.assertRaisesRegex(RuntimeError, 'mount_access'):
            p.validate_compose(obj, self.lock)
        obj = self.compose()
        obj['services']['postgres']['volumes'].pop()
        with self.assertRaisesRegex(RuntimeError, 'missing_mount'):
            p.validate_compose(obj, self.lock)

    def test_unpinned_image_rejected(self):
        obj = self.compose()
        obj['services']['nats']['image'] = 'nats:latest'
        with self.assertRaisesRegex(RuntimeError, 'pinned'):
            p.validate_compose(obj, self.lock)

    def test_image_architecture_and_digest(self):
        ref = self.lock['postgres']['image']
        good = {'Os': 'linux', 'Architecture': 'amd64', 'Id': 'sha256:synthetic', 'RepoDigests': [ref]}
        self.assertEqual('sha256:synthetic', p.validate_image(good, ref))
        with self.assertRaises(RuntimeError):
            p.validate_image({**good, 'Architecture': 'arm64'}, ref)
        with self.assertRaises(RuntimeError):
            p.validate_image({**good, 'RepoDigests': []}, ref)

    def test_container_actual_labels_digest_ports_and_mounts(self):
        ref = self.lock['postgres']['image']
        p.validate_container(self.container(), 'postgres', 'sha256:synthetic-image', ref)
        mutations = [
            lambda x: x['Config']['Labels'].update({'com.docker.compose.project': 'other'}),
            lambda x: x.update({'Image': 'sha256:different'}),
            lambda x: x['HostConfig']['PortBindings'].update({'5432/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '15432'}]}),
            lambda x: x['NetworkSettings']['Ports'].update({'5432/tcp': [{'HostIp': '0.0.0.0', 'HostPort': '15432'}]}),
            lambda x: x['NetworkSettings']['Networks'][p.NETWORK].update({'IPAddress': '172.25.0.2'}),
            lambda x: x['Mounts'][-1].update({'RW': True}),
            lambda x: x['State'].update({'Running': False}),
        ]
        for mutation in mutations:
            obj = self.container()
            mutation(obj)
            with self.assertRaises(RuntimeError):
                p.validate_container(obj, 'postgres', 'sha256:synthetic-image', ref)

    def test_broker_config_has_no_agent_wildcard_or_empty_allow(self):
        text = (HERE / 'nats.conf.example').read_text()
        self.assertIn('verify_and_map: true', text)
        self.assertIn('user: "railtime-openuem-worker"', text)
        self.assertNotIn('allow: []', text)
        self.assertNotIn('password:', text)
        self.assertNotIn('handshake_first: true', text)
        self.assertEqual(1, text.count('user: '))

    def test_startup_no_automatic_init_or_secret_environment(self):
        text = (HERE / 'postgres-start.sh').read_text()
        self.assertNotIn('POSTGRES_PASSWORD', text)
        self.assertNotIn('set -x\n', text)
        self.assertIn('init refuses nonempty data directory', text)
        self.assertIn('explicit reviewed init is required', text)
        self.assertNotIn('\r', text)

    def test_container_uids_do_not_reuse_plesk_identities(self):
        self.assertEqual({'postgres': '54321:54321', 'nats': '54322:54322'}, p.USERS)
        self.assertEqual(['root:x:0:0:root:/root:/bin/sh',
                          'postgres:x:54321:54321:RailTime dedicated PostgreSQL:/var/lib/postgresql:/bin/sh'],
                         (HERE / 'postgres.passwd').read_text().splitlines())
        self.assertEqual(['root:x:0:', 'postgres:x:54321:'], (HERE / 'postgres.group').read_text().splitlines())
        for target in ('/etc/passwd', '/etc/group'):
            self.assertFalse(p.MOUNTS['postgres'][target][1])

    def test_actual_network_rejects_egress_wrong_subnet_or_extra_peer(self):
        baseline = {'Name': p.NETWORK, 'Driver': 'bridge', 'Internal': True, 'EnableIPv6': False,
                    'Labels': {'com.docker.compose.project': p.PROJECT},
                    'IPAM': {'Config': [{'Subnet': p.SUBNET, 'Gateway': p.GATEWAY}]},
                    'Containers': {'synthetic-id': {'Name': 'railtime-openuem-postgres', 'IPv4Address': '172.25.0.10/16'}}}
        p.validate_network(baseline)
        for mutation in (
            lambda x: x.update({'Internal': False}),
            lambda x: x['IPAM']['Config'][0].update({'Subnet': '172.26.0.0/16'}),
            lambda x: x['Containers']['synthetic-id'].update({'Name': 'other-production-service'}),
            lambda x: x.update({'Options': {'com.docker.network.bridge.gateway_mode_ipv4': 'isolated'}}),
        ):
            obj = copy.deepcopy(baseline)
            mutation(obj)
            with self.assertRaises(RuntimeError):
                p.validate_network(obj)

    def test_actual_listener_requires_only_exact_ipv4_loopback(self):
        p.validate_loopback_listener([('127.0.0.1', 15432), ('0.0.0.0', 443)], 15432)
        for listeners in ([], [('0.0.0.0', 15432)], [('::', 15432)], [('::1', 15432)],
                          [('127.0.0.1', 15432), ('0.0.0.0', 15432)], [('127.0.0.1', 15432)] * 2):
            with self.assertRaisesRegex(RuntimeError, 'host_listener'):
                p.validate_loopback_listener(listeners, 15432)
        raw = 'header\n 0: 0100007F:3C48 00000000:0000 0A rest\n 1: 00000000:3C48 00000000:0000 01 rest\n'
        self.assertEqual([('127.0.0.1', 15432)], p.parse_listeners(raw))
        raw6 = 'header\n 0: 00000000000000000000000001000000:3C48 00000000:0000 0A rest\n'
        self.assertEqual([('::1', 15432)], p.parse_listeners(raw6, True))

    def test_proxy_units_are_precise_unprivileged_and_worker_ordered(self):
        worker = (HERE / 'railtime-openuem-worker.service').read_text()
        for service in p.PORTS:
            name = 'railtime-openuem-' + service + '-proxy'
            port, backend_port = p.PORTS[service]
            sock = (HERE / (name + '.socket')).read_text()
            unit = (HERE / (name + '.service')).read_text()
            self.assertIn('ListenStream=127.0.0.1:' + port, sock)
            self.assertEqual(1, sock.count('ListenStream='))
            self.assertIn('DynamicUser=yes', unit)
            self.assertIn('Type=notify', unit)
            self.assertIn('IPAddressDeny=any', unit)
            self.assertIn('IPAddressAllow=127.0.0.1/32 ' + p.ADDRESSES[service] + '/32', unit)
            self.assertIn('ExecStart=/usr/lib/systemd/systemd-socket-proxyd ' + p.ADDRESSES[service] + ':' + backend_port.split('/')[0], unit)
            self.assertIn(name + '.socket', worker.split('Requires=', 1)[1].splitlines()[0])
            self.assertIn(name + '.socket', worker.split('After=', 1)[1].splitlines()[0])

    @unittest.skipIf(os.name != 'posix' or os.getuid() != 0, 'Real ownership checks require Linux root synthetic fixture')
    def test_posix_owner_modes_links_rejected(self):
        # /tmp itself is world-writable; use the fixture as trusted root only via
        # lstat shim for that ancestor, never weakening production path checks.
        with tempfile.TemporaryDirectory(prefix='railtime-preflight-') as temp:
            base = Path(temp).resolve()
            path = base / 'secret'
            path.write_bytes(b'synthetic only')
            os.chmod(path, 0o600)
            actual = Path.lstat
            def safe_temp_parent(item):
                info = actual(item)
                if item == base.parent:
                    values = list(info)
                    values[0] = stat.S_IFDIR | 0o755
                    return os.stat_result(values)
                return info
            with mock.patch.object(Path, 'lstat', safe_temp_parent):
                p.trusted_path(path, 0, (0o600,))
                os.chmod(path, 0o644)
                with self.assertRaisesRegex(RuntimeError, 'unsafe_mode'):
                    p.trusted_path(path, 0, (0o600,))
                os.chmod(path, 0o600)
                os.link(path, base / 'hardlink')
                with self.assertRaisesRegex(RuntimeError, 'hardlink'):
                    p.trusted_path(path, 0, (0o600,))
                (base / 'hardlink').unlink()
                link = base / 'symlink'
                link.symlink_to(path)
                with self.assertRaisesRegex(RuntimeError, 'symlink'):
                    p.trusted_path(link, 0, (0o600,))


if __name__ == '__main__':
    unittest.main(verbosity=2)
