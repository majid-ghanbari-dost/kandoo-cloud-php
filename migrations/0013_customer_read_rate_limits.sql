-- 0013_customer_read_rate_limits.sql — independent read throttling for the customer API
-- (PHP-B: P-31 finish + P-34 local security). One row per bucket key; two bucket families
-- share the table and are distinguished by a keyed prefix inside the hashed key:
--   ip:<hmac(client address)>        — bounds unauthenticated token guessing from one peer
--   session:<hmac(session bearer)>   — bounds replay of one stolen but valid session
-- Keys are keyed digests only: no raw address and no raw token is ever stored.
-- Window = floor(unix_utc/60)*60, swapped atomically; denied requests are not counted.
-- Not contract-visible. Pruned by bin/cron.php together with the other bucket tables.

CREATE TABLE customer_read_rate_buckets (
    bucket_key   CHAR(64) NOT NULL PRIMARY KEY,
    window_start BIGINT   NOT NULL,
    hits         INT      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
