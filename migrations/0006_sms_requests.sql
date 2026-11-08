-- Each announcement operation is reserved before contacting the provider.
CREATE TABLE cloud_sms_delivery_requests (
    request_id CHAR(36) NOT NULL PRIMARY KEY,
    store_id CHAR(36) NOT NULL,
    invoice_id CHAR(36) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    state VARCHAR(16) NOT NULL,
    provider_receipt VARCHAR(128) NULL,
    created_at BIGINT NOT NULL,
    CONSTRAINT fk_sms_request_invoice FOREIGN KEY (store_id, invoice_id)
        REFERENCES public_invoices (store_id, invoice_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
