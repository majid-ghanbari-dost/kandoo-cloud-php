#!/usr/bin/env python3
"""Concurrent loopback HTTP benchmark on a local synthetic missing-invoice lookup."""
import argparse
import concurrent.futures
import math
import os
from pathlib import Path
import secrets
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
parser.add_argument('--concurrency', type=int, default=50, choices=range(50, 101))
parser.add_argument('--requests', type=int, default=100)
parser.add_argument('--github-notice', action='store_true')
args = parser.parse_args()
if args.requests < args.concurrency or args.requests > 1000 or args.requests % args.concurrency:
    parser.error('requests must be a multiple of concurrency, from concurrency to 1000')

# The PHP helper rejects non-loopback DBs and all non-demo/non-TEST environments.
with tempfile.TemporaryDirectory(prefix='kandoo-load-') as directory:
    config = str(Path(directory) / 'config.php')
    result = subprocess.run([args.php, str(root / 'tools/dev/load-config.php'), config], cwd=root)
    if result.returncode:
        sys.exit(result.returncode)
    env = os.environ.copy()
    env['KANDOO_CONFIG'] = config
    env['KANDOO_BLOCK_REAL_SMS'] = '1'
    env.pop('PHP_CLI_SERVER_WORKERS', None)  # same single-worker built-in server as Windows kit
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
        token = 'TEST_LOAD_' + secrets.token_urlsafe(24)
        url = f'http://127.0.0.1:{port}/i/{token}'
        barrier = threading.Barrier(args.concurrency)
        def request(_):
            barrier.wait(timeout=30)
            start = time.perf_counter()
            status = None
            try:
                with urllib.request.urlopen(url, timeout=30) as response:
                    status = response.status
                    response.read()
            except urllib.error.HTTPError as exc:
                status = exc.code
                exc.read()
            except (OSError, TimeoutError):
                pass
            return (time.perf_counter()-start)*1000, status
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as executor:
            results = list(executor.map(request, range(args.requests)))
        errors = sum(status != 404 for _, status in results)
        latencies = sorted(ms for ms, _ in results)
        p95 = latencies[math.ceil(.95*len(latencies))-1]
        summary = (f'P-24 synthetic unknown-invoice GET, MariaDB, loopback, single PHP worker: '
                   f'requests={len(results)}, concurrency={args.concurrency}, errors={errors}, '
                   f'error_rate={100*errors/len(results):.2f}%, p95={p95:.2f}ms; not production capacity')
        print(summary, flush=True)
        if args.github_notice:
            print('::notice title=P-24 load measurement::'+summary, flush=True)
        if errors:
            sys.exit(1)
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
