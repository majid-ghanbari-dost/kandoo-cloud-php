-- Direct-peer administrative request throttling; no credentials or customer data.
CREATE TABLE admin_rate_buckets (
    bucket_key VARCHAR(64) NOT NULL,
    window_start BIGINT NOT NULL,
    hits INT NOT NULL,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
