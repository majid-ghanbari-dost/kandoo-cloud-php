-- Device credentials are provisioned out of band; only SHA-256 digests are retained.
CREATE TABLE cloud_replication_devices (
    device_id CHAR(36) NOT NULL PRIMARY KEY,
    store_id CHAR(36) NOT NULL,
    secret_sha256 CHAR(64) NOT NULL,
    registered_at DATETIME(6) NOT NULL,
    last_authenticated_at DATETIME(6) NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_replication_device_store FOREIGN KEY (store_id)
        REFERENCES cloud_stores (store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
