-- CQRS read projection + write queue schema (PostgreSQL).
-- Applied automatically by docker-compose.cqrs.yml on first start, or run
-- manually:  psql "$ORDER_INTEGRATION_DB_DSN" -f docs/sql/order_integration_schema.sql

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
CREATE INDEX IF NOT EXISTS idx_owq_claim ON order_write_queue (status, available_at, created_at);
-- supports the retention purge (DELETE WHERE status = ... AND updated_at < ...)
CREATE INDEX IF NOT EXISTS idx_owq_purge ON order_write_queue (status, updated_at);
