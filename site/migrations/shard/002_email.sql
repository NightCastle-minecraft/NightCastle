-- Почта игрока (D18): обязательна при регистрации, подтверждается ссылкой из письма.
ALTER TABLE accounts
    ADD COLUMN email VARCHAR(254) NULL,
    ADD COLUMN email_verified_at DATETIME NULL;

-- Ссылки из писем: подтверждение почты и сброс пароля. Хранится только sha256 токена.
CREATE TABLE email_tokens (
    hash CHAR(64) NOT NULL PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(10) NOT NULL,
    email VARCHAR(254) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    KEY idx_email_tokens_account (account_id, purpose),
    KEY idx_email_tokens_expires (expires_at),
    CONSTRAINT fk_email_tokens_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
