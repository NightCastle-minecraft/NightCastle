# Настройки SiteGate: справочник

Здесь описана каждая настройка: где лежит, что делает, какое значение по умолчанию и что перезапустить после изменения. Установка по шагам — в [INSTALL.md](INSTALL.md).

Все пути ниже — от `/opt/sitegate/infra`, если не сказано другое. Команды выполняются на VPS из этой папки.

## Содержание
1. [Карта файлов](#1-карта-файлов)
2. [.env](#2-env)
3. [compose.yml и compose.override.yml](#3-composeyml-и-composeoverrideyml)
4. [Caddy (сайт снаружи)](#4-caddy-сайт-снаружи)
5. [Velocity](#5-velocity)
6. [SiteGateAuth](#6-sitegateauth)
7. [Игровой сервер SMP](#7-игровой-сервер-smp)
8. [SiteGateShop](#8-sitegateshop)
9. [Сайт](#9-сайт)
10. [Почта](#10-почта)
11. [Панель](#11-панель)
12. [Что перезапускать после изменений](#12-что-перезапускать-после-изменений)

---

## 1. Карта файлов

### В репозитории (обновляются через `git pull`, руками лучше не править)
| Путь | Что это |
|---|---|
| `compose.yml` | Описание всех контейнеров |
| `.env.example` | Шаблон `.env` |
| `bin/init-secrets.sh` | Создаёт секреты |
| `site/Caddyfile` | Конфиг Caddy |
| `proxy/velocity.toml`, `proxy/sitegateauth.toml` | Шаблоны конфигов прокси и SiteGateAuth |
| `smp/sitegateshop.yml` | Шаблон конфига SiteGateShop |
| `panel/panel.py`, `panel/sitegate-panel.service` | Панель и её служба |
| `../site/` | Код сайта |
| `../plugins/` | Код плагинов |

### Только на VPS (в git не попадают, это ваши данные)
| Путь | Что это | Бэкапить? |
|---|---|---|
| `.env` | Домен, название, память, доступы к базам | **Да** |
| `secrets/` | Токены и пароли (4 файла) | **Да** |
| `compose.override.yml` | Ваши дополнения к `compose.yml` | Да |
| `servers/SMP/` | Игровой сервер: мир, плагины, конфиги | **Да** (панель бэкапит) |
| `servers/proxy/` | Velocity: `velocity.jar`, `velocity.toml`, плагины | Да |
| `servers/proxy/plugins/sitegateauth/` | `config.toml`, `sessions.json` (кеш сессий), `playtime-queue.json` | Конфиг да |
| `panel/data/` | Пароль панели, настройки бэкапов, история правок конфигов | Да |
| `backups/` | Бэкапы мира от панели | Копировать с VPS |

### Docker-тома (команда `docker volume ls`)
| Том | Что внутри | Что будет, если удалить |
|---|---|---|
| `sg-site-data` | Сертификаты Let's Encrypt | Caddy получит новые (лимит LE: 5 одинаковых сертификатов в неделю) |
| `sg-site-config` | Служебный конфиг Caddy | Ничего страшного |
| `sg-web-uploads` | Картинки, загруженные через админку (фон главной) | Пропадут загруженные картинки |
| `sg-mail-dkim` | **Ключ DKIM** | Новый ключ → нужно менять DNS-запись DKIM |
| `sg-mail-spool` | Очередь писем | Пропадут неотправленные письма |
| `sg-db-data` | База (только вариант Б из INSTALL.md) | **Пропадут все аккаунты и сайт** |

---

## 2. .env

После изменения: `docker compose up -d`. Compose сам пересоздаст контейнеры, которых коснулось изменение.

| Переменная | По умолчанию | Где используется | Описание |
|---|---|---|---|
| `DOMAIN` | — (обязательна) | Caddy, сайт, почта | Домен без `https://`. Caddy получает на него сертификат, сайт строит ссылки, почта шлёт от `noreply@DOMAIN` |
| `SITE_NAME` | `MyServer` | Почта | Имя отправителя и название в текстах писем. Название на сайте задаётся отдельно, в админке |
| `GAME_ADDRESS` | пусто = `DOMAIN` | Почта | Адрес сервера в приветственном письме |
| `SMP_MEMORY` | `5G` | SMP | Heap Java игрового сервера (`-Xms` и `-Xmx`). Формат: `3G`, `4500M` |
| `DB1_HOST`…`DB3_HOST` | — (обязательны) | Сайт | Хост базы. Для своей MariaDB из INSTALL.md — `db` |
| `DB1_PORT`…`DB3_PORT` | `3306` | Сайт | Порт базы |
| `DB1_NAME`…`DB3_NAME` | — | Сайт | Имя базы |
| `DB1_USER`…`DB3_USER` | — | Сайт | Пользователь |
| `DB1_PASSWORD`…`DB3_PASSWORD` | — | Сайт | Пароль. Пишите в одинарных кавычках |

**Синтаксис:**
- одна переменная на строку, `ИМЯ=значение`, пробелов вокруг `=` нет;
- строка с `#` в начале — комментарий;
- значение в одинарных кавычках берётся как есть (`$`, `#`, пробелы не ломают его).

---

## 3. compose.yml и compose.override.yml

`compose.yml` лежит в git, его перезаписывает `git pull`. **Свои изменения делайте в `compose.override.yml`**: Docker Compose сам накладывает его поверх `compose.yml`. Правила наложения:
- скалярные значения (`mem_limit`, `user`, `image`) заменяются;
- `environment` сливается по ключам;
- `ports` и `volumes` добавляются к списку, но `volumes` с тем же путём внутри контейнера заменяются.

Посмотреть итоговую конфигурацию после наложения: `docker compose config`.

### 3.1. Что можно менять

| Сервис | Параметр | По умолчанию | Зачем менять |
|---|---|---|---|
| `smp` | `mem_limit` | `6500m` | Потолок памяти контейнера. Делайте на 1,2–1,5 ГБ больше `SMP_MEMORY` |
| `smp` | `environment.TZ` | `Europe/Kyiv` | Часовой пояс игрового сервера (время в логах) |
| `smp` | `environment.UID` / `GID` | `1000` | Если ваш пользователь на VPS не uid 1000 |
| `smp` | `environment.USE_AIKAR_FLAGS` | `true` | Набор флагов JVM для Minecraft. Не трогайте без причины |
| `smp` | `stop_grace_period` | `2m` | Сколько ждать сохранения мира при остановке |
| `proxy` | `user` | `1000:1000` | Если ваш пользователь на VPS не uid 1000 |
| `proxy` | `mem_limit` | `640m` | Потолок памяти прокси |
| `proxy` | `entrypoint` (`-Xmx320M`) | 320 МБ | Heap Velocity. До 50 игроков хватает |
| `proxy` | `ports` | `25565:25565` | Внешний порт игры (см. ниже) |
| `web` | `mem_limit` | `320m` | Потолок памяти сайта. Хватает на 12 параллельных запросов |
| `mail` | `mem_limit` | `256m` | rspamd (DKIM) сам по себе ест ~160 МБ |
| `site` | `mem_limit` | `128m` | Caddy |

Пример `compose.override.yml` на 6 ГБ VPS с другим часовым поясом:
```yaml
services:
  smp:
    mem_limit: 4500m
    environment:
      TZ: Europe/Berlin
```

**Другой внешний порт игры**, например 25570. Игроки будут заходить на `example.com:25570`:
```yaml
services:
  proxy:
    ports: !override
      - "25570:25565"
```
`!override` заменяет список целиком, без него Compose добавил бы порт к старому. Нужен Docker Compose 2.24 или новее (`docker compose version`). Не забудьте открыть новый порт в ufw.

**Heap Velocity** меняется только заменой всей команды запуска:
```yaml
services:
  proxy:
    mem_limit: 1g
    entrypoint: ["java", "-Xms512M", "-Xmx512M", "-XX:+UseG1GC", "-XX:G1HeapRegionSize=4M",
                 "-XX:+UnlockExperimentalVMOptions", "-XX:+ParallelRefProcEnabled",
                 "-XX:+AlwaysPreTouch", "-XX:MaxInlineLevel=15", "-jar", "velocity.jar"]
```

### 3.2. Что менять нельзя

| Что | Почему |
|---|---|
| Порты у `smp`, `mail`, `web` | Откроете SMP — в игру зайдут мимо SiteGateAuth под любым ником. Откроете почту — получите открытый спам-релей |
| `container_name` (`sg-*`) | На этих именах держатся панель и команды из инструкций |
| Имя сети `sg-network` и алиас `${DOMAIN}` у `site` | По алиасу прокси и SMP ходят на сайт внутри docker-сети |
| `secrets:` | Плагины и сайт читают токены по путям `/run/secrets/...` |
| `OVERRIDE_SERVER_PROPERTIES: "false"` | Иначе образ перезапишет ваш `server.properties` при каждом старте |

### 3.3. Версии образов

| Образ | Версия | Обновлять |
|---|---|---|
| `itzg/minecraft-server` | `2026.11.0-java25` | Можно на более новый `*-java25`. Читайте changelog образа |
| `boky/postfix` | `5.2.1-alpine` | Осторожно. После обновления отправьте тестовое письмо и проверьте, что DKIM = PASS (ключ берётся из тома `sg-mail-dkim`) |
| Caddy (`site/docker/caddy/Dockerfile`) | `2.11.9` + плагин ratelimit по коммиту | Вместе с плагином |
| PHP (`site/Dockerfile`) | `php:8.3-apache` | Патчи 8.3 приходят при `docker compose build --pull web` |

---

## 4. Caddy (сайт снаружи)

Файл `site/Caddyfile`. Применить изменения: `docker compose restart site`.

| Блок | Что делает | Значение |
|---|---|---|
| `encode zstd gzip` | Сжатие ответов | |
| `Strict-Transport-Security` | Браузер всегда ходит по HTTPS | 1 год |
| `rate_limit` → `all_per_ip` | Общий лимит запросов с одного IP. API плагинов (`/api/auth/*`, `/api/stats/*`, `/api/server/*`) не считается | 120 в минуту |
| `rate_limit` → `login_per_ip` | Лимит попыток входа: POST на `/admin/login`, `/login`, `/auth/*` | 20 в минуту |
| `@rippers` | Ответ 403 программам для скачивания сайтов целиком (HTTrack, wget, python-requests и т.п.) | |
| `@dotfiles`, `@sensitive` | Ответ 404 на служебные файлы (`.env`, `.git`, `*.sql`, `composer.json`, `/vendor/` …) | |
| `@hotlink` | Картинки из `/uploads/` нельзя встраивать на чужие сайты | |
| `@static` | Кеш браузера для `/assets/*` и `/uploads/*` | 7 дней |
| `www.{$DOMAIN}` | Редирект `www` → основной домен | 301 |

Чтобы ваши изменения не конфликтовали с `git pull`, сделайте свою копию и подключите её через override:
```bash
cp site/Caddyfile site/Caddyfile.local
```
```yaml
# compose.override.yml
services:
  site:
    volumes:
      - ./site/Caddyfile.local:/etc/caddy/Caddyfile:ro
```
После этого правьте `site/Caddyfile.local`, затем `docker compose up -d site`.

---

## 5. Velocity

Файл `servers/proxy/velocity.toml` (шаблон: `proxy/velocity.toml`). Применить: `docker compose restart proxy`. **Все игроки отключатся.**

| Ключ | В шаблоне | Описание |
|---|---|---|
| `motd` | `<#9b8cff><b>MyServer</b> ...` | Описание в списке серверов, формат MiniMessage |
| `show-max-players` | `20` | Сколько слотов показывать в списке серверов. Velocity не ограничивает онлайн |
| `online-mode` | `false` | **Не менять.** Лицензию не проверяем, вход проверяет SiteGateAuth |
| `force-key-authentication` | `false` | **Не менять.** У игроков без лицензии нет ключей Mojang |
| `player-info-forwarding-mode` | `"MODERN"` | **Не менять.** Передача ника, UUID и IP на SMP с подписью |
| `forwarding-secret-file` | `/run/secrets/forwarding_secret` | **Не менять.** Тот же секрет вписан в SMP |
| `[servers] smp`, `try` | `smp:25565`, `["smp"]` | Куда отправлять игрока после входа |
| `[ping-passthrough] favicon` | `false` | `true` — показывать иконку `servers/SMP/server-icon.png` в списке серверов |
| `[ping-passthrough] players` | `false` | `true` — показывать онлайн SMP вместо онлайна прокси (у вас один сервер, разницы нет) |
| `[advanced] login-ratelimit` | `3000` | Не чаще одного подключения с IP за 3 секунды |
| `[advanced] haproxy-protocol` | `false` | `true` только если перед прокси стоит балансировщик или DDoS-защита с PROXY protocol. Иначе никто не зайдёт |
| `enable-player-address-logging` | `true` | `false` — скрыть IP игроков в логах прокси |
| `log-player-connections` | `true` | Писать в лог входы и выходы |

> **Velocity обновлять нельзя** отдельно от SiteGateAuth: плагин рассчитан на 4.3.1 build 45.

---

## 6. SiteGateAuth

Файл `servers/proxy/plugins/sitegateauth/config.toml` (шаблон: `proxy/sitegateauth.toml`). Если файла нет, плагин создаёт его при первом запуске из своего jar. Применить: `docker compose restart proxy`.

### [site]
| Ключ | В шаблоне | Описание |
|---|---|---|
| `url` | `https://example.com` | Адрес сайта без `/` в конце. Внутри docker-сети он ведёт прямо на Caddy |
| `token` | `""` | Токен API текстом. Задавайте **что-то одно**: `token` или `token-file` |
| `token-file` | `/run/secrets/auth_api_token` | Файл с токеном. Относительный путь считается от папки плагина |
| `timeout-seconds` | `5` | Таймаут одного запроса к сайту |

Без токена, без файла или с неправильным конфигом **вход закрыт для всех** («Вход временно закрыт»). Так задумано: недонастроенный плагин не должен пускать кого попало.

### [auth]
| Ключ | В шаблоне | Описание |
|---|---|---|
| `poll-seconds` | `3` | Как часто спрашивать сайт, подтвердил ли игрок вход |
| `wait-minutes` | `10` | Сколько ждать подтверждения, потом кик. Ссылка на сайте тоже живёт 10 минут |
| `cache-days` | `7` | Сколько дней хранить локальную копию сессий. Она нужна только когда сайт недоступен: игроки с живой сессией всё равно заходят |
| `logins-per-minute` | `5` | Сколько подключений в минуту разрешено с одного IP |

### [captcha]
| Ключ | В шаблоне | Описание |
|---|---|---|
| `buttons` | `6` | Сколько вариантов ответа показывать |
| `attempts` | `3` | Сколько ошибок можно сделать |
| `ban-minutes` | `5` | На сколько минут закрыть вход с IP после проваленной капчи |

### [messages]
Все тексты в формате MiniMessage. Перевод строки — `\n`.

| Ключ | Где виден | Подстановки |
|---|---|---|
| `title` | Заголовок обоих окон | |
| `captcha-prompt` | Окно капчи | `<x>`, `<y>` — слагаемые |
| `captcha-wrong` | Окно капчи после ошибки | `<x>`, `<y>`, `<left>` — осталось попыток |
| `login-body` | Окно входа: основной текст | |
| `login-hint` | Окно входа: подсказка под текстом | `<minutes>` — сколько живёт ссылка |
| `login-button` | Кнопка, открывающая сайт | |
| `leave-button` | Кнопка «Выйти» | |
| `kick-closed` | Кик: плагин не настроен или версия Velocity не та | |
| `kick-bad-name` | Кик: ник не подходит (3–16 символов, латиница, цифры, `_`) | |
| `kick-old-client` | Кик: клиент не 26.3 | |
| `kick-rate-limit` | Кик: слишком много подключений с IP | |
| `kick-captcha-ban` | Кик: капча провалена | `<minutes>` |
| `kick-timeout` | Кик: не подтвердил вход за `wait-minutes` | |
| `kick-denied` | Кик: на сайте нажали «Это не я» | |
| `kick-site-down` | Кик: сайт недоступен, а живой сессии нет | |
| `kick-left` | Игрок нажал «Выйти» | |

### Что задаётся на сайте, а не в плагине
| Что | Значение | Где в коде |
|---|---|---|
| Сколько дней игра пускает с того же IP без сайта | 7 дней | `site/src/Players.php`, `SESSION_DAYS` |
| Сколько живёт ссылка из игры | 10 минут | `site/src/Ticket.php`, `TTL` |

---

## 7. Игровой сервер SMP

### 7.1. server.properties
Файл `servers/SMP/server.properties`. Удобнее всего править в панели: «Конфиги» → `server.properties`. Применить: перезапустить SMP.

**Не менять:**
| Ключ | Значение | Почему |
|---|---|---|
| `online-mode` | `false` | Лицензию проверяет не Mojang, а SiteGateAuth |
| `server-port` | `25565` | На этот порт внутри docker-сети ходит прокси |
| `enable-rcon` | `true` | Панель и `rcon-cli` |
| `rcon.port` | `25700` | Его ждёт образ (`RCON_PORT` в compose) |
| `rcon.password` | содержимое `secrets/rcon_pass` | Должны совпадать |

**Можно менять свободно:**

| Ключ | Пример | Описание |
|---|---|---|
| `motd` | `A Minecraft Server` | Не виден игрокам: они видят MOTD Velocity |
| `max-players` | `20` | Реальное ограничение онлайна |
| `difficulty` | `normal` | `peaceful`, `easy`, `normal`, `hard` |
| `gamemode` | `survival` | Режим новых игроков |
| `pvp` | `true` | PvP |
| `white-list` | `false` | `true` — пускать только из вайтлиста (в дополнение к входу через сайт) |
| `view-distance` | `10` | Дальность прорисовки в чанках. Сильнее всего влияет на нагрузку |
| `simulation-distance` | `10` | Дальность симуляции (мобы, фермы) |
| `spawn-protection` | `16` | Радиус защиты спавна. `0` — выключить |

### 7.2. paper-global.yml
Файл `servers/SMP/config/paper-global.yml`. Блок `proxies.velocity` **не трогать**:
```yaml
proxies:
  velocity:
    enabled: true
    online-mode: false
    secret: '…содержимое secrets/forwarding_secret…'
```
Остальное — обычные настройки Paper: https://docs.papermc.io/paper/reference/global-configuration

### 7.3. Плагины SMP
Папка `servers/SMP/plugins`. Ставятся как на любом Paper-сервере: jar в папку и перезапуск. Или через панель: «Плагины» → загрузить.

> UUID игроков офлайновые: их выдаёт Velocity из ника. Плагины, которые требуют лицензию (проверяют аккаунт Mojang), работать не будут.

### 7.4. Параметры контейнера
См. раздел 3: память (`SMP_MEMORY`, `mem_limit`), часовой пояс (`TZ`), владелец файлов (`UID`, `GID`).

---

## 8. SiteGateShop

### 8.1. config.yml
Файл `servers/SMP/plugins/SiteGateShop/config.yml` (шаблон: `smp/sitegateshop.yml`). Применить: перезапустить SMP. Товары перечитываются без перезапуска: `/shop reload`.

| Ключ | В шаблоне | Описание |
|---|---|---|
| `site.url` | `https://example.com` | Адрес сайта без `/` в конце |
| `site.token` | `""` | Токен магазина текстом. Задавайте что-то одно |
| `site.token-file` | `/run/secrets/shop_api_token` | Файл с токеном |
| `site.timeout-seconds` | `5` | Таймаут запроса к сайту |
| `poll-seconds` | `15` | Как часто забирать новые покупки. Ещё плагин проверяет их через 3 секунды после входа игрока |
| `messages.prefix` | `SiteGateShop »` | Префикс сообщений плагина |
| `messages.balance` | | Ответ на `/shop`. Подстановки: `<balance>`, `<url>` |
| `messages.not-registered` | | `/shop` у игрока без аккаунта на сайте |
| `messages.site-down` | | Сайт недоступен |
| `messages.delivered` | | Покупка выдана. Подстановка `<title>` |
| `messages.reloaded` | | После `/shop reload`. Подстановка `<count>` |
| `messages.disabled` | | Плагин не настроен (нет токена) |
| `messages.no-permission` | | Нет прав на `/shop reload` |

### 8.2. Команды и права
| Команда | Кто может | Что делает |
|---|---|---|
| `/shop` | Все | Баланс монет и ссылка на магазин |
| `/shop reload` | Право `sitegateshop.admin` (по умолчанию op) и консоль | Перечитать `products/`, отправить коды на сайт |

### 8.3. Товары: products/<код>.yml
Папка `servers/SMP/plugins/SiteGateShop/products/`. **Код товара = имя файла без `.yml`**: `[a-z0-9_]`, от 2 до 64 символов.

| Ключ | Тип | Описание |
|---|---|---|
| `require-online` | `true` / `false` | `true` — выдать, только когда игрок в игре. Нужно для предметов в инвентарь. `false` — выдать сразу (права, валюта плагинов) |
| `commands` | список строк | Команды от консоли по порядку. Подстановки: `{player}`, `{uuid}`, `{order}`, `{title}` |
| `message` | строка | Сообщение игроку после выдачи, MiniMessage. Пусто — `messages.delivered` |

Примеры:
```yaml
# products/diamonds_16.yml
require-online: true
commands:
  - "give {player} minecraft:diamond 16"
message: "<green>Вам выдано 16 алмазов!</green>"
```
```yaml
# products/vip_30d.yml — LuckPerms
require-online: false
commands:
  - "lp user {player} parent addtemp vip 30d"
```
```yaml
# products/points_500.yml — PlayerPoints
require-online: false
commands:
  - "points give {player} 500"
```

**Журнал выдачи:** `servers/SMP/plugins/SiteGateShop/delivered.json`. Плагин записывает в него результат **до** того, как сообщить сайту. Поэтому, если сайт не ответил или сервер упал, при следующем опросе уйдёт только подтверждение, без повторной выдачи. Не удаляйте этот файл, пока в админке есть заказы со статусом «ждёт выдачи».

---

## 9. Сайт

### 9.1. Настройки в админке (`/admin/settings`)
| Поле | Ключ | Обязательно | Описание |
|---|---|---|---|
| Название сервера | `server_name` | да | Шапка, заголовки страниц, админка. До 64 символов |
| Слоган | `slogan` | | Подзаголовок на главной |
| IP для показа | `display_ip` | да | Адрес сервера для игроков: шапка с кнопкой «скопировать», страница входа, сообщение после регистрации |
| Версия игры | `game_version` | | Подпись на главной |
| Ссылка на сборку | `modpack_url` | | Кнопка скачивания сборки. Пусто — кнопки нет |
| Discord | `discord_url` | | Кнопка Discord. Ссылка `https://…` (или `http://…`) |
| Текст баннера + «Показывать баннер» | `banner_text`, `banner_enabled` | | Полоса-объявление над шапкой |
| Фон hero | `hero_bg` | | Картинка первого экрана главной: JPG, PNG или WebP, до 5 МБ |

### 9.2. Контент
| Раздел админки | Что | Формат |
|---|---|---|
| Новости | Лента на `/news` и на главной, 10 на страницу | Markdown |
| Правила | Разделы и пункты на `/rules`, порядок стрелками | Markdown |
| Страницы | «О сервере», «Как начать играть» (`/start`), приветствие | Markdown |

### 9.3. Встроенные ограничения (в коде, не настраиваются в админке)
| Что | Значение | Файл |
|---|---|---|
| Пароль игрока | 8–128 символов, не равен нику | `src/Players.php` |
| Пароль админа сайта | от 12 символов | `bin/create-admin.php`, `src/Controller/Admin/PasswordController.php` |
| Ник | 3–16 символов: латиница, цифры, `_` | `src/Players.php` |
| Неверный пароль игрока | 5 раз за 15 минут на пару IP + ник, потом пауза | `src/Controller/TicketController.php`, `AccountController.php` |
| Регистрации | 5 в час с одного IP | `src/Controller/RegisterController.php` |
| Письма «Забыли пароль?» | 5 в час с одного IP | `src/Controller/MailLinkController.php` |
| Повторная отправка письма подтверждения | 3 в час | `src/Controller/AccountController.php` |
| Ссылка подтверждения почты | 24 часа | `src/EmailFlow.php` |
| Ссылка сброса пароля | 1 час, одноразовая, сбрасывает все входы | `src/EmailFlow.php` |
| Сессия по IP (вход без сайта) | 7 дней | `src/Players.php` |
| Ссылка входа из игры | 10 минут, одноразовая | `src/Ticket.php` |
| «Сервер онлайн» в шапке | если пульс от прокси был за последние 2 минуты | `src/ServerStatus.php` |
| Предупреждение о размере базы | от 3 ГБ, красным; лимит в подсказке 4 ГБ | `src/ShardStats.php` |
| Цена товара, разовое начисление монет | до 1 000 000 | `src/Shop.php` |
| Загрузка картинки | до 5 МБ | `src/Upload.php` |

Чтобы поменять: правка файла → `docker compose build web && docker compose up -d web`.

### 9.4. Тексты писем
Файл `../site/src/MailTemplates.php`: тема, абзацы и подпись трёх писем. В приветственном письме есть строка про правила («без грифа, без PvP…»): поменяйте её под свой сервер. После правки пересоберите сайт.

### 9.5. Логотип и иконки
См. [INSTALL.md, шаг 13.3](INSTALL.md#133-логотип-и-иконки).

### 9.6. Технические параметры
| Что | Где | Значение |
|---|---|---|
| Параллельных PHP-процессов | `../site/docker/mpm_prefork.conf`, `MaxRequestWorkers` | 12 (~15–25 МБ каждый) |
| Лимит памяти PHP на запрос | `../site/docker/php.ini`, `memory_limit` | 128M |
| Размер загрузки | `../site/docker/php.ini`, `upload_max_filesize` / `post_max_size` | 6M / 8M |
| Часовой пояс PHP и баз | `php.ini`, `date.timezone`; соединение с базой | UTC (время в базе всегда UTC) |
| Постоянные соединения с базами | переменная `DB_PERSISTENT` у `web` | `1` (включены). `0` — если хостинг баз ограничивает число соединений |

`DB_PERSISTENT` задаётся через override:
```yaml
services:
  web:
    environment:
      DB_PERSISTENT: "0"
```

---

## 10. Почта

Параметры контейнера `mail` в `compose.yml`:
| Параметр | Значение | Описание |
|---|---|---|
| `ALLOWED_SENDER_DOMAINS` | `${DOMAIN}` | От какого домена разрешено слать |
| `POSTFIX_myhostname`, `hostname` | `mail.${DOMAIN}` | Имя почтового сервера. Должно совпадать с PTR и A-записью `mail` |
| `DKIM_AUTOGENERATE` | `true` | Сгенерировать ключ при первом старте |
| `DKIM_SELECTOR` | `sg1` | Имя ключа → DNS-запись `sg1._domainkey`. Сменили — новая запись в DNS |
| `POSTFIX_message_size_limit` | 10 МБ | Максимальный размер письма |

Параметры сайта (в `compose.yml` у `web`): `MAIL_HOST=mail`, `MAIL_PORT=587`, `MAIL_FROM=noreply@${DOMAIN}`, `MAIL_FROM_NAME=${SITE_NAME}`, `MAIL_HELO=${DOMAIN}`.

**Чужой SMTP вместо своего Postfix** (например, если хостинг не открывает порт 25). Сайт умеет только SMTP без логина и без TLS. Внешние сервисы (Gmail, Mailgun, SendGrid) требуют и то и другое, поэтому напрямую не подойдут. Вариант: настроить Postfix-контейнер на пересылку через внешний сервис. В образе `boky/postfix` это переменные `RELAYHOST`, `RELAYHOST_USERNAME`, `RELAYHOST_PASSWORD`, см. документацию образа.

---

## 11. Панель

### 11.1. Переменные окружения
Задаются в `/etc/systemd/system/sitegate-panel.service` строками `Environment=ИМЯ=значение`. Применить: `sudo systemctl daemon-reload && sudo systemctl restart sitegate-panel`.

| Переменная | По умолчанию | Описание |
|---|---|---|
| `SG_STACK` | `/opt/sitegate/infra` | Папка стека |
| `SG_BACKUPS` | `/opt/sitegate/infra/backups` | Куда класть бэкапы мира |
| `SG_PANEL_DATA` | `infra/panel/data` | Пароль, настройки, история конфигов |
| `SG_PANEL_HOST` | `127.0.0.1` | Адрес. **Не ставьте `0.0.0.0`**: панель уйдёт в интернет |
| `SG_PANEL_PORT` | `8780` | Порт |
| `SG_SITE_URL` | `https://example.com` | Ссылка на сайт в панели |

### 11.2. Настройки в самой панели
| Что | По умолчанию | Где |
|---|---|---|
| Автобэкап, раз в N часов | 12 | «Бэкапы», от 0 до 168. `0` — выключить |
| Сколько бэкапов хранить | 14 | «Бэкапы», от 1 до 200. Старые удаляются сами |

### 11.3. Безопасность
- Пароль от 10 символов, хранится как PBKDF2-SHA256 (300 000 итераций). Сменить: `echo 'новый' | python3 panel/panel.py set-password` (без sudo), все сессии сбрасываются.
- 10 неверных паролей за 15 минут → вход блокируется до конца этих 15 минут.
- Сессия живёт 12 часов.
- Защита: проверка `Host` (от DNS rebinding), заголовок `X-Panel` и `Origin` (от CSRF), загрузка плагинов до 64 МБ.

### 11.4. Что попадает в бэкап мира
Вся папка `servers/SMP`, кроме: `libraries`, `versions`, `cache`, `logs`, `crash-reports`, `server.jar`, `.paper-remapped`. Перед бэкапом панель делает `save-off` и `save-all flush`, после — `save-on`: мир сохраняется целым.

---

## 12. Что перезапускать после изменений

| Что изменили | Команда |
|---|---|
| `.env` | `docker compose up -d` |
| `compose.override.yml` | `docker compose up -d` |
| `site/Caddyfile` (или `.local`) | `docker compose restart site` |
| `servers/proxy/velocity.toml`, конфиг SiteGateAuth, jar SiteGateAuth | `docker compose restart proxy` (все отключатся) |
| `server.properties`, `paper-global.yml`, плагины SMP, конфиг SiteGateShop | `docker compose restart smp` (или кнопка в панели) |
| `products/*.yml` | `docker exec -i sg-smp rcon-cli shop reload` |
| Код сайта, иконки, тексты писем | `docker compose build web && docker compose up -d web` |
| Секрет в `secrets/` | см. [OPERATIONS.md → Смена токенов](OPERATIONS.md#смена-токенов-и-секретов) |
| Служба панели | `sudo systemctl daemon-reload && sudo systemctl restart sitegate-panel` |
