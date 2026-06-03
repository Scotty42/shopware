# Infrastructure setup — CQRS read DB + write queue (Proxmox LXC)

The CQRS read projection and the write queue (T13) need a fast, dedicated
database **separate from Shopware's MariaDB**, so read/queue load never competes
with the shop's checkout DB. Consistent with the rest of this project (Proxmox
LXC, Debian Trixie — see the README deployment topology), the reference setup is
a **dedicated LXC container running PostgreSQL** (Trixie's default, PostgreSQL
17). One DB holds both the `order_read_projection` (JSONB) and the
`order_write_queue` (claimed with `FOR UPDATE SKIP LOCKED` for safe parallel
workers).

```
  LXC: shopware-be                         LXC: order-integration-db
  ┌────────────────────────────┐           ┌──────────────────────────┐
  │ Apache + PHP-FPM + Shopware │  TCP 5432 │ PostgreSQL 17            │
  │ OrderIntegration plugin     ├──────────►│  order_read_projection   │
  │ write-queue worker (systemd)│  LAN      │  order_write_queue        │
  └────────────────────────────┘           └──────────────────────────┘
```

> Alternatives: the queue can also run on Redis Streams, RabbitMQ, SQS or Kafka,
> and the projection on OpenSearch/Redis. PostgreSQL is chosen here because one
> container covers both and SKIP LOCKED gives correct multi-worker claiming with
> no extra broker. Swap by providing a different `WriteQueueInterface` /
> `ReadProjectionInterface` binding.

## 1. Provision the LXC container

On the **Proxmox host** (adjust storage, bridge, and CTID to your environment):

```bash
# Debian 13 (Trixie) container for the read/queue DB
pct create 210 local:vztmpl/debian-13-standard_13.0-1_amd64.tar.zst \
  --hostname order-integration-db \
  --cores 2 --memory 2048 --swap 512 \
  --rootfs local-lvm:8 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp \
  --unprivileged 1 \
  --onboot 1

pct start 210
pct exec 210 -- bash -lc 'apt-get update && apt-get install -y postgresql'
```

Give it a stable LAN name (e.g. `order-integration-db.lan.internal`) via your DNS
or the BE container's `/etc/hosts`, mirroring `shopware-be.lan.internal`.

## 2. Configure PostgreSQL (inside the container)

Open it to the LAN and restrict access to the Shopware BE container:

```bash
pct exec 210 -- bash -lc "
  PG=\$(ls -d /etc/postgresql/*/main | head -1)
  sed -i \"s/^#*listen_addresses.*/listen_addresses = '*'/\" \$PG/postgresql.conf
  # allow only the shopware-be host (replace with its IP/subnet)
  echo 'host  order_integration  order_integration  10.0.0.0/24  scram-sha-256' >> \$PG/pg_hba.conf
  systemctl restart postgresql
"
```

Create the role, database, and schema:

```bash
pct exec 210 -- sudo -u postgres psql -v ON_ERROR_STOP=1 <<'SQL'
CREATE ROLE order_integration LOGIN PASSWORD 'change-me';
CREATE DATABASE order_integration OWNER order_integration;
SQL

# apply the schema (copy the file into the container first, or pipe it)
pct exec 210 -- sudo -u postgres psql -d order_integration \
  -f /root/order_integration_schema.sql
```

The schema lives in `docs/sql/order_integration_schema.sql` (reproduced in §3).
Copy it in with `pct push 210 docs/sql/order_integration_schema.sql /root/order_integration_schema.sql`.

## 3. Schema

`docs/sql/order_integration_schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS order_read_projection (
    id               TEXT PRIMARY KEY,
    status           TEXT,
    sales_channel_id TEXT,
    created_at       TIMESTAMPTZ,
    updated_at       TIMESTAMPTZ,
    data             JSONB NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orp_status     ON order_read_projection (status);
CREATE INDEX IF NOT EXISTS idx_orp_created_id ON order_read_projection (created_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS order_write_queue (
    id              TEXT PRIMARY KEY,
    type            TEXT NOT NULL,
    payload         JSONB NOT NULL,
    idempotency_key TEXT UNIQUE,
    status          TEXT NOT NULL DEFAULT 'queued',
    attempts        INT  NOT NULL DEFAULT 0,
    max_attempts    INT  NOT NULL DEFAULT 5,
    available_at    TIMESTAMPTZ,
    last_error      TEXT,
    result          JSONB,
    created_at      TIMESTAMPTZ NOT NULL,
    updated_at      TIMESTAMPTZ NOT NULL
);
-- the claim() query filters on (status, available_at) ordered by created_at
CREATE INDEX IF NOT EXISTS idx_owq_claim ON order_write_queue (status, available_at, created_at);
```

The Shopware BE container needs the PostgreSQL PDO driver:

```bash
pct exec <shopware-be-ctid> -- apt-get install -y php-pgsql
```

## 4. Configuration

### Testing (`.env.test`, from `.env.test.dist`)

```dotenv
ORDER_INTEGRATION_ASYNC_WRITES=true
ORDER_INTEGRATION_PROJECTION_READS=true
ORDER_INTEGRATION_DB_DSN=pgsql:host=order-integration-db.lan.internal;port=5432;dbname=order_integration
ORDER_INTEGRATION_DB_USER=order_integration
ORDER_INTEGRATION_DB_PASSWORD=change-me
```

Then run the async integration test from the BE container:

```bash
SHOPWARE_CONSOLE="php /var/www/shopware/bin/console" bash tests/write_queue_test.sh
```

The feature flags default to **off** in `.env.test.dist`, so the rest of the
suite runs unchanged unless you opt in.

### Production

Set the same variables in the Shopware environment on the BE container (`.env` /
secret manager). The PDO connection is opened lazily, so with the flags off and
no DSN the plugin keeps its synchronous, DAL-backed behaviour and nothing
breaks.

Recommended rollout (matches `order-api-concept.md` §2):

1. Provision the LXC DB, apply the schema, leave both flags **off**.
2. Turn on `ORDER_INTEGRATION_PROJECTION_READS` — the `OrderProjectionSubscriber`
   has been populating the projection from `order.written`/`order.deleted`
   events; reads now come from the projection.
3. Turn on `ORDER_INTEGRATION_ASYNC_WRITES` — writes are queued and answered with
   `202` + a job URL.

> First-time projection backfill: replay existing orders once (e.g. a small
> script that loads all orders and calls `OrderProjectionWriter::apply`), since
> the subscriber only captures changes from when it is active.

## 5. Worker (on the shopware-be LXC)

Run one or more drain workers (SKIP LOCKED keeps them from colliding):

```bash
php /var/www/shopware/bin/console order-integration:write-queue:drain --sleep=1
```

systemd unit on the BE container (`/etc/systemd/system/order-integration-worker.service`):

```ini
[Unit]
Description=Order Integration write-queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/shopware
ExecStart=/usr/bin/php bin/console order-integration:write-queue:drain --sleep=1
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now order-integration-worker
```

Scale throughput by enabling several instances (a templated
`order-integration-worker@.service`, or copies `@1`, `@2`, …) — each claims
disjoint batches thanks to SKIP LOCKED.

## 6. Operational notes

- **Backpressure:** when the queue depth crosses `BackpressurePolicy`'s limit,
  async `POST`s return `503` + `Retry-After` instead of overloading Shopware.
- **Dead letters:** commands that exhaust `max_attempts` move to `status='dead'`;
  monitor `SELECT count(*) FROM order_write_queue WHERE status='dead'`.
- **Idempotency:** `idempotency_key` is unique, so a retried enqueue returns the
  original job instead of duplicating the write.
- **Backups:** include the LXC container / its PostgreSQL data in the Proxmox
  backup schedule (`vzdump`), like the other containers.
