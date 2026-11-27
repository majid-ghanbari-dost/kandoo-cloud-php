-- P-32 (ORDER 16): administrative surface v2 — device revocation, audit detail, SMS cost log.
-- Additive only; existing columns and rows are untouched.
ALTER TABLE cloud_replication_devices
    ADD COLUMN revoked_at DATETIME(6) NULL,
    ADD COLUMN revoked_reason VARCHAR(255) NULL;
ALTER TABLE cloud_admin_events
    ADD COLUMN detail VARCHAR(255) NULL;
CREATE TABLE cloud_sms_cost_log (
    entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id CHAR(36) NULL,
    store_id CHAR(36) NOT NULL,
    pattern VARCHAR(32) NOT NULL,
    units INT NOT NULL DEFAULT 1,
    unit_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'IRR',
    cost_date DATE NOT NULL,
    created_at BIGINT NOT NULL,
    UNIQUE KEY uq_sms_cost_request (request_id),
    KEY idx_sms_cost_date (cost_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
