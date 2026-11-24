#!/bin/sh
# Создаёт секреты в secrets/: пароль RCON, токен API авторизации (общий для сайта и SiteGateAuth)
# секрет modern forwarding (общий для Velocity и SMP: тот же текст в paper-global.yml)
# и токен API магазина (общий для сайта и плагина SiteGateShop на SMP).
# Существующие не перезаписывает.
# Запускать на сервере один раз, до первого docker compose up.
set -eu
cd "$(dirname "$0")/.."
mkdir -p secrets
chmod 700 secrets
for name in rcon_pass auth_api_token forwarding_secret shop_api_token; do
    if [ -s "secrets/$name" ]; then
        echo "есть:    secrets/$name"
        continue
    fi
    case "$name" in
        auth_api_token)    openssl rand -hex 32 ;;
        forwarding_secret) openssl rand -hex 16 ;;
        shop_api_token)    openssl rand -hex 32 ;;
        *)                 openssl rand -hex 24 ;;
    esac | tr -d '\n' > "secrets/$name"
    # 644 внутри закрытой папки: файл читают процессы контейнера под своими uid
    chmod 644 "secrets/$name"
    echo "создан:  secrets/$name"
done
