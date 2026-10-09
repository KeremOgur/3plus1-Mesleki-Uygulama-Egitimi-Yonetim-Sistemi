"""Check generated local OpenAPI references and methods against Laravel routes."""
import json
from pathlib import Path

root = Path(__file__).resolve().parents[2]
spec = json.loads((root / 'docs/openapi.json').read_text(encoding='utf-8'))
routes = json.loads((root / 'docs/routes.json').read_text(encoding='utf-8-sig'))
resources = json.loads((root / 'docs/resource-manifest.json').read_text(encoding='utf-8'))
expected = set()
for route in routes:
    if not route['uri'].startswith('api/v1/'):
        continue
    path = '/' + route['uri'].removeprefix('api/v1/')
    variants = [path.replace('{resource}', name) for name in resources] if path.startswith('/resources/{resource}') else [path]
    for variant in variants:
        for method in route['method'].lower().split('|'):
            if method != 'head':
                # These routes exist dynamically but explicitly reject generic writes.
                name = variant.split('/')[2] if variant.startswith('/resources/') else None
                if method in ('post', 'put') and name and '{id}/transition' not in variant:
                    internal = {'preferences','matching_runs','candidate_scores','match_results','decisions','publications','publication_items','success_results','documents','import_batches','notifications','outbox_events'}
                    if name in internal or (method == 'post' and name == 'appeals'):
                        continue
                if variant.endswith('/transition') and name and not resources[name]['states']:
                    continue
                expected.add((variant, method))
actual = {(path, method) for path, item in spec['paths'].items() for method in item}
assert expected == actual, {'missing': sorted(expected-actual), 'extra': sorted(actual-expected)}
refs = 0
def walk(value):
    global refs
    if isinstance(value, dict):
        if '$ref' in value:
            target = spec
            assert value['$ref'].startswith('#/')
            for part in value['$ref'][2:].split('/'):
                target = target[part]
            refs += 1
        for item in value.values():
            walk(item)
    elif isinstance(value, list):
        for item in value:
            walk(item)
walk(spec)
ids = []
for path, item in spec['paths'].items():
    for method, operation in item.items():
        ids.append(operation['operationId'])
        parameters = [(p['in'], p['name']) for p in operation['parameters']]
        assert len(parameters) == len(set(parameters)), path
        assert bool(operation['security']) == (not path.startswith('/auth/') or path in ('/auth/me','/auth/logout')), path
assert len(ids) == len(set(ids))
result = {'paths': len(spec['paths']), 'operations': len(actual), 'resolved_refs': refs, 'resources':len(resources), 'status':'passed', 'limit':'Structural contract check; conditional domain rules are also documented in API.md and tested through HTTP/PHPUnit.'}
print(json.dumps(result, ensure_ascii=False))
(root / 'backend/storage/logs/full-qa-20261007/openapi-check.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
