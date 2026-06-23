#!/usr/bin/env python3
"""
Cancel all orders on the test Shopware instance and acknowledge them in the
ERP pull queue so that a subsequent Celigo pull via GET /erp/orders returns
no results.

Config: ../.env.test  (same pattern as celigo_pull.py)

Usage:
    python3 tests/cleanup_test_orders.py            # cancel + acknowledge all
    python3 tests/cleanup_test_orders.py --dry-run  # preview without changes
"""
import argparse, json, os, sys, uuid
from urllib import request, error
from urllib.parse import quote

USER_AGENT = (
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
    'AppleWebKit/537.36 (KHTML, like Gecko) '
    'Chrome/126.0.0.0 Safari/537.36'
)


# ── env / http helpers (same pattern as celigo_pull.py) ─────────────────────

def load_env(path):
    env = {}
    if not os.path.exists(path):
        return env
    for line in open(path):
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        if line.startswith('export '):
            line = line[7:]
        k, v = line.split('=', 1)
        v = v.strip()
        if len(v) >= 2 and v[0] == v[-1] and v[0] in ('"', "'"):
            v = v[1:-1]
        env[k.strip()] = v
    return env


def acquire_token(env, extra_headers=None):
    body = json.dumps({
        'grant_type': 'password',
        'client_id': 'administration',
        'username': env['SHOPWARE_ADMIN_USER'],
        'password': env['SHOPWARE_ADMIN_PASSWORD'],
        'scopes': 'write',
    }).encode()
    req = request.Request(env['SHOPWARE_URL'].rstrip('/') + '/api/oauth/token', data=body)
    req.add_header('Content-Type', 'application/json')
    req.add_header('Accept', 'application/json')
    req.add_header('User-Agent', USER_AGENT)
    for k, v in (extra_headers or {}).items():
        req.add_header(k, v)
    try:
        with request.urlopen(req, timeout=20) as r:
            return json.loads(r.read())['access_token']
    except Exception as ex:
        sys.exit(f'Auth failed: {ex}')


def http_get(url, token, extra_headers=None, timeout=20):
    req = request.Request(url)
    req.add_header('Accept', 'application/json')
    req.add_header('Authorization', 'Bearer ' + token)
    req.add_header('User-Agent', USER_AGENT)
    for k, v in (extra_headers or {}).items():
        req.add_header(k, v)
    try:
        with request.urlopen(req, timeout=timeout) as r:
            raw = r.read()
            etag = r.headers.get('ETag', '')
            try:
                body = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                snippet = raw[:500].decode('utf-8', errors='replace')
                sys.exit(f'Non-JSON response from {url} (HTTP {r.status}): {snippet}')
            return r.status, body, etag
    except error.HTTPError as e:
        return e.code, {}, ''
    except Exception as ex:
        sys.exit(f'HTTP error ({url}): {ex}')


def http_delete(url, token, etag, idempotency_key, extra_headers=None, timeout=20):
    req = request.Request(url, method='DELETE')
    req.add_header('Accept', 'application/json')
    req.add_header('Authorization', 'Bearer ' + token)
    req.add_header('If-Match', etag)
    req.add_header('Idempotency-Key', idempotency_key)
    req.add_header('Prefer', 'respond-sync')
    req.add_header('User-Agent', USER_AGENT)
    for k, v in (extra_headers or {}).items():
        req.add_header(k, v)
    try:
        with request.urlopen(req, timeout=timeout) as r:
            return r.status
    except error.HTTPError as e:
        return e.code
    except Exception as ex:
        print(f'  DELETE error: {ex}')
        return 0


def http_post(url, token, body, extra_headers=None, timeout=20):
    data = json.dumps(body).encode()
    req = request.Request(url, data=data)
    req.add_header('Content-Type', 'application/json')
    req.add_header('Accept', 'application/json')
    req.add_header('Authorization', 'Bearer ' + token)
    req.add_header('User-Agent', USER_AGENT)
    for k, v in (extra_headers or {}).items():
        req.add_header(k, v)
    try:
        with request.urlopen(req, timeout=timeout) as r:
            raw = r.read()
            return r.status, json.loads(raw) if raw else {}
    except error.HTTPError as e:
        try:
            return e.code, json.loads(e.read())
        except Exception:
            return e.code, {}
    except Exception as ex:
        sys.exit(f'HTTP error ({url}): {ex}')


# ── order collection ─────────────────────────────────────────────────────────

def collect_all_orders(base, token, cf_headers):
    """Paginate GET /orders — used for the cancel pass."""
    orders = []
    cursor = None
    while True:
        url = f'{base}/orders?limit=200'
        if cursor:
            url += f'&cursor={quote(cursor)}'
        status, body, _ = http_get(url, token, cf_headers)
        if status != 200:
            sys.exit(f'GET /orders returned HTTP {status}')
        for o in body.get('items', []):
            orders.append({'id': o['id'], 'status': o.get('status', '')})
        cursor = (body.get('page') or {}).get('nextCursor')
        if not cursor:
            break
    return orders


def collect_unacknowledged_ids(base, token, cf_headers):
    """Paginate GET /erp/orders — the exact queue Celigo reads.

    This is the authoritative source for the acknowledge pass: it catches every
    order with erpSyncedAt=null regardless of whether GET /orders returns it.
    """
    ids = []
    cursor = None
    while True:
        url = f'{base}/erp/orders?limit=200'
        if cursor:
            url += f'&cursor={quote(cursor)}'
        status, body, _ = http_get(url, token, cf_headers)
        if status != 200:
            sys.exit(f'GET /erp/orders returned HTTP {status}')
        for o in body.get('items', []):
            ids.append(o['id'])
        cursor = (body.get('page') or {}).get('nextCursor')
        if not cursor:
            break
    return ids


# ── main ─────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dry-run', action='store_true',
                    help='Print what would happen without making any changes')
    args = ap.parse_args()

    env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            '..', '.env.test')
    env = load_env(env_path)
    missing = [k for k in ('SHOPWARE_URL', 'SHOPWARE_ADMIN_USER', 'SHOPWARE_ADMIN_PASSWORD')
               if not env.get(k)]
    if missing:
        sys.exit('Missing in .env.test: ' + ', '.join(missing))

    cf_id     = env.get('CF_ACCESS_CLIENT_ID')
    cf_secret = env.get('CF_ACCESS_CLIENT_SECRET')
    cf_headers = (
        {'CF-Access-Client-Id': cf_id, 'CF-Access-Client-Secret': cf_secret}
        if cf_id and cf_secret else {}
    )

    base = env['SHOPWARE_URL'].rstrip('/') + '/api/order-integration/v1'

    if args.dry_run:
        print('[dry-run] No changes will be made.\n')

    print(f'Authenticating against {env["SHOPWARE_URL"]} …')
    token = acquire_token(env, cf_headers)
    print('Token acquired.')

    print('Collecting all orders …')
    orders = collect_all_orders(base, token, cf_headers)
    print(f'Found {len(orders)} order(s).\n')

    cancelled_ok = 0
    cancel_failed = 0

    # ── Step 1: cancel ───────────────────────────────────────────────────────
    print('Step 1/2 — Cancel all non-cancelled orders')
    print('─' * 60)
    for o in orders:
        oid    = o['id']
        status = o['status']
        short  = oid[:16] + '…'

        if status == 'cancelled':
            print(f'  {short}  already cancelled')
            cancelled_ok += 1
            continue

        if args.dry_run:
            print(f'  {short}  [{status}] → would cancel')
            continue

        # Fetch ETag via HEAD to avoid downloading/parsing full order JSON
        req = request.Request(f'{base}/orders/{oid}', method='HEAD')
        req.add_header('Authorization', 'Bearer ' + token)
        req.add_header('User-Agent', USER_AGENT)
        for k, v in cf_headers.items():
            req.add_header(k, v)
        try:
            with request.urlopen(req, timeout=20) as r:
                etag = r.headers.get('ETag', '')
        except error.HTTPError as e:
            etag = e.headers.get('ETag', '')

        if not etag:
            print(f'  {short}  [{status}] → no ETag returned, skipping cancel')
            cancel_failed += 1
            continue

        result = http_delete(f'{base}/orders/{oid}', token, etag,
                             str(uuid.uuid4()), cf_headers)
        if result in (200, 204):
            print(f'  {short}  [{status}] → cancelled ✓')
            cancelled_ok += 1
        else:
            print(f'  {short}  [{status}] → DELETE returned HTTP {result} (will still acknowledge)')
            cancel_failed += 1

    # ── Step 2: acknowledge ──────────────────────────────────────────────────
    print()
    print('Step 2/2 — Acknowledge all unacknowledged orders (via ERP pull queue)')
    print('─' * 60)

    if args.dry_run:
        print('  [dry-run] Would paginate GET /erp/orders and acknowledge all found')
    else:
        print('  Collecting unacknowledged orders from GET /erp/orders …')
        unacked_ids = collect_unacknowledged_ids(base, token, cf_headers)
        print(f'  Found {len(unacked_ids)} unacknowledged order(s).')

    BATCH = 500
    total_ack = 0
    total_already = 0

    if not args.dry_run:
        for i in range(0, len(unacked_ids), BATCH):
            batch     = unacked_ids[i:i + BATCH]
            batch_num = i // BATCH + 1
            status, body = http_post(
                f'{base}/erp/orders/acknowledge',
                token,
                {'orderIds': batch},
                cf_headers,
            )
            if status != 200:
                print(f'  Batch {batch_num}: acknowledge returned HTTP {status}')
            else:
                counts = body.get('counts', {})
                ack    = counts.get('acknowledged', 0)
                alr    = counts.get('alreadySynced', 0)
                nf     = counts.get('notFound', 0)
                total_ack     += ack
                total_already += alr
                print(f'  Batch {batch_num}: acknowledged={ack}  alreadySynced={alr}  notFound={nf}')

    print()
    print('─' * 60)
    if args.dry_run:
        to_cancel = sum(1 for o in orders if o['status'] != 'cancelled')
        print(f'[dry-run] Would cancel            {to_cancel} order(s) (from GET /orders)')
        print(f'[dry-run] Would acknowledge       all orders returned by GET /erp/orders')
    else:
        print(f'Cancelled:    {cancelled_ok}  (failed: {cancel_failed})')
        print(f'Acknowledged: {total_ack}  (already synced: {total_already})')
        print()
        print('GET /erp/orders will now return no results for these orders.')


if __name__ == '__main__':
    main()
