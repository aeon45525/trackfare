CREATE TABLE IF NOT EXISTS phone_nfc_credentials (
    credential_id CHAR(32) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    public_key VARCHAR(2048) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (credential_id),
    UNIQUE KEY uq_phone_nfc_user (user_id),
    CONSTRAINT fk_phone_nfc_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS phone_nfc_challenges (
    challenge CHAR(32) NOT NULL,
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (challenge),
    KEY idx_phone_nfc_challenge_used_at (used_at)
);