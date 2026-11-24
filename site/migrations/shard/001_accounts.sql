-- Аккаунты игроков. Катится на все три базы: ник живёт в базе crc32(lower(ник)) % 3 + 1 (D14).
-- Совместимо с MySQL 8.2 и MariaDB 10.4/10.6. Лимит хостинга 4 ГБ на базу: тяжёлых логов здесь нет.

CREATE TABLE accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(16) NOT NULL,
    name_lower VARCHAR(16) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    created_ip VARCHAR(45) NOT NULL,
    last_login_at DATETIME NULL,
    playtime_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sessions_reset_at DATETIME NULL,
    UNIQUE KEY uq_accounts_name (name_lower)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- С этих IP игра пускает без сайта, пока не истекло expires_at
CREATE TABLE ip_sessions (
    account_id INT UNSIGNED NOT NULL,
    ip VARCHAR(45) NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (account_id, ip),
    KEY idx_ip_sessions_expires (expires_at),
    CONSTRAINT fk_ip_sessions_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ссылки входа из игры. Хранится только sha256 тикета
CREATE TABLE auth_tickets (
    hash CHAR(64) NOT NULL PRIMARY KEY,
    name VARCHAR(16) NOT NULL,
    name_lower VARCHAR(16) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    status VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    account_id INT UNSIGNED NULL,
    KEY idx_auth_tickets_name (name_lower, status),
    KEY idx_auth_tickets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отыгранные сессии: ключ (аккаунт, начало) не даёт засчитать одну сессию дважды
CREATE TABLE play_sessions (
    account_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NOT NULL,
    PRIMARY KEY (account_id, started_at),
    CONSTRAINT fk_play_sessions_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE playtime_daily (
    account_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    seconds INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, day),
    CONSTRAINT fk_playtime_daily_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
