-- Магазин SiteGateShop (D17). Всё в DB1: покупка = списание + заказ в одной транзакции.
-- Совместимо с MySQL 8.2 и MariaDB 10.4/10.6.

-- Товары. code = файл products/<code>.yml в плагине SiteGateShop: там команды выдачи
CREATE TABLE shop_products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    title VARCHAR(120) NOT NULL,
    description_md TEXT NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT '',
    price INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shop_products_code (code),
    KEY idx_shop_products_active (is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кошелёк игрока: аккаунт = (база, id) из трёх баз аккаунтов
CREATE TABLE shop_wallets (
    shard TINYINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(16) NOT NULL,
    balance BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (shard, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shop_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shard TINYINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(16) NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    title VARCHAR(120) NOT NULL,
    price INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    KEY idx_shop_orders_status (status, id),
    KEY idx_shop_orders_account (shard, account_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История баланса: каждое пополнение, покупка и возврат
CREATE TABLE shop_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shard TINYINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(16) NOT NULL,
    delta BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    reason VARCHAR(160) NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    admin VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_shop_ledger_account (shard, account_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Какие товары плагин умеет выдавать (присылает сам при запуске и /shop reload)
CREATE TABLE shop_plugin_codes (
    code VARCHAR(64) NOT NULL PRIMARY KEY,
    require_online TINYINT(1) NOT NULL DEFAULT 1,
    seen_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
