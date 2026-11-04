#!/usr/bin/env python3
"""CI docs validation for the Kandoo Cloud PHP repo (فاکتور دیجیتال کندو / Kandoo Digital Invoice).

Checks (no PHP required):
1. Every docs/schemas/*.json and docs/golden/*.json is valid JSON and matches basic structural rules.
2. Golden cases: fake_data flag, unique ids, exact-body JSON parses, subset headers lower-cased.
3. Canonical-hash self-consistency: every LogEntry inside every golden request body is
   re-hashed with the P-02 canonical JSON profile (CLOUD-CONTRACT.md §5.5). For the
   intentional corruption case (G-008) the recomputed hash must equal the documented
   recomputed_entry_hash and differ from the supplied entry_hash; everywhere else the
   supplied request_hash/entry_hash must reproduce exactly.
4. The identical-404 rule: g-019 and g-020 response bodies are byte-identical.
5. docs/DECISIONS.md carries D1..D5; docs/CLOUD-CONTRACT.md carries the UNCONFIRMED register.

All fixture values are FAKE synthetic data; nothing here touches production.
"""
import json, hashlib, os, re, sys
from collections import OrderedDict

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
FAILURES = []

def fail(msg):
    FAILURES.append(msg)
    print('FAIL:', msg)

def load(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f, object_pairs_hook=OrderedDict)

# --------------------------------------------------------------- canonical P-02 JSON
def canon(v, omit_root=None, is_root=True):
    if isinstance(v, (OrderedDict, dict)):
        items = list(v.items())
        keys = [k for k, _ in items]
        if len(set(keys)) != len(keys):
            raise ValueError('duplicate object key is not canonical')
        items.sort(key=lambda kv: kv[0])
        parts = []
        for k, val in items:
            if is_root and omit_root is not None and k == omit_root:
                continue
            parts.append(json.dumps(k, ensure_ascii=False, separators=(',', ':')) + ':' + canon(val, None, False))
        return '{' + ','.join(parts) + '}'
    if isinstance(v, list):
        return '[' + ','.join(canon(x, None, False) for x in v) + ']'
    if isinstance(v, bool):
        return 'true' if v else 'false'
    if v is None:
        return 'null'
    if isinstance(v, int):
        return str(v)
    if isinstance(v, float):
        raise ValueError('float in hash-bearing JSON')
    return json.dumps(v, ensure_ascii=False, separators=(',', ':'))

def sha(s):
    return hashlib.sha256(s.encode('utf-8')).hexdigest()

ENTRY_FIELDS = ['protocol_version', 'store_id', 'writer_device_id', 'writer_epoch', 'operation_seq',
                'previous_entry_hash', 'entry_hash', 'operation_id', 'device_id', 'operation_type',
                'base_version', 'request_hash', 'payload', 'outcome', 'response_snapshot', 'hub_recorded_at']

def entry_dto(e):
    d = OrderedDict()
    for f in ENTRY_FIELDS:
        v = e.get(f)
        if f in ('protocol_version', 'previous_entry_hash', 'entry_hash', 'operation_id',
                 'operation_type', 'request_hash', 'hub_recorded_at'):
            v = '' if v is None else v
        d[f] = v
    return d

def check_hashes(gid, entry, expect_mismatch):
    dto = entry_dto(entry)
    supplied_entry = dto['entry_hash']
    recomputed_entry = sha(canon(dto, omit_root='entry_hash'))
    inp = OrderedDict([('store_id', dto['store_id']), ('device_id', dto['device_id']),
                       ('operation_id', dto['operation_id']), ('operation_type', dto['operation_type']),
                       ('base_version', dto['base_version']), ('payload', dto['payload'])])
    recomputed_request = sha(canon(inp))
    if recomputed_request != dto['request_hash']:
        fail(f'{gid}: entry seq {dto["operation_seq"]} request_hash does not reproduce')
    if expect_mismatch:
        if recomputed_entry == supplied_entry:
            fail(f'{gid}: corrupted fixture unexpectedly reproduces its entry_hash')
        return recomputed_entry
    if recomputed_entry != supplied_entry:
        fail(f'{gid}: entry seq {dto["operation_seq"]} entry_hash does not reproduce '
             f'(supplied {supplied_entry}, recomputed {recomputed_entry})')
    return recomputed_entry

# --------------------------------------------------------------- checks
schemas = []
for dirpath in (os.path.join(ROOT, 'docs', 'schemas'),):
    for base, _dirs, files in os.walk(dirpath):
        for name in sorted(files):
            if name.endswith('.json'):
                schemas.append(os.path.join(base, name))
for path in schemas:
    try:
        doc = load(path)
        if '$schema' not in doc or 'draft/2020-12' not in doc['$schema']:
            fail(f'{path}: missing JSON Schema 2020-12 $schema')
        if '$id' not in doc or 'title' not in doc:
            fail(f'{path}: missing $id/title')
    except Exception as exc:  # noqa: BLE001
        fail(f'{path}: {exc}')
print(f'schemas checked: {len(schemas)}')

golden_dir = os.path.join(ROOT, 'docs', 'golden')
golden_files = sorted(f for f in os.listdir(golden_dir) if f.endswith('.json'))
ids = set()
bodies_404 = {}
for name in golden_files:
    path = os.path.join(golden_dir, name)
    try:
        g = load(path)
    except Exception as exc:  # noqa: BLE001
        fail(f'{name}: {exc}')
        continue
    gid = g.get('id', name)
    if g.get('fake_data') is not True or 'SYNTHETIC' not in g.get('fake_data_notice', ''):
        fail(f'{gid}: missing fake-data marking')
    if gid in ids:
        fail(f'{gid}: duplicate golden id')
    ids.add(gid)
    for key in ('golden_version', 'title', 'source', 'given', 'steps'):
        if key not in g:
            fail(f'{gid}: missing {key}')
    for key in ('stores', 'entries', 'batches', 'public_invoices'):
        if key not in g.get('given', {}):
            fail(f'{gid}: given.{key} missing')
    for i, st in enumerate(g.get('steps', [])):
        req = st.get('request', {})
        resp = st.get('response', {})
        for key in ('method', 'path', 'headers', 'body_text'):
            if key not in req:
                fail(f'{gid} step {i}: request.{key} missing')
        for key in ('status', 'headers', 'headers_match', 'body_match'):
            if key not in resp:
                fail(f'{gid} step {i}: response.{key} missing')
        for hk in list(req.get('headers', {})) + list(resp.get('headers', {})):
            if hk != hk.lower():
                fail(f'{gid} step {i}: header name not lower-cased: {hk}')
        if resp.get('body_match') == 'exact':
            body = resp.get('body_text', '')
            try:
                parsed = json.loads(body)
            except Exception as exc:  # noqa: BLE001
                fail(f'{gid} step {i}: exact body is not valid JSON: {exc}')
                parsed = None
            if parsed is not None and parsed.get('code') == 'ENTRY_HASH_MISMATCH':
                recomputed = parsed['retry_hint']['details']['recomputed_entry_hash']
                # verify the documented recomputed hash by re-hashing the request entries
                try:
                    batch = json.loads(req.get('body_text', ''), object_pairs_hook=OrderedDict)
                except Exception:  # noqa: BLE001
                    batch = None
                if batch and batch.get('entries'):
                    got = check_hashes(gid, batch['entries'][0], expect_mismatch=True)
                    if got != recomputed:
                        fail(f'{gid}: documented recomputed_entry_hash {recomputed} != independently recomputed {got}')
            if req.get('method') == 'POST' and req.get('body_text'):
                try:
                    batch = json.loads(req['body_text'], object_pairs_hook=OrderedDict)
                except Exception as exc:  # noqa: BLE001
                    fail(f'{gid} step {i}: request body is not valid JSON: {exc}')
                    continue
                resp_code = (parsed or {}).get('code')
                if isinstance(batch, OrderedDict) and 'entries' in batch and batch['entries'] \
                        and resp_code != 'PAYLOAD_TOO_LARGE':
                    # PAYLOAD_TOO_LARGE (entry-count) fires before per-entry hash validation,
                    # so g-016 carries structurally valid but hash-invalid entries on purpose.
                    corrupt = resp_code == 'ENTRY_HASH_MISMATCH'
                    for e in batch['entries']:
                        check_hashes(gid, e, expect_mismatch=corrupt)
            if parsed is not None and parsed.get('code') == 'PUBLIC_INVOICE_NOT_FOUND':
                bodies_404[gid] = body
    if name.startswith('g-019') or name.startswith('g-020'):
        pass
print(f'golden cases checked: {len(golden_files)}')

if len(bodies_404) >= 2:
    distinct = set(bodies_404.values())
    if len(distinct) != 1:
        fail(f'identical-404 rule violated: {bodies_404}')
    else:
        print('identical-404 bodies: byte-identical across', sorted(bodies_404))
else:
    fail('expected PUBLIC_INVOICE_NOT_FOUND goldens (g-019/g-020)')

dec = open(os.path.join(ROOT, 'docs', 'DECISIONS.md'), encoding='utf-8').read()
for marker in ('D1.', 'D2.', 'D3.', 'D4.', 'D5.'):
    if marker not in dec:
        fail(f'DECISIONS.md missing {marker}')
contract = open(os.path.join(ROOT, 'docs', 'CLOUD-CONTRACT.md'), encoding='utf-8').read()
for marker in ('UNCONFIRMED register', 'canonical', 'WRITER_FENCED', 'PUBLIC_INVOICE_NOT_FOUND',
               'فاکتور دیجیتال کندو', 'PROPOSED v1.1', 'remaining_balance', 'currency_unit',
               'card-reader', 'card-to-card'):
    if marker not in contract:
        fail(f'CLOUD-CONTRACT.md missing marker: {marker}')

if FAILURES:
    print(f'\n{len(FAILURES)} failure(s)')
    sys.exit(1)
print('\nAll docs/golden checks passed.')
