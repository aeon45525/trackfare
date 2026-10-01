CREATE TABLE IF NOT EXISTS payment_requests (
    request_id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    merchant_name   VARCHAR(120) NOT NULL,
    description     VARCHAR(180) NULL,
    amount          DECIMAL(10,2) NULL,
    min_amount      DECIMAL(10,2) NULL,
    max_amount      DECIMAL(10,2) NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'PHP',
    status          ENUM('pending','paid','expired','cancelled') NOT NULL DEFAULT 'pending',
    expires_at      DATETIME NOT NULL,
    paid_by         INT UNSIGNED NULL,
    paid_at         DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id_hash),
    KEY idx_payment_request_status_expiry (status, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wallet_payment_transactions (
    transaction_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount           DECIMAL(10,2) NOT NULL,
    currency         CHAR(3) NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    UNIQUE KEY uq_wallet_payment_request (request_id_hash),
    UNIQUE KEY uq_wallet_payment_idempotency (idempotency_hash),
    KEY idx_wallet_payment_user_created (user_id, created_at)
) ENGINE=InnoDB;