-- Demo-only local messages. Never used as evidence of actual SMS delivery.
CREATE TABLE cloud_demo_sms_outbox (
    message_id CHAR(36) NOT NULL PRIMARY KEY,
    phone VARCHAR(32) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    body TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    KEY ix_demo_sms_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
