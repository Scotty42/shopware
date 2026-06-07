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
-- supports the retention purge
CREATE INDEX IF NOT EXISTS idx_owq_purge ON order_write_queue (status, updated_at);
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

Drain workers apply queued commands to Shopware. `FOR UPDATE SKIP LOCKED` lets
several workers drain in parallel without ever claiming the same command twice,
so scaling throughput is just "run more instances".

Use a **templated** systemd unit so N workers come from one file —
`/etc/systemd/system/order-integration-worker@.service`:

```ini
[Unit]
Description=Order Integration write-queue worker #%i
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/shopware
ExecStart=/usr/bin/php bin/console order-integration:write-queue:drain --sleep=1
Restart=always
RestartSec=2
SyslogIdentifier=oi-worker-%i

[Install]
WantedBy=multi-user.target
```

`%i` is the instance id (1, 2, …); it only labels the description/log tag — the
drain command is identical for every instance.

```bash
systemctl daemon-reload
# run two workers (add @3, @4, … to scale further)
systemctl enable --now order-integration-worker@1.service order-integration-worker@2.service
systemctl status 'order-integration-worker@*'
journalctl -fu 'order-integration-worker@1' -fu 'order-integration-worker@2'
```

End-to-end queue-drain time scales roughly linearly with the worker count, as
long as Shopware (not the CQRS DB) is the bottleneck: in the reference benchmark,
1 → 2 workers roughly halved the write-completion p95 (see `docs/benchmark.md`).
`--sleep=1` avoids a busy-loop when the queue is empty; `--sleep=0` squeezes out
a little more throughput under constant load at the cost of idle CPU.

> Migrating from an earlier single `order-integration-worker.service`:
> ```bash
> systemctl disable --now order-integration-worker.service
> rm -f /etc/systemd/system/order-integration-worker.service
> ```
> then install the template above and enable `@1`, `@2`.

## 6.5 Retention & cleanup

The two tables behave oppositely:

- **`order_read_projection` — bounded, no time-based cleanup.** One row per
  order (upsert on `order.written`, delete on `order.deleted`), so it tracks the
  Shopware order count and follows your order / GDPR retention automatically.
  Only consider a periodic **reconciliation** if orders are ever purged outside
  the DAL (raw SQL), which would leave orphan rows:
  ```sql
  -- orphans: projection rows whose order no longer exists (run against Shopware DB-aware tooling,
  -- or re-backfill the projection from OrderProjectionWriter::apply over all orders)
  ```

- **`order_write_queue` — grows unbounded, needs periodic purge.** The worker
  marks finished commands `succeeded` / `dead` but never deletes them. Purge old
  terminal rows on a schedule (active `queued` / `in_progress` rows are never
  touched):
  ```bash
  bin/console order-integration:write-queue:purge --succeeded-after=7d --dead-after=30d
  ```
  Keep `succeeded` long enough that clients can still poll `GET /v1/jobs/{id}`;
  keep `dead` longer for triage. Index `idx_owq_purge (status, updated_at)`
  backs the delete.

  systemd timer (`/etc/systemd/system/order-integration-purge.{service,timer}`):
  ```ini
  # order-integration-purge.service
  [Unit]
  Description=Order Integration write-queue retention purge
  [Service]
  Type=oneshot
  User=www-data
  WorkingDirectory=/var/www/shopware
  ExecStart=/usr/bin/php bin/console order-integration:write-queue:purge --succeeded-after=7d --dead-after=30d
  ```
  ```ini
  # order-integration-purge.timer
  [Unit]
  Description=Daily Order Integration write-queue purge
  [Timer]
  OnCalendar=daily
  Persistent=true
  [Install]
  WantedBy=timers.target
  ```
  ```bash
  systemctl enable --now order-integration-purge.timer
  ```

- **Idempotency store** (FilesystemAdapter, not in Postgres): self-expiring with
  a 24 h TTL — no maintenance.

## 6. Operational notes

- **Backpressure:** when the queue depth crosses `BackpressurePolicy`'s limit,
  async `POST`s return `503` + `Retry-After` instead of overloading Shopware.
- **Dead letters:** commands that exhaust `max_attempts` move to `status='dead'`;
  monitor `SELECT count(*) FROM order_write_queue WHERE status='dead'`.
- **Idempotency:** `idempotency_key` is unique, so a retried enqueue returns the
  original job instead of duplicating the write.
- **Backups:** include the LXC container / its PostgreSQL data in the Proxmox
  backup schedule (`vzdump`), like the other containers.
