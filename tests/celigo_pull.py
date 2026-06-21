#!/usr/bin/env python3
"""
Celigo pull diagnostic for the D2C → ERP → SCA order flow.

Fetches unacknowledged orders from GET /erp/orders and prints them in
the shape of the SCA push payload (OrderExport-ERP-SCA.yaml), showing
for every field:
  • the API source path
  • the actual value pulled from the shop
  • or "── ERP origin ──" for fields the shop cannot supply
    (invoiceDate, invoiceNo, customerNo, per-line net/VAT, incoterm)

Stdlib only. Config from ../.env.test (same as benchmark.py).

Usage
-----
    python3 tests/celigo_pull.py [--limit N]      # default 10
    python3 tests/celigo_pull.py --limit 1        # one order, verbose
"""
import argparse, json, os, sys, textwrap
from urllib import request, error

# ── sentinel strings ────────────────────────────────────────────────────────
ERP_ORIGIN = "── ERP origin ──"   # field must be filled by the ERP/NAV
NA         = "—"                   # shop field exists but value is null/empty


# ── env / http helpers (same pattern as benchmark.py) ───────────────────────

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


def http_get(url, token, extra_headers=None, user_agent=None, timeout=20):
    req = request.Request(url)
    req.add_header('Accept', 'application/json')
    if user_agent:
        req.add_header('User-Agent', user_agent)
    req.add_header('Authorization', 'Bearer ' + token)
    for k, v in (extra_headers or {}).items():
        req.add_header(k, v)
    try:
        with request.urlopen(req, timeout=timeout) as r:
            return r.status, json.loads(r.read())
    except error.HTTPError as e:
        return e.code, {}
    except Exception as ex:
        sys.exit(f'HTTP error: {ex}')


def acquire_token(env, extra_headers=None, user_agent=None):
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
    if user_agent:
        req.add_header('User-Agent', user_agent)
    for k, v in (extra_headers or {}).items():
        req.add_header(k, v)
    try:
        with request.urlopen(req, timeout=20) as r:
            return json.loads(r.read())['access_token']
    except Exception as ex:
        sys.exit(f'Auth failed: {ex}')


# ── formatting helpers ───────────────────────────────────────────────────────

COL_W = (26, 34, 42)   # SCA field | API source | value


def _sep(widths=COL_W, char='─'):
    return '  ' + char * (sum(widths) + len(widths) * 2)


def _row(f, s, v, widths=COL_W):
    cols = [str(f), str(s), str(v)]
    # Truncate each column to its max width
    parts = [c[:w].ljust(w) for c, w in zip(cols, widths)]
    return '  ' + '  '.join(parts)


def _section(title):
    print()
    print(f'  {title}')
    print(_sep())
    print(_row('SCA field', 'API source', 'value'))
    print(_sep())


def _order_header(order, idx, total):
    oid = order.get('id', '?')[:16] + '…'
    num = order.get('orderNumber', '?')
    status = order.get('status', '?')
    pstatus = order.get('paymentStatus', '?')
    print()
    bar = '═' * 72
    print(f'  {bar}')
    print(f'  ORDER {idx}/{total}   #{num}   id:{oid}   '
          f'status:{status}  payment:{pstatus}')
    print(f'  {bar}')


def v(val, default=NA):
    """Return val if truthy, else the default sentinel."""
    if val is None or val == '' or val == []:
        return default
    return val


def money(m):
    if not m or m.get('amount') is None:
        return NA
    return f"{m['amount']:.2f} {m.get('currency', '')}"


def truncate(s, n=38):
    s = str(s)
    return s[:n - 1] + '…' if len(s) > n else s


# ── per-section renderers ────────────────────────────────────────────────────

def render_order_level(order):
    _section('SALES ORDER')
    total  = order.get('total',    {}) or {}
    tax    = order.get('tax',      {}) or {}
    ship   = order.get('shipping', {}) or {}

    # amountWithoutVAT: computable as total - tax
    total_amt = total.get('amount')
    tax_amt   = tax.get('amount')
    if total_amt is not None and tax_amt is not None:
        net_order = f'{(total_amt - tax_amt):.2f} {total.get("currency","")}'
    else:
        net_order = NA

    # shipper: first delivery's carrier or shippingMethod (embedded)
    deliveries = order.get('deliveries') or []
    d0 = deliveries[0] if deliveries else {}
    shipper = v(d0.get('carrier')) or v(d0.get('shippingMethod'))

    rows = [
        ('orderNo',           'orderNumber',               v(order.get('orderNumber'))),
        ('orderDate',         'createdAt',                 v(order.get('createdAt'))),
        ('invoiceDate',       ERP_ORIGIN,                  '(NAV: set on invoice posting)'),
        ('invoiceNo',         ERP_ORIGIN,                  '(NAV: assigned on posting)'),
        ('currencyCode',      'currency',                  v(order.get('currency'))),
        ('amountIncludingVAT','total.amount',              money(total)),
        ('amountWithoutVAT',  'total.amount − tax.amount', net_order),
        ('incoterm',          ERP_ORIGIN,                  '(not a shop field)'),
        ('shipper',           'deliveries[0].carrier',     shipper),
    ]
    for r in rows:
        print(_row(*r))


def render_address(label, addr, email):
    _section(label)
    if not addr:
        print(_row('(no address)', '', NA))
        return
    src = label  # preserve camelCase in source column (e.g. billingAddress)
    rows = [
        ('customerNo',  ERP_ORIGIN,                   '(NAV customer master lookup)'),
        ('firstName',   f'{src}.firstName',            v(addr.get('firstName'))),
        ('lastName',    f'{src}.lastName',             v(addr.get('lastName'))),
        ('companyName', f'{src}.company',              v(addr.get('company'))),
        ('address',     f'{src}.street',               v(addr.get('street'))),
        ('address2',    f'{src}.additionalAddressLine1', v(addr.get('additionalAddressLine1'))),
        ('state',       f'{src}.stateCode',            v(addr.get('stateCode'))),
        ('postCode',    f'{src}.zipcode',              v(addr.get('zipcode'))),
        ('city',        f'{src}.city',                 v(addr.get('city'))),
        ('countryCode', f'{src}.countryCode',          v(addr.get('countryCode'))),
        ('email',       'customer.email',              v(email)),
        ('phone',       f'{src}.phoneNumber',          v(addr.get('phoneNumber'))),
    ]
    for r in rows:
        print(_row(*r))


def render_line_items(order):
    items = order.get('lineItems') or []
    print()
    print(f'  LINE ITEMS ({len(items)})')
    print(_sep())

    # column headers for the line-items mini-table
    h_widths = (4, 12, 14, 32, 5, 12, 12, 12)
    headers  = ('#', 'type', 'itemNo/sku', 'description', 'qty',
                'grossTotal', 'netTotal', 'vat')
    hline = '  ' + '  '.join(str(h).ljust(w) for h, w in zip(headers, h_widths))
    print(hline)
    print('  ' + '─' * (sum(h_widths) + len(h_widths) * 2))

    for i, item in enumerate(items, 1):
        # itemNo: prefer sku; fall back to productNumber from payload
        sku = item.get('sku')
        if not sku:
            sku = (item.get('payload') or {}).get('productNumber')
        item_no = v(sku)

        gross = money(item.get('totalPrice'))

        cols = (
            str(i),
            v(item.get('type')),
            item_no,
            truncate(v(item.get('label')), 30),
            str(v(item.get('quantity'))),
            gross,
            ERP_ORIGIN,   # netTotal — not returned per line
            ERP_ORIGIN,   # vat — not returned per line
        )
        line = '  ' + '  '.join(str(c).ljust(w) for c, w in zip(cols, h_widths))
        print(line)

    print()
    print('  Note: netTotal and vat per line are ERP-origin — only gross total')
    print('        is available from the shop. amountWithoutVAT at order level')
    print('        is computable as total.amount − tax.amount.')


def render_delivery_note(order):
    deliveries = order.get('deliveries') or []
    print()
    print(f'  DELIVERIES ({len(deliveries)} embedded)')
    print(_sep())
    if not deliveries:
        print(_row('shipper', 'deliveries[0].carrier', NA + '  (no delivery on order)'))
        return

    for i, d in enumerate(deliveries):
        label = f'delivery[{i}]'
        carrier   = v(d.get('carrier'))
        method    = v(d.get('shippingMethod'))
        tracking  = ', '.join(
            (t.get('code') or '') for t in (d.get('trackingCodes') or [])
        ) or NA
        status    = v(d.get('status'))
        ship_date = v(d.get('actualShipDate') or d.get('plannedShipDate'))
        print(_row('shipper (carrier)',    f'{label}.carrier',        carrier))
        print(_row('shipper (method)',     f'{label}.shippingMethod', method))
        print(_row('trackingCodes',        f'{label}.trackingCodes',  truncate(tracking, 40)))
        print(_row('deliveryStatus',       f'{label}.status',         status))
        print(_row('shipDate',             f'{label}.plannedShipDate',ship_date))
        if i < len(deliveries) - 1:
            print()


# ── main ─────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--limit', type=int, default=10,
                    help='Max unacknowledged orders to fetch (default: 10)')
    args = ap.parse_args()

    env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            '..', '.env.test')
    env = load_env(env_path)
    missing = [k for k in ('SHOPWARE_URL', 'SHOPWARE_ADMIN_USER', 'SHOPWARE_ADMIN_PASSWORD')
               if not env.get(k)]
    if missing:
        sys.exit('Missing in .env.test: ' + ', '.join(missing))

    # Cloudflare Access service-token headers — only sent when both vars are set.
    # Required when SHOPWARE_URL is a public endpoint protected by CF Access WAF;
    # omitted automatically for local / internal URLs where CF is not in the path.
    cf_id     = env.get('CF_ACCESS_CLIENT_ID')
    cf_secret = env.get('CF_ACCESS_CLIENT_SECRET')
    cf_headers = (
        {'CF-Access-Client-Id': cf_id, 'CF-Access-Client-Secret': cf_secret}
        if cf_id and cf_secret else {}
    )
    user_agent = env.get(
        'CELIGO_PULL_USER_AGENT',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
        'AppleWebKit/537.36 (KHTML, like Gecko) '
        'Chrome/126.0.0.0 Safari/537.36',
    )

    base = env['SHOPWARE_URL'].rstrip('/') + '/api/order-integration/v1'

    via = 'via Cloudflare Access service token' if cf_headers else 'directly'
    print(f'Authenticating against {env["SHOPWARE_URL"]} {via} …')
    token = acquire_token(env, cf_headers, user_agent=user_agent)

    url = f'{base}/erp/orders?limit={args.limit}'
    print(f'Pulling unacknowledged orders: GET {url}')
    status, body = http_get(url, token, cf_headers, user_agent=user_agent)
    if status != 200:
        sys.exit(f'GET /erp/orders returned HTTP {status}')

    orders = body.get('items', [])
    cursor = (body.get('page') or {}).get('nextCursor')

    print(f'Fetched {len(orders)} unacknowledged order(s)'
          + (f'  [more available — nextCursor present]' if cursor else '') + '.')

    if not orders:
        print()
        print('  Nothing to display. Either all orders are already acknowledged')
        print('  (customFields.erpSyncedAt is set) or no orders exist yet.')
        print()
        print('  Tip: run tests/create_test_order.sh or tests/api_test.sh to seed orders,')
        print('       then re-run this script before calling POST /erp/orders/acknowledge.')
        return

    print()
    print('  Legend')
    print('  ──────')
    print(f'  {ERP_ORIGIN!r:<36} field cannot be sourced from the shop;')
    print('                                        must be supplied by NAV / Celigo mapping.')
    print(f'  {NA!r:<36} shop field exists but value is null or empty on this order.')

    total = len(orders)
    for idx, order in enumerate(orders, 1):
        email = (order.get('customer') or {}).get('email')
        _order_header(order, idx, total)
        render_order_level(order)
        render_address('billingAddress', order.get('billingAddress'), email)
        render_address('shippingAddress', order.get('shippingAddress'), email)
        render_line_items(order)
        render_delivery_note(order)

    print()
    print('─' * 74)
    print(f'  {total} order(s) displayed. None acknowledged — re-run after ERP processing')
    print('  or call POST /erp/orders/acknowledge to mark them as synced.')
    print()


if __name__ == '__main__':
    main()
