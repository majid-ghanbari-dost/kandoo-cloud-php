#!/usr/bin/env python3
"""Synthetic concurrent read load on the local customer API (PHP-B / P-34).

Seeds ONE synthetic store/invoice/session (tools/dev/load-customer-seed.php), starts the
single-worker PHP built-in server against an isolated local TEST/demo database, and issues
an even mix of `GET /v1/customer/invoices` and `GET /v1/customer/invoices/{store}/{invoice}`
with a customer Bearer.

Reported numbers are LOCAL LATENCY EVIDENCE ONLY — not production capacity, not a plan-E1
host measurement and not a security audit. The independent read throttle is disabled for the
measurement; its enforcement is proven in tests/Unit/CustomerReadLimitDatabaseTest.php.
"""

import argparse
import concurrent.futures
import json
import math
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php', help='Path to PHP 8.1+ executable')
parser.add_argument('--concurrency', type=int, default=25, choices=range(10, 101))
parser.add_argument('--requests', type=int, default=200)
parser.add_argument('--github-notice', action='store_true')
args = parser.parse_args()
if args.requests < args.concurrency or args.requests > 2000 or args.requests % args.concurrency:
    parser.error('requests must be a multiple of concurrency, from concurrency to 2000')

with tempfile.TemporaryDirectory(prefix='kandoo-customer-load-') as directory:
    config = str(Path(directory) / 'config.php')
    seeded = subprocess.run([args.php, str(root / 'tools/dev/load-customer-seed.php'), config],
                            cwd=root, capture_output=True, text=True)
    if seeded.returncode or not seeded.stdout.strip():
        sys.stderr.write(seeded.stderr or 'customer load seed failed\n')
        sys.exit(1)
    seed = json.loads(seeded.stdout.strip().splitlines()[-1])

    env = os.environ.copy()
    env['KANDOO_CONFIG'] = config
    env['KANDOO_BLOCK_REAL_SMS'] = '1'
    env.pop('PHP_CLI_SERVER_WORKERS', None)  # same single-worker built-in server as the Windows kit
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(root / 'public/index.php')],
                              cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        deadline = time.monotonic() + 20
        while True:
            if server.poll() is not None:
                raise RuntimeError('PHP server exited before startup')
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.25):
                    break
            except OSError:
                if time.monotonic() >= deadline:
                    raise RuntimeError('PHP server did not start')
                time.sleep(.05)

        base = f'http://127.0.0.1:{port}'
        paths = ['/v1/customer/invoices',
                 f"/v1/customer/invoices/{seed['store']}/{seed['invoice']}"]
        authorization = {'Authorization': 'Bearer ' + seed['token']}
        barrier = threading.Barrier(args.concurrency)

        def request(index):
            target = urllib.request.Request(base + paths[index % len(paths)], headers=authorization)
            barrier.wait(timeout=30)
            start = time.perf_counter()
            status = None
            try:
                with urllib.request.urlopen(target, timeout=30) as response:
                    status = response.status
                    response.read()
            except urllib.error.HTTPError as exc:
                status = exc.code
                exc.read()
            except (OSError, TimeoutError):
                pass
            return (time.perf_counter() - start) * 1000, status

        with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as executor:
            results = list(executor.map(request, range(args.requests)))
        errors = sum(status != 200 for _, status in results)
        latencies = sorted(ms for ms, _ in results)
        p95 = latencies[math.ceil(.95 * len(latencies)) - 1]
        summary = (f'P-34 synthetic customer-API read (list/detail), MariaDB, loopback, single PHP worker: '
                   f'requests={len(results)}, concurrency={args.concurrency}, errors={errors}, '
                   f'error_rate={100 * errors / len(results):.2f}%, p95={p95:.2f}ms; not production capacity')
        print(summary, flush=True)
        if args.github_notice:
            print('::notice title=P-34 customer API load measurement::' + summary, flush=True)
        if errors:
            sys.exit(1)
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
