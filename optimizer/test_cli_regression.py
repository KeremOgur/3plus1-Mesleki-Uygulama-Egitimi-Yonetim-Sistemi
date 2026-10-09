"""Exercise the immutable stdin/stdout boundary used by Laravel's worker."""
import ast
import json
from pathlib import Path
import subprocess
import sys
import unittest

from test_source_compliance import data, pair


class CliRegressionTest(unittest.TestCase):
    def invoke(self, payload):
        return subprocess.run([sys.executable, str(Path(__file__).with_name('runner.py'))],
                              input=payload, capture_output=True, text=True, encoding='utf-8', timeout=20)

    def test_snapshot_cli_is_repeatable_and_does_not_modify_input(self):
        snapshot = data(2)
        snapshot['candidates'] = [pair('S0', 'A'), pair('S1', 'B')]
        frozen = json.dumps(snapshot, ensure_ascii=False, sort_keys=True)
        first, second = self.invoke(frozen), self.invoke(frozen)
        self.assertEqual((first.returncode, second.returncode), (0, 0))
        a, b = json.loads(first.stdout), json.loads(second.stdout)
        self.assertEqual(a['assignments'], b['assignments'])
        self.assertEqual(a['objective_values'], b['objective_values'])
        self.assertEqual(frozen, json.dumps(snapshot, ensure_ascii=False, sort_keys=True))
        self.assertTrue(a['proven_optimal'])

    def test_malformed_snapshot_returns_error_json_and_nonzero_exit(self):
        for payload in ['not-json', '{}']:
            with self.subTest(payload=payload):
                result = self.invoke(payload)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(json.loads(result.stdout)['solver_status'], 'ERROR')
                self.assertNotIn('Traceback', result.stdout)

    def test_zero_capacity_and_no_candidates_return_all_unplaced(self):
        for candidates in [[], [pair('S0', 'A'), pair('S1', 'B')]]:
            snapshot = data(2)
            snapshot['candidates'] = candidates
            for offer in snapshot['offers']:
                offer['capacity'] = 0
            result = json.loads(self.invoke(json.dumps(snapshot)).stdout)
            self.assertEqual(result['assignments'], [])
            self.assertEqual({row['student_id'] for row in result['unplaced']}, {'S0', 'S1'})
            self.assertTrue(result['proven_optimal'])

    def test_runner_has_no_database_network_or_file_write_boundary(self):
        tree = ast.parse(Path(__file__).with_name('runner.py').read_text(encoding='utf-8'))
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports.update(alias.name for alias in node.names)
            elif isinstance(node, ast.ImportFrom):
                imports.add(node.module)
        self.assertEqual(imports, {'hashlib', 'json', 'sys', 'time', 'collections', 'ortools.sat.python'})
        calls = [node for node in ast.walk(tree) if isinstance(node, ast.Call)]
        self.assertFalse(any(isinstance(call.func, ast.Name) and call.func.id == 'open' for call in calls))
        self.assertFalse(any(isinstance(call.func, ast.Attribute) and call.func.attr in
                             {'connect', 'execute', 'executemany', 'write_text', 'write_bytes'} for call in calls))


if __name__ == '__main__':
    unittest.main()
