-- Device identities are opaque random tokens; only keyed digests are stored.
CREATE TABLE cloud_customer_devices (
    device_hash CHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE cloud_customer_device_numbers (
    device_hash CHAR(64) NOT NULL,
    day_start BIGINT NOT NULL,
    phone_hash CHAR(64) NOT NULL,
    PRIMARY KEY (device_hash, day_start, phone_hash),
    CONSTRAINT fk_device_number_device FOREIGN KEY (device_hash) REFERENCES cloud_customer_devices(device_hash) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
ALTER TABLE cloud_otp_challenges ADD COLUMN device_hash CHAR(64) NULL;
CREATE TABLE customer_issue_rate_buckets (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    window_start BIGINT NOT NULL,
    hits INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE customer_verify_rate_buckets (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    window_start BIGINT NOT NULL,
    hits INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
