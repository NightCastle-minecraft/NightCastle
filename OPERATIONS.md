# Жизнь сервера: эксплуатация SiteGate

Что делать после установки: повседневные команды, бэкапы и восстановление, обновления, смена паролей и токенов, переезд на другой VPS. Установка — в [INSTALL.md](INSTALL.md), все настройки — в [CONFIGURATION.md](CONFIGURATION.md), проблемы — в [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

Все команды выполняются **на VPS** из `/opt/sitegate/infra`, если не сказано другое:
```bash
cd /opt/sitegate/infra
```

## Содержание
1. [Повседневные команды](#повседневные-команды)
2. [Бэкапы](#бэкапы)
3. [Восстановление из бэкапа](#восстановление-из-бэкапа)
4. [Обновления](#обновления)
5. [Смена токенов и секретов](#смена-токенов-и-секретов)
6. [Админы и игроки](#админы-и-игроки)
7. [Нагрузка, память, диск](#нагрузка-память-диск)
8. [Переезд на другой VPS](#переезд-на-другой-vps)
9. [Удаление](#удаление)

---

## Повседневные команды

### Состояние
```bash
docker compose ps                          # все контейнеры и их статус
docker stats --no-stream                   # память и процессор каждого контейнера
curl -s https://example.com/api/status; echo   # онлайн глазами сайта
```

### Логи
```bash
docker compose logs -f proxy               # входы игроков, капча, связь с сайтом (Ctrl+C — выйти из просмотра)
docker compose logs -f smp                 # игровой сервер
docker compose logs --tail 100 web         # ошибки PHP
docker compose logs --tail 100 site        # Caddy: сертификаты, ошибки HTTPS
docker logs sg-mail 2>&1 | grep status=    # судьба писем: sent / deferred / bounced
journalctl -u sitegate-panel -n 50         # панель
```
`--since 1h` покажет логи за последний час, `--tail 200` — последние 200 строк.

### Консоль
```bash
docker exec -i sg-smp rcon-cli list                 # одна команда на SMP
docker exec -i sg-smp rcon-cli "say Рестарт через 5 минут"
docker attach sg-proxy                              # консоль Velocity
```
**Из `docker attach` выходите `Ctrl+P`, затем `Ctrl+Q`.** `Ctrl+C` там остановит прокси, и все игроки вылетят.

То же самое удобнее делать в панели: http://localhost:8780 через SSH-туннель (INSTALL.md, шаг 15.4).

### Запуск и остановка
```bash
docker compose restart smp      # перезапуск SMP (мир сохраняется, на остановку до 2 минут)
docker compose restart proxy    # перезапуск прокси (все игроки отключатся)
docker compose stop             # остановить всё
docker compose up -d            # запустить всё (и применить изменения .env / compose)
```
Перезапуск SMP из панели сам пишет в чат «Сервер перезапустится через 10 секунд» и ждёт 10 секунд. Командой `docker compose restart` предупреждения нет: сначала `docker exec -i sg-smp rcon-cli "say Рестарт через минуту"`.

---

## Бэкапы

### Что бэкапить

| Что | Где | Как часто | Чем |
|---|---|---|---|
| Мир и всё игровое | `servers/SMP` | Каждые 12 ч (по умолчанию) | Панель, автоматически |
| Базы: аккаунты, сайт, магазин | У хостинга или `sg-db` | Ежедневно | Хостинг или команда ниже |
| Настройки и секреты | `.env`, `secrets/`, конфиги плагинов, `panel/data` | После каждого изменения | Команда ниже |
| Ключ DKIM | Том `sg-mail-dkim` | Один раз после установки | Команда ниже |
| Загруженные картинки | Том `sg-web-uploads` | После загрузки новых | Команда ниже |

**Правило:** бэкап, который лежит только на том же VPS, не спасёт, если VPS умрёт или хостинг его удалит. Регулярно забирайте бэкапы к себе (раздел «Скачать бэкапы к себе»).

### Мир (панель)
- Автобэкап: панель → «Бэкапы». По умолчанию раз в 12 часов, хранится 14 последних. Папка: `/opt/sitegate/infra/backups`, файлы `smp-ГГГГ-ММ-ДД_ЧЧ-ММ-СС-auto.tar.gz`.
- Вручную: панель → «Бэкапы» → «Сделать бэкап». Делайте перед обновлениями и установкой новых плагинов.
- Сервер во время бэкапа не останавливается. Панель временно выключает автосохранение (`save-off`), сохраняет мир на диск (`save-all flush`), архивирует и включает сохранение обратно.

### Базы, вариант А (хостинг)
Многие хостинги сами делают бэкапы. Узнайте у своего, как часто и как из них восстановиться. Свой дамп (для каждой базы, подставьте её данные):
```bash
mkdir -p ~/db-backups
# MySQL 8:
docker run --rm mysql:8.4 mysqldump -h HOST -P 3306 -u USER -p'PASSWORD' \
  --single-transaction --no-tablespaces DBNAME | gzip > ~/db-backups/db1-$(date +%F).sql.gz
# MariaDB:
docker run --rm mariadb:10.11 mariadb-dump -h HOST -P 3306 -u USER -p'PASSWORD' \
  --single-transaction DBNAME | gzip > ~/db-backups/db2-$(date +%F).sql.gz
ls -lh ~/db-backups
```
Файл размером в десятки байт — это ошибка, а не дамп: запустите команду без `| gzip > ...` и прочитайте сообщение.

### Базы, вариант Б (своя MariaDB `sg-db`)
```bash
mkdir -p ~/db-backups
docker exec sg-db sh -c 'exec mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction "$MARIADB_DATABASE"' \
  | gzip > ~/db-backups/sitegate-$(date +%F).sql.gz
ls -lh ~/db-backups
```
Каждый день в 04:30, храня 14 дней. Откройте `crontab -e` и добавьте строку:
```
30 4 * * * docker exec sg-db sh -c 'exec mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction "$MARIADB_DATABASE"' | gzip > $HOME/db-backups/sitegate-$(date +\%F).sql.gz && find $HOME/db-backups -name '*.sql.gz' -mtime +14 -delete
```
`\%` в crontab обязателен: без обратного слэша `%` значит перевод строки.

### Настройки и секреты
```bash
cd /opt/sitegate/infra
tar czf ~/sitegate-config-$(date +%F).tgz --ignore-failed-read \
  .env secrets compose.override.yml panel/data \
  servers/proxy/velocity.toml servers/proxy/plugins/sitegateauth/config.toml \
  servers/SMP/plugins/SiteGateShop/config.yml servers/SMP/plugins/SiteGateShop/products
chmod 600 ~/sitegate-config-*.tgz
```
**В архиве лежат пароли к базам и токены.** Храните его так же бережно, как пароли.

### Ключ DKIM и загруженные картинки
```bash
docker run --rm -v sg-mail-dkim:/v:ro -v "$HOME":/b alpine tar czf /b/sg-mail-dkim-$(date +%F).tgz -C /v .
docker run --rm -v sg-web-uploads:/v:ro -v "$HOME":/b alpine tar czf /b/sg-web-uploads-$(date +%F).tgz -C /v .
ls -lh ~/sg-*.tgz
```

### Скачать бэкапы к себе
**[ПК]** Linux/macOS (или WSL на Windows):
```bash
mkdir -p ~/sitegate-backups
rsync -av ubuntu@203.0.113.10:/opt/sitegate/infra/backups/ ~/sitegate-backups/world/
rsync -av ubuntu@203.0.113.10:db-backups/ ~/sitegate-backups/db/
rsync -av 'ubuntu@203.0.113.10:sitegate-config-*.tgz' ~/sitegate-backups/config/
rsync -av 'ubuntu@203.0.113.10:sg-*.tgz' ~/sitegate-backups/config/
```
`rsync` докачивает только новое, поэтому команду удобно запускать хоть каждый день. На Windows без WSL забирайте файлы через WinSCP.

---

## Восстановление из бэкапа

### Мир
Внутри архива всё лежит в папке `SMP/`. `server.jar`, `libraries` и `versions` в бэкап не входят: `server.jar` берём из текущей папки, остальное сервер скачает сам.
```bash
cd /opt/sitegate/infra
ls -lh backups/                                         # выберите нужный бэкап
docker compose stop smp
mv servers/SMP servers/SMP.before-restore-$(date +%F_%H%M)
mkdir servers/SMP
tar xzf backups/smp-2026-01-01_12-00-00-auto.tar.gz --strip-components=1 -C servers/SMP
cp servers/SMP.before-restore-*/server.jar servers/SMP/
docker compose up -d smp
docker compose logs -f smp                              # ждите "Done"
```
Когда убедитесь, что всё в порядке, удалите старую копию: `rm -rf servers/SMP.before-restore-*`.

### Базы, вариант Б
```bash
docker compose stop web
gunzip -c ~/db-backups/sitegate-2026-01-01.sql.gz \
  | docker exec -i sg-db sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
docker compose start web
```

### Базы, вариант А
Через панель хостинга (phpMyAdmin → «Импорт») или командой:
```bash
gunzip -c ~/db-backups/db1-2026-01-01.sql.gz | docker run --rm -i mysql:8.4 mysql -h HOST -P 3306 -u USER -p'PASSWORD' DBNAME
```

### Ключ DKIM
```bash
docker compose stop mail
docker run --rm -v sg-mail-dkim:/v -v "$HOME":/b alpine sh -c 'rm -rf /v/* && tar xzf /b/sg-mail-dkim-2026-01-01.tgz -C /v'
docker compose up -d mail
```

---

## Обновления

### SiteGate (сайт, конфиги стека)
Перед обновлением сделайте бэкап мира (панель) и баз.
```bash
cd /opt/sitegate
git status --short            # пусто = локальных правок в файлах репозитория нет
git pull
cd infra
docker compose build
docker compose run --rm --no-deps -T web php bin/migrate.php
docker compose up -d
```
- Если `git pull` пишет `Your local changes ... would be overwritten`, вы правили файлы репозитория. Посмотрите, что именно: `git diff`. Перенесите правки в `compose.override.yml` или `.local`-файлы (см. [CONFIGURATION.md](CONFIGURATION.md)), затем `git stash` и снова `git pull`.
- Что поменялось в шаблонах конфигов (новые ключи у плагинов): `git diff HEAD@{1} -- infra/proxy infra/smp`. Новые ключи перенесите в свои копии в `servers/`.

### Плагины SiteGateAuth и SiteGateShop
После `git pull` пересоберите их (INSTALL.md, шаг 9) и замените jar:
```bash
cd /opt/sitegate/infra
cp /opt/sitegate/plugins/auth/build/libs/SiteGateAuth-*.jar servers/proxy/plugins/
cp /opt/sitegate/plugins/shop/build/libs/SiteGateShop-*.jar servers/SMP/plugins/
ls servers/proxy/plugins servers/SMP/plugins             # старых версий рядом быть не должно
docker compose restart proxy smp
```
Если номер версии в имени jar поменялся, удалите старый jar: два одинаковых плагина сервер не загрузит.

### Velocity
**Только вместе с SiteGateAuth.** Нужная версия Velocity прописана в `plugins/build.gradle.kts`, сборка сама скачивает её в `plugins/libs/`. Когда выйдет SiteGate с новой Velocity, обновляйтесь так: `git pull` → сборка плагинов → скопировать `velocity-*.jar` в `servers/proxy/velocity.jar` вместе с плагином → `docker compose restart proxy`.

### Paper или Leaf (сборки внутри 26.3)
```bash
cd /opt/sitegate/infra
# панель → «Бэкапы» → «Сделать бэкап»
wget -O servers/SMP/server.jar.new 'ССЫЛКА_НА_НОВУЮ_СБОРКУ_26.3'
mv servers/SMP/server.jar servers/SMP/server.jar.old && mv servers/SMP/server.jar.new servers/SMP/server.jar
docker compose restart smp && docker compose logs -f smp
```
Что-то пошло не так — верните старый файл: `mv servers/SMP/server.jar.old servers/SMP/server.jar` и перезапустите.

### Новая версия Minecraft (26.4 и дальше)
**Не обновляйте сервер, пока не вышла версия SiteGateAuth под новую версию игры.** Плагин работает с сетевым протоколом конкретной версии. Клиенты другой версии получат кик «Нужен клиент Minecraft 26.3».

### Docker-образы
Версии образов закреплены в `compose.yml` и меняются вместе с обновлениями SiteGate. Свежие патчи безопасности для PHP и Caddy подтягиваются пересборкой:
```bash
docker compose build --pull && docker compose up -d
```

### Система
```bash
sudo apt update && sudo apt upgrade -y
```
Docker обновляется тем же `apt`. После обновления Docker контейнеры перезапустятся сами (`restart: unless-stopped`), панель тоже (systemd). Если пишет `System restart required`: `sudo reboot`, после загрузки всё поднимется само.

Включить автоматические обновления безопасности:
```bash
sudo apt install -y unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades
```

---

## Смена токенов и секретов

Меняйте, если:
- файл секрета или конфиг с токеном кто-то увидел (скриншот, переписка, чужой доступ к VPS);
- ушёл человек, у которого был доступ;
- в логах есть запросы к API с чужих IP.

Docker подключает секреты в контейнер как файлы. После замены файла контейнер нужно **пересоздать** (`--force-recreate`): простой `restart` оставит старый файл.

| Секрет | Команды |
|---|---|
| Токен входа `auth_api_token` | `rm secrets/auth_api_token && sh bin/init-secrets.sh && docker compose up -d --force-recreate web proxy` |
| Токен магазина `shop_api_token` | `rm secrets/shop_api_token && sh bin/init-secrets.sh && docker compose up -d --force-recreate web smp` |
| Пароль RCON `rcon_pass` | См. ниже: нужно поменять ещё и `server.properties` |
| Секрет forwarding `forwarding_secret` | См. ниже: нужно поменять ещё и `paper-global.yml` |

**RCON:**
```bash
rm secrets/rcon_pass && sh bin/init-secrets.sh
sed -i "s/^rcon.password=.*/rcon.password=$(cat secrets/rcon_pass)/" servers/SMP/server.properties
docker compose up -d --force-recreate smp
```
**Forwarding:**
```bash
rm secrets/forwarding_secret && sh bin/init-secrets.sh
S=$(cat secrets/forwarding_secret)
sed -i "/^  velocity:/,/^    secret:/s/^    secret: .*/    secret: '$S'/" servers/SMP/config/paper-global.yml
grep -A3 '^  velocity:' servers/SMP/config/paper-global.yml
docker compose up -d --force-recreate proxy smp
```
**Пароль базы:** поменяйте у хостинга, затем в `.env`, затем `docker compose up -d web`.

**Пароль админа сайта:** в админке → «Пароль», или `docker exec -it sg-web php bin/create-admin.php admin --reset`.

**Пароль панели:** `cd /opt/sitegate/infra && echo 'новый-пароль' | python3 panel/panel.py set-password` (без sudo). Все сессии панели сбрасываются.

---

## Админы и игроки

### Админы сайта
```bash
docker exec -it sg-web php bin/create-admin.php moderator          # новый админ
docker exec -it sg-web php bin/create-admin.php moderator --reset  # новый пароль существующему
```
Ролей нет: каждый админ видит всю админку.

### Игроки
| Задача | Админка | Панель | Командой |
|---|---|---|---|
| Найти аккаунт | «Игроки» → «Найти во всех трёх базах» | «Игроки» → ник | `docker exec sg-web php bin/player.php info Steve` |
| Забыл пароль | «Новый пароль» (покажет новый) | «Новый пароль» | `docker exec sg-web php bin/player.php password Steve` |
| Выкинуть со всех устройств | «Сбросить сессии» | «Сбросить входы» | `docker exec sg-web php bin/player.php reset-sessions Steve` |
| Монеты | «Магазин» → «Кошельки и пополнение» | «Изменить баланс» | `docker exec sg-web php bin/player.php balance Steve 100 "подарок"` (минус: `-50`) |
| Кик, бан, op, вайтлист | — | «Игроки» | `docker exec -i sg-smp rcon-cli ban Steve` |

Игрок сам сбрасывает пароль через «Забыли пароль?» на странице входа, если подтвердил почту.

### Удалить все аккаунты игроков
Например, перед открытием сервера после теста.
```bash
docker exec -it sg-web php bin/wipe-players.php          # только покажет, сколько удалит
docker exec -it sg-web php bin/wipe-players.php --yes    # удалит во всех базах
```
Необратимо. Сначала сделайте бэкап баз.

---

## Нагрузка, память, диск

```bash
free -h                                   # строка Mem: available — сколько памяти реально свободно
docker stats --no-stream                  # кто сколько ест
df -h /                                   # место на диске
du -sh servers/SMP backups                # размер мира и бэкапов
docker inspect -f 'OOM={{.State.OOMKilled}} рестартов={{.RestartCount}}' sg-smp
```
- `OOM=true` или растущее число рестартов — SMP упирается в `mem_limit`. Уменьшите `SMP_MEMORY` или увеличьте `mem_limit` (CONFIGURATION.md, раздел 3), если позволяет память VPS.
- Лаги при нормальной памяти: уменьшите `view-distance` и `simulation-distance` в `server.properties`. Найти, что именно грузит сервер, поможет плагин spark (spark.lucko.me): он строит отчёт по нагрузке.
- Диск кончается — первым делом бэкапы: уменьшите «Сколько бэкапов хранить» в панели и заберите старые к себе.
- Размер баз виден на дашборде админки (`/admin`). Предупреждение загорается от 3 ГБ.

---

## Переезд на другой VPS

Базы у хостинга (вариант А) переезжать не должны, им нужно только разрешить новый IP.

**1. Новый VPS.** По INSTALL.md шаги 2 (подготовка) и 5 (код). Остальные шаги не нужны.

**2. Старый VPS.** Остановите игру и упакуйте всё:
```bash
cd /opt/sitegate/infra
docker compose stop proxy smp
tar czf ~/move-infra.tgz .env secrets compose.override.yml panel/data servers
docker run --rm -v sg-mail-dkim:/v:ro -v "$HOME":/b alpine tar czf /b/move-dkim.tgz -C /v .
docker run --rm -v sg-web-uploads:/v:ro -v "$HOME":/b alpine tar czf /b/move-uploads.tgz -C /v .
# только вариант Б:
docker exec sg-db sh -c 'exec mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction "$MARIADB_DATABASE"' | gzip > ~/move-db.sql.gz
```

**3. Перенос.** Со старого VPS на новый (замените IP):
```bash
scp ~/move-*.tgz ~/move-db.sql.gz ubuntu@НОВЫЙ_IP:~/
```

**4. Новый VPS: распаковка.**
```bash
cd /opt/sitegate/infra
tar xzf ~/move-infra.tgz
docker volume create sg-mail-dkim && docker run --rm -v sg-mail-dkim:/v -v "$HOME":/b alpine tar xzf /b/move-dkim.tgz -C /v
docker volume create sg-web-uploads && docker run --rm -v sg-web-uploads:/v -v "$HOME":/b alpine tar xzf /b/move-uploads.tgz -C /v
docker compose build
```
Предупреждение `volume ... already exists but was not created by Docker Compose` здесь нормально: Compose возьмёт эти тома.

Вариант Б, база:
```bash
docker compose up -d db && sleep 20
gunzip -c ~/move-db.sql.gz | docker exec -i sg-db sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

**5. DNS и доступы.** Везде замените старый IP на новый:
- A-записи `@`, `www`, `mail`;
- SPF: `v=spf1 ip4:НОВЫЙ_IP -all`;
- PTR для нового IP — у хостинга нового VPS;
- вариант А: разрешите новый IP у хостинга баз;
- фаервол (INSTALL.md, шаг 2.8), исходящий порт 25 (шаг 2.9).

**6. Запуск.**
```bash
docker compose up -d
docker compose logs -f site     # ждите "certificate obtained successfully"
```
И установите панель (INSTALL.md, шаг 15.1–15.3: пароль уже лежит в `panel/data`).

**7. Проверка** по INSTALL.md, шаг 18. Когда всё работает и DNS везде показывает новый IP (`dig +short A example.com @1.1.1.1`), остановите старый стек: `docker compose down` на старом VPS.

---

## Удаление

```bash
cd /opt/sitegate/infra
docker compose down                     # остановить и удалить контейнеры; данные и тома остаются
```
Полностью, **необратимо** (сертификаты, ключ DKIM, загрузки, своя база, мир, бэкапы):
```bash
cd /opt/sitegate/infra
docker compose down -v
sudo systemctl disable --now sitegate-panel
sudo rm /etc/systemd/system/sitegate-panel.service && sudo systemctl daemon-reload
cd / && sudo rm -rf /opt/sitegate
```
Базы у хостинга удаляются в его панели.
