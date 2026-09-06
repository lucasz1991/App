import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('check_infrastructure', Path(__file__).with_name('check-infrastructure.py'))
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


class HealthContractTests(unittest.TestCase):
    def baseline(self):
        return {'protocol': 'railtime.execution.v1', 'ready': False,
                'storage_ready': True, 'broker_ready': True,
                'provisioning_only': True, 'capabilities': []}

    def test_exact_infrastructure_contract(self):
        check.check_health(503, self.baseline())

    def test_full_ready_or_capabilities_refused(self):
        for field, value in [('ready', True), ('provisioning_only', False),
                             ('capabilities', ['profile_runs_v1'])]:
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                check.check_health(503, self.baseline() | {field: value})

    def test_failed_dependencies_refused(self):
        for field in ('storage_ready', 'broker_ready'):
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                check.check_health(503, self.baseline() | {field: False})

    def test_numeric_boolean_substitutes_refused(self):
        for field, value in [('ready', 0), ('storage_ready', 1),
                             ('broker_ready', 1), ('provisioning_only', 1)]:
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                check.check_health(503, self.baseline() | {field: value})

    def test_http_success_or_missing_fields_refused(self):
        with self.assertRaises(RuntimeError):
            check.check_health(200, self.baseline())
        with self.assertRaises(RuntimeError):
            check.check_health(503, {})


if __name__ == '__main__':
    unittest.main()
