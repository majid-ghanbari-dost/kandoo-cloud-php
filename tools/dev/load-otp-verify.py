#!/usr/bin/env python3
"""Synthetic concurrent load on the customer OTP path (PHP-B / P-34).

`docs/SECURITY-REVIEW-P34.md` lists the OTP path as an unknown cost. This measures it locally:
one synthetic announcement + one real `POST /v1/customer/otp/challenges` (the SMS transport is
the FAKE dry-run Faraz adapter, so nothing leaves the machine), then N concurrent verifications
with a WRONG code against that challenge.

Expected answers are 400 `INVALID` (wrong code) and, once `max_attempts` is exhausted, the
locked answer — both are legitimate server responses, so only 5xx and connection failures count
as errors. The status histogram is printed for that reason: a measurement that silently mixes
401/429/500 into "errors" would be worthless.

This is LOCAL LATENCY EVIDENCE ONLY. It is not plan-E1 capacity, not a comparison with a real
SMS provider's round trip (there is no network SMS here) and not a security test.
"""

import argparse
import concurrent.futures
import json
import math
import os
from pathlib import Path
import random
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import uuid

root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--concurrency', type=int, default=10, choices=range(5, 51))
parser.add_argument('--requests', type=int, default=100)
parser.add_argument('--github-notice', action='store_true')
args = parser.parse_args()
if args.requests < args.concurrency or args.requests > 1000 or args.requests % args.concurrency:
    parser.error('requests must be a multiple of concurrency, from concurrency to 1000')


def post(url, payload, headers, timeout=30):
    body = json.dumps(payload).encode()
    request = urllib.request.Request(url, data=body, method='POST',
                                     headers={**headers, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, json.loads(response.read() or b'{}')
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        try:
            return exc.code, json.loads(raw or b'{}')
        except json.JSONDecodeError:
            return exc.code, {}


with tempfile.TemporaryDirectory(prefix='kandoo-otp-load-') as directory:
    config = str(Path(directory) / 'config-otp.php')
    seeded = subprocess.run([args.php, str(root / 'tools/dev/load-customer-seed.php'), config, '0', 'otp'],
                            cwd=root, capture_output=True, text=True)
    if seeded.returncode or not seeded.stdout.strip():
        sys.stderr.write(seeded.stderr or 'OTP load seed failed\n')
        sys.exit(1)
    seed = json.loads(seeded.stdout.strip().splitlines()[-1])
    if seed.get('otp') is not True:
        sys.stderr.write('seed did not enter OTP mode\n')
        sys.exit(1)

    env = os.environ.copy()
    env['KANDOO_CONFIG'] = config
    env['KANDOO_BLOCK_REAL_SMS'] = '1'  # belt and braces: the dry-run adapter is already offline
    env.pop('PHP_CLI_SERVER_WORKERS', None)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'),
                               str(root / 'public/index.php')],
                              cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        deadline = time.monotonic() + 20
        while True:
            if server.poll() is not None:
                sys.exit('PHP server exited before startup')
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.25):
                    break
            except OSError:
                if time.monotonic() >= deadline:
                    sys.exit('PHP server did not start')
                time.sleep(.05)
        base = f'http://127.0.0.1:{port}'
        device = str(uuid.uuid4())

        # One real issue: this is the path that would call the provider, here it is a dry run.
        status, issued = post(base + '/v1/customer/otp/challenges', {'phone': seed['phone']},
                              {'Idempotency-Key': 'TEST-otp-load-' + uuid.uuid4().hex[:16],
                               'X-Kandoo-Device': device})
        challenge = issued.get('challenge_id')
        if status != 201 or not isinstance(challenge, str) or challenge == '':
            sys.stderr.write(f'issue failed: HTTP {status} {json.dumps(issued)[:200]}\n')
            sys.exit(1)

        wrong_codes = ['000000', '111111', '222222', '333333']
        barrier = threading.Barrier(args.concurrency)

        def verify(index):
            code = random.Random(index).choice(wrong_codes)
            barrier.wait(timeout=30)
            start = time.perf_counter()
            try:
                status, _ = post(f'{base}/v1/customer/otp/challenges/{challenge}/verify',
                                 {'code': code}, {'X-Kandoo-Device': device})
            except OSError:
                return (time.perf_counter() - start) * 1000, 0
            return (time.perf_counter() - start) * 1000, status

        with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as executor:
            results = list(executor.map(verify, range(args.requests)))
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()

    histogram = {}
    for _, status in results:
        histogram[str(status)] = histogram.get(str(status), 0) + 1
    # 5xx and 0 (connection failure) are the only real errors: 400/423 are the expected answers.
    errors = sum(count for code, count in histogram.items() if code == '0' or code.startswith('5'))
    latencies = sorted(ms for ms, _ in results)
    p95 = latencies[math.ceil(.95 * len(latencies)) - 1]
    summary = (f'phase=otp-verify requests={len(results)} concurrency={args.concurrency} '
               f'errors={errors} p95={p95:.2f}ms statuses={json.dumps(histogram, sort_keys=True)}')
    print(summary, flush=True)
    report = (f'P-34 synthetic customer OTP verify (dry-run SMS transport, MariaDB, loopback, single '
              f'PHP worker): requests={args.requests}, concurrency={args.concurrency}, 5xx/connection '
              f'errors={errors}, p95={p95:.2f}ms, responses={json.dumps(histogram, sort_keys=True)}; '
              f'local latency only, not provider delivery and not production capacity')
    print(report, flush=True)
    if args.github_notice:
        print('::notice title=P-34 customer OTP verify load measurement::' + report, flush=True)
    if errors:
        sys.exit(1)
