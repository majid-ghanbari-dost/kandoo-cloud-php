-- 0002_rate_limits.sql — DB-backed fixed 1-minute rate-limit windows (PM ORDER 9 P-03).
-- public_rate_buckets: one row per client address for GET /i/{token} (§6.2, default 60/min).
-- ingest_rate_buckets: one row per store for POST …/operation-batches (§5.4 note, U5, default 120/min).
-- Window semantics: window_start = floor(unix_utc/60)*60; the row is swapped atomically on
-- upsert; denied requests do NOT increment. Rows are pruned by bin/cron.php. Not contract-visible.

CREATE TABLE public_rate_buckets (
    bucket_key   VARCHAR(64) NOT NULL,
    window_start BIGINT      NOT NULL,
    hits         INT         NOT NULL,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE ingest_rate_buckets (
    bucket_key   VARCHAR(36) NOT NULL,
    window_start BIGINT      NOT NULL,
    hits         INT         NOT NULL,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
