-- P-07 foundations: a delivered announcement is an actual SMS delivery record,
-- never inferred merely from the existence of an invoice. P-08 supplies delivery.
CREATE TABLE cloud_customer_phones (
    phone VARCHAR(32) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE cloud_sms_announcements (
    announcement_id CHAR(36) NOT NULL PRIMARY KEY,
    store_id CHAR(36) NOT NULL,
    invoice_id CHAR(36) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    delivered_at BIGINT NOT NULL,
    code_issues INT NOT NULL DEFAULT 0,
    KEY ix_announcement_phone (phone, delivered_at),
    CONSTRAINT fk_announcement_invoice FOREIGN KEY (store_id, invoice_id)
        REFERENCES public_invoices (store_id, invoice_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Serialize each idempotency key across different phone numbers too.
CREATE TABLE cloud_otp_idempotency_locks (
    idempotency_hash CHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE cloud_otp_challenges (
    challenge_id CHAR(36) NOT NULL PRIMARY KEY,
    phone VARCHAR(32) NOT NULL,
    announcement_id CHAR(36) NOT NULL,
    idempotency_hash CHAR(64) NOT NULL UNIQUE,
    code_mac CHAR(64) NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    consumed TINYINT(1) NOT NULL DEFAULT 0,
    delivery_state VARCHAR(16) NOT NULL,
    KEY ix_challenge_phone_time (phone, created_at),
    CONSTRAINT fk_otp_announcement FOREIGN KEY (announcement_id)
        REFERENCES cloud_sms_announcements (announcement_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE cloud_customer_sessions (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    phone VARCHAR(32) NOT NULL,
    expires_at BIGINT NOT NULL,
    KEY ix_customer_session_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
