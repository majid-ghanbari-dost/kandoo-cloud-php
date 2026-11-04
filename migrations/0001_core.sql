-- 0001_core.sql — pilot schema for فاکتور دیجیتال کندو (Kandoo Digital Invoice) Cloud.
-- MariaDB 10.6 / InnoDB / utf8mb4 with binary collation for IDs and hashes (mirrors the
-- frozen Postgres byte-exact comparison semantics). Column mapping: CLOUD-CONTRACT.md §10.
-- No credentials or customer master data live here; the Cloud stores only the replicated
-- log plus the compact public projection (decision D5).

CREATE TABLE cloud_stores (
    store_id                CHAR(36)   NOT NULL,
    active_writer_device_id CHAR(36)   NOT NULL,
    active_writer_epoch     BIGINT     NOT NULL,
    accepted_through_seq    BIGINT     NOT NULL,
    entry_count             BIGINT     NOT NULL,
    head_entry_hash         CHAR(64)   NULL,
    projection_version      BIGINT     NOT NULL,
    throttled               TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE cloud_entries (
    store_id      CHAR(36)     NOT NULL,
    operation_seq BIGINT       NOT NULL,
    operation_id  VARCHAR(191) NOT NULL,
    entry_hash    CHAR(64)     NOT NULL,
    entry_json    LONGTEXT     NOT NULL,
    PRIMARY KEY (store_id, operation_seq),
    UNIQUE KEY ux_cloud_entries_operation (store_id, operation_id),
    CONSTRAINT fk_cloud_entries_store
        FOREIGN KEY (store_id) REFERENCES cloud_stores (store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE cloud_batches (
    store_id    CHAR(36)     NOT NULL,
    batch_id    VARCHAR(191) NOT NULL,
    fingerprint CHAR(64)     NOT NULL,
    PRIMARY KEY (store_id, batch_id),
    CONSTRAINT fk_cloud_batches_store
        FOREIGN KEY (store_id) REFERENCES cloud_stores (store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE public_invoices (
    public_token          VARCHAR(128) NOT NULL,
    public_token_hash     CHAR(64)     NOT NULL,
    digital_invoice_id    CHAR(36)     NOT NULL,
    invoice_id            CHAR(36)     NOT NULL,
    store_id              CHAR(36)     NOT NULL,
    store_name            VARCHAR(255) NOT NULL,
    display_number        BIGINT       NOT NULL,
    lifecycle_status      VARCHAR(16)  NOT NULL,
    issued_at             DATETIME(6)  NOT NULL,
    issued_payload        LONGTEXT     NOT NULL,
    issued_content_sha256 CHAR(64)     NOT NULL,
    payable_rials         BIGINT       NOT NULL,
    line_items            LONGTEXT     NOT NULL,
    customer_phone        VARCHAR(32)  NULL,
    -- Proposed v1.1 passthrough columns (§11, UNCONFIRMED U14): recorded AS DELIVERED
    -- only when the Hub emits them; never computed, never assumed, never enriched.
    customer_name         VARCHAR(255) NULL,
    payments              LONGTEXT     NULL,
    currency_unit         VARCHAR(8)   NULL,
    remaining_balance     BIGINT       NULL,
    PRIMARY KEY (public_token),
    UNIQUE KEY ux_public_invoices_token_hash (public_token_hash),
    UNIQUE KEY ux_public_invoices_store_invoice (store_id, invoice_id),
    KEY ix_public_invoices_digital (digital_invoice_id),
    CONSTRAINT fk_public_invoices_store
        FOREIGN KEY (store_id) REFERENCES cloud_stores (store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
