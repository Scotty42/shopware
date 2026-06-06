#!/usr/bin/env python3
"""
Synthetic A/B load benchmark for the Order Integration API.

Drives a significant, configurable number of read/write operations purely over
the HTTP API and records latency percentiles, throughput and error rate. Run it
once as the BASELINE (T13 off: ORDER_INTEGRATION_ASYNC_WRITES / PROJECTION_READS
= false), then enable T13 + a running write-queue worker and run it again as
CQRS — then `compare` the two JSON results to see the relative difference.

Stdlib only (urllib + threads). Reads config from ../.env.test, same vars as
tests/api_test.sh.

Usage
-----
  # 1) baseline (synchronous plugin path)
  python3 tests/benchmark.py run --label baseline --writes 300 --reads 1500 \
      --concurrency 24 --out baseline.json

  # 2) enable T13 in the BE .env, clear cache, start a worker:
  #      ORDER_INTEGRATION_ASYNC_WRITES=true
  #      ORDER_INTEGRATION_PROJECTION_READS=true
  #      ORDER_INTEGRATION_DB_DSN=pgsql:host=order-integration-db.lan.internal;port=5432;dbname=order_integration
  #      bin/console cache:clear
  #      bin/console order-integration:write-queue:drain --sleep=0 &
  python3 tests/benchmark.py run --label cqrs --async --writes 300 --reads 1500 \
      --concurrency 24 --out cqrs.json

  # 3) relative comparison
  python3 tests/benchmark.py compare baseline.json cqrs.json
"""
import argparse, json, os, random, statistics, sys, time, uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib import request, error


def load_env(path):
    """Parse a .env file the way `source` would: tolerate `export KEY=...` and
    strip surrounding single/double quotes from the value."""
    env = {}
    if os.path.exists(path):
        for line in open(path):
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            if line.startswith('export '):
                line = line[len('export '):]
            k, v = line.split('=', 1)
            v = v.strip()
            if len(v) >= 2 and v[0] == v[-1] and v[0] in ('"', "'"):
                v = v[1:-1]
            env[k.strip()] = v
    return env


def http(method, url, token=None, body=None, headers=None, timeout=30):
    """Return (status, elapsed_ms, body_bytes). Never raises on HTTP errors."""
    data = body.encode() if isinstance(body, str) else body
    req = request.Request(url, data=data, method=method)
    req.add_header('Accept', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    t0 = time.perf_counter()
    try:
        with request.urlopen(req, timeout=timeout) as r:
            payload = r.read()
            return r.status, (time.perf_counter() - t0) * 1000.0, payload
    except error.HTTPError as e:
        return e.code, (time.perf_counter() - t0) * 1000.0, e.read()
    except Exception:
        return 0, (time.perf_counter() - t0) * 1000.0, b''


def pct(values, p):
    if not values:
        return None
    s = sorted(values)
    k = min(len(s) - 1, int(round((p / 100.0) * (len(s) - 1))))
    return s[k]


def summarize(latencies, wall_s):
    return {
        'count': len(latencies),
        'throughput_per_s': round(len(latencies) / wall_s, 2) if wall_s > 0 else None,
        'mean_ms': round(statistics.fmean(latencies), 2) if latencies else None,
        'p50_ms': round(pct(latencies, 50), 2) if latencies else None,
        'p90_ms': round(pct(latencies, 90), 2) if latencies else None,
        'p95_ms': round(pct(latencies, 95), 2) if latencies else None,
        'p99_ms': round(pct(latencies, 99), 2) if latencies else None,
        'max_ms': round(max(latencies), 2) if latencies else None,
    }


class Bench:
    def __init__(self, env):
        self.base_root = env['SHOPWARE_URL'].rstrip('/')
        self.base = self.base_root + '/api/order-integration/v1'
        self.env = env
        self.token = None
        self.customer_id = None
        self.read_ids = []

    def auth(self):
        body = json.dumps({
            'grant_type': 'password', 'client_id': 'administration',
            'username': self.env['SHOPWARE_ADMIN_USER'],
            'password': self.env['SHOPWARE_ADMIN_PASSWORD'], 'scopes': 'write',
        })
        st, _, payload = http('POST', self.base_root + '/api/oauth/token', body=body)
        if st != 200:
            sys.exit(
                f"auth failed (HTTP {st}) at {self.base_root}/api/oauth/token "
                f"as user '{self.env['SHOPWARE_ADMIN_USER']}'. "
                f"Server said: {payload.decode(errors='replace')[:300]}"
            )
        self.token = json.loads(payload)['access_token']

    def prepare(self, want_read_ids):
        st, _, p = http('GET', self.base_root + '/api/customer?limit=1', self.token)
        try:
            doc = json.loads(p)
        except Exception:
            doc = {}
        if st != 200 or not isinstance(doc, dict) or not doc.get('data'):
            sys.exit(
                f"could not read a customer (HTTP {st}) from {self.base_root}/api/customer?limit=1. "
                f"Server said: {p.decode(errors='replace')[:300]}"
            )
        self.customer_id = doc['data'][0]['id']
        ids, cursor = [], None
        while len(ids) < want_read_ids:
            url = self.base + '/orders?limit=200' + (f'&cursor={cursor}' if cursor else '')
            st, _, p = http('GET', url, self.token)
            if st != 200:
                break
            doc = json.loads(p)
            ids += [o['id'] for o in doc.get('items', [])]
            cursor = doc.get('page', {}).get('nextCursor')
            if not cursor:
                break
        if not ids:
            sys.exit('no existing orders to read — seed at least one order first (tests/create_test_order.sh).')
        self.read_ids = ids

    def order_body(self):
        return json.dumps({
            'salesChannelId': self.env['SHOPWARE_SALES_CHANNEL_ID'],
            'customer': {'id': self.customer_id},
            'lineItems': [{'productId': self.env['SHOPWARE_TEST_PRODUCT_ID'], 'quantity': 1}],
        })

    def one_write(self, async_mode):
        # Force the path per-request via Prefer, so the baseline is truly
        # synchronous even if ORDER_INTEGRATION_ASYNC_WRITES=true is set on the
        # server (otherwise "baseline" would silently measure the async enqueue).
        headers = {
            'Idempotency-Key': str(uuid.uuid4()),
            'Prefer': 'respond-async' if async_mode else 'respond-sync',
        }
        st, ms, payload = http('POST', self.base + '/orders', self.token, self.order_body(), headers)
        job_id = None
        if async_mode and st == 202:
            try:
                job_id = json.loads(payload).get('jobId')
            except Exception:
                pass
        return {'ok': st in (200, 201, 202), 'status': st, 'ms': ms,
                'job_id': job_id, 'enqueued_at': time.perf_counter()}

    def one_read(self):
        if random.random() < 0.5:
            url = self.base + '/orders?limit=50'
        else:
            url = self.base + '/orders/' + random.choice(self.read_ids)
        st, ms, _ = http('GET', url, self.token)
        return {'ok': st == 200, 'status': st, 'ms': ms}

    def poll_completion(self, jobs, concurrency, max_wait_s):
        """Poll job status until terminal; return (completions, still_pending)."""
        pending = {j['job_id']: j for j in jobs if j.get('job_id')}
        completions, deadline = [], time.perf_counter() + max_wait_s
        while pending and time.perf_counter() < deadline:
            done_now = []
            with ThreadPoolExecutor(max_workers=concurrency) as ex:
                futs = {ex.submit(http, 'GET', self.base + '/jobs/' + jid, self.token): jid
                        for jid in list(pending)}
                for f in as_completed(futs):
                    jid = futs[f]
                    st, _, payload = f.result()
                    if st != 200:
                        continue
                    try:
                        status = (json.loads(payload) or {}).get('status')
                    except Exception:
                        continue
                    if status in ('succeeded', 'dead'):
                        j = pending[jid]
                        completions.append({'ms': (time.perf_counter() - j['enqueued_at']) * 1000.0,
                                            'status': status})
                        done_now.append(jid)
            for jid in done_now:
                pending.pop(jid, None)
            if pending:
                time.sleep(0.5)
        return completions, len(pending)

    def run(self, label, writes, reads, concurrency, async_mode, max_wait_s):
        self.auth()
        self.prepare(max(reads, 200))
        result = {'label': label, 'async_mode': async_mode,
                  'config': {'writes': writes, 'reads': reads, 'concurrency': concurrency},
                  'base': self.base}

        # writes
        t0 = time.perf_counter()
        wres = []
        with ThreadPoolExecutor(max_workers=concurrency) as ex:
            futs = [ex.submit(self.one_write, async_mode) for _ in range(writes)]
            for f in as_completed(futs):
                wres.append(f.result())
        wall_w = time.perf_counter() - t0
        result['writes'] = summarize([r['ms'] for r in wres if r['ok']], wall_w)
        result['writes']['errors'] = sum(1 for r in wres if not r['ok'])
        result['writes']['kind'] = 'enqueue (202)' if async_mode else 'synchronous (201)'

        if async_mode:
            comp, still_pending = self.poll_completion(wres, concurrency, max_wait_s)
            comp_ms = [c['ms'] for c in comp]
            t_comp = summarize(comp_ms, wall_w) if comp_ms else {}
            t_comp['completed'] = len(comp_ms)
            t_comp['pending_after_timeout'] = still_pending
            t_comp['dead'] = sum(1 for c in comp if c['status'] == 'dead')
            result['writes_completion'] = t_comp

        # reads
        t0 = time.perf_counter()
        rres = []
        with ThreadPoolExecutor(max_workers=concurrency) as ex:
            futs = [ex.submit(self.one_read) for _ in range(reads)]
            for f in as_completed(futs):
                rres.append(f.result())
        wall_r = time.perf_counter() - t0
        result['reads'] = summarize([r['ms'] for r in rres if r['ok']], wall_r)
        result['reads']['errors'] = sum(1 for r in rres if not r['ok'])
        return result


def print_run(res):
    print(f"\n=== {res['label']}  (async={res['async_mode']})  "
          f"writes={res['config']['writes']} reads={res['config']['reads']} "
          f"concurrency={res['config']['concurrency']} ===")
    for section in ('writes', 'writes_completion', 'reads'):
        if section in res:
            m = res[section]
            print(f"  {section:18} " + "  ".join(
                f"{k}={m[k]}" for k in ('count', 'completed', 'throughput_per_s',
                                        'p50_ms', 'p95_ms', 'p99_ms', 'max_ms', 'errors',
                                        'pending_after_timeout', 'dead') if k in m))


def rel(a, b):
    if a in (None, 0) or b is None:
        return 'n/a'
    d = (b - a) / a * 100.0
    return f"{b} ({'+' if d >= 0 else ''}{d:.0f}%)"


def compare(a, b):
    A, B = json.load(open(a)), json.load(open(b))
    print(f"\n=== compare: {A['label']} (baseline)  ->  {B['label']} ===")
    for section in ('writes', 'reads'):
        if section in A and section in B:
            print(f"\n[{section}]  {A['label']} -> {B['label']}")
            for k in ('throughput_per_s', 'p50_ms', 'p95_ms', 'p99_ms', 'max_ms', 'errors'):
                if k in A[section] and k in B[section]:
                    print(f"  {k:18} {A[section][k]} -> {rel(A[section].get(k), B[section].get(k))}")
    if 'writes_completion' in B:
        wc = B['writes_completion']
        print(f"\n[{B['label']} async end-to-end]  completed={wc.get('completed')}  "
              f"p95_ms={wc.get('p95_ms')}  pending_after_timeout={wc.get('pending_after_timeout')}  dead={wc.get('dead')}")
        print("  (compare async end-to-end p95 against the baseline synchronous write p95")
        print("   to judge the latency-vs-protection trade-off; the queue caps pressure on Shopware.)")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    r = sub.add_parser('run')
    r.add_argument('--label', required=True)
    r.add_argument('--writes', type=int, default=300)
    r.add_argument('--reads', type=int, default=1500)
    r.add_argument('--concurrency', type=int, default=24)
    r.add_argument('--async', dest='async_mode', action='store_true',
                   help='send Prefer: respond-async and poll job completion (needs T13 + a worker)')
    r.add_argument('--max-wait', type=int, default=120, help='seconds to wait for the queue to drain')
    r.add_argument('--out', default=None)
    c = sub.add_parser('compare')
    c.add_argument('baseline')
    c.add_argument('cqrs')
    args = ap.parse_args()

    if args.cmd == 'compare':
        compare(args.baseline, args.cqrs)
        return

    env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '.env.test')
    env = load_env(env_path)
    missing = [k for k in ('SHOPWARE_URL', 'SHOPWARE_ADMIN_USER', 'SHOPWARE_ADMIN_PASSWORD',
                           'SHOPWARE_SALES_CHANNEL_ID', 'SHOPWARE_TEST_PRODUCT_ID') if not env.get(k)]
    if missing:
        sys.exit('missing in .env.test: ' + ', '.join(missing))

    res = Bench(env).run(args.label, args.writes, args.reads, args.concurrency,
                         args.async_mode, args.max_wait)
    print_run(res)
    if args.out:
        json.dump(res, open(args.out, 'w'), indent=2)
        print(f"\nsaved -> {args.out}")


if __name__ == '__main__':
    main()
