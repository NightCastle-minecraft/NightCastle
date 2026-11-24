-- Лимит писем (D18): повторная отправка подтверждения и «забыли пароль». ip = «что|кто»
CREATE TABLE mail_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(80) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mail_attempts_ip (ip, attempted_at),
    KEY idx_mail_attempts_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
