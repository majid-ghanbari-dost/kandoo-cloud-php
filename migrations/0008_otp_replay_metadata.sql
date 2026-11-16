-- Keep OTP replay metadata independent from short-lived challenge secrets.
ALTER TABLE cloud_otp_idempotency_locks
    ADD COLUMN created_at BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN phone VARCHAR(32) NULL,
    ADD COLUMN device_hash CHAR(64) NULL,
    ADD COLUMN challenge_id CHAR(36) NULL,
    ADD COLUMN expires_at BIGINT NULL,
    ADD COLUMN delivery_state VARCHAR(16) NULL;
UPDATE cloud_otp_idempotency_locks l JOIN cloud_otp_challenges c ON c.idempotency_hash=l.idempotency_hash
SET l.created_at=c.created_at, l.phone=c.phone, l.device_hash=c.device_hash,
    l.challenge_id=c.challenge_id, l.expires_at=c.expires_at, l.delivery_state=c.delivery_state;
