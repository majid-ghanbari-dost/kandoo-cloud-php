-- Default-off administrative surface and durable transition audit.
CREATE TABLE cloud_admin_state (
    singleton_id TINYINT NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
INSERT INTO cloud_admin_state (singleton_id, enabled) VALUES (1, 0);
CREATE TABLE cloud_admin_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(16) NOT NULL,
    occurred_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
