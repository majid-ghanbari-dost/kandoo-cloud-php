-- Last successful scheduled maintenance only; no customer or secret data.
CREATE TABLE cloud_cron_heartbeat (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    completed_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
