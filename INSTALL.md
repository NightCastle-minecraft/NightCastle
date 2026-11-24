# Установка SiteGate с нуля

Инструкция ведёт от пустого VPS до сервера, на который игроки заходят через ваш сайт. Шаги идут по порядку, пропускать нельзя: каждый следующий опирается на предыдущий. Везде ниже:
- `example.com` — ваш домен;
- `203.0.113.10` — IP вашего VPS;
- `MyServer` — название вашего сервера.

Замените их на свои.

**Время:** 1–2 часа, если делать впервые.

---

## 0. Что понадобится

| Что | Зачем | Где взять |
|---|---|---|
| VPS с Ubuntu 22.04/24.04 (или другой Linux), от 4 ГБ RAM, лучше 8 | Всё крутится там | Любой хостинг VPS |
| Домен | Адрес сайта и сервера | Любой регистратор. DNS удобно держать в Cloudflare |
| 1–3 базы MySQL 8 или MariaDB 10.4+ | Аккаунты, сайт, магазин | У хостинга или своя MySQL. Можно одну базу на всё (см. шаг 3) |
| `server.jar` Paper или Leaf под Minecraft 26.3 | Сам игровой сервер | papermc.io или leafmc.one |
| JDK 25 на своём компьютере | Собрать плагины | adoptium.net (Temurin 25) |
| Доступ по SSH к VPS | Всё ставится через терминал | Даёт хостинг |

Игроки должны играть на клиенте **Minecraft: Java Edition 26.3**. Лицензия не нужна: проверку делает SiteGateAuth.

---

## 1. Подготовка VPS

Зайдите на VPS по SSH. Дальше команды выполняются там, от обычного пользователя с `sudo` (в примерах пользователь `ubuntu`).

### 1.1. Docker и Docker Compose
```bash
sudo apt update
sudo apt install -y ca-certificates curl git python3 openssl
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```
**Перезайдите по SSH**, чтобы группа `docker` применилась, и проверьте:
```bash
docker version
docker compose version     # нужен Compose v2
```

### 1.2. Код
```bash
sudo mkdir -p /opt/sitegate && sudo chown $USER:$USER /opt/sitegate
git clone https://github.com/FeniksGames/NightCastle.git /opt/sitegate
cd /opt/sitegate/infra
```
Дальше почти все команды выполняются из `/opt/sitegate/infra`.

### 1.3. Фаервол
Наружу нужны только порты **22** (SSH), **80** и **443** (сайт), **25565** (игра).
```bash
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443 && sudo ufw allow 25565/tcp
sudo ufw enable
```
> Docker публикует порты контейнеров в обход ufw. Поэтому в `compose.yml` наружу открыто только то, что нужно, а SMP, почта и сайт на PHP наружу не смотрят вообще.

---

## 2. DNS

В панели DNS вашего домена создайте записи. Если DNS в Cloudflare, у всех записей выключите прокси: **серое облако, DNS only**. Через оранжевое облако Minecraft не подключится.

| Тип | Имя | Значение | Зачем |
|---|---|---|---|
| A | `example.com` | `203.0.113.10` | Сайт и игра на одном адресе |
| A | `www.example.com` | `203.0.113.10` | Редирект на основной домен |
| A | `mail.example.com` | `203.0.113.10` | Имя почтового сервера |
| TXT | `example.com` | `v=spf1 ip4:203.0.113.10 -all` | SPF: письма от домена шлёт только VPS |
| TXT | `mail.example.com` | `v=spf1 a -all` | SPF для имени почтового сервера |
| TXT | `_dmarc.example.com` | `v=DMARC1; p=quarantine; pct=100; adkim=s; aspf=s` | DMARC |

Запись DKIM добавите на шаге 9, когда Postfix сгенерирует ключ.

**PTR (обратная запись) для `203.0.113.10` → `mail.example.com`** ставится у провайдера VPS, в его панели или через поддержку. Без неё Gmail и другие почтовики могут отбивать письма с ошибкой `550 5.7.25`.

Проверка (через пару минут):
```bash
dig +short A example.com @1.1.1.1         # → 203.0.113.10
dig +short TXT example.com @1.1.1.1       # → "v=spf1 ip4:203.0.113.10 -all"
```

---

## 3. Базы данных

Нужны базы MySQL 8 или MariaDB 10.4+ с кодировкой `utf8mb4`.

- **Три базы** (так задумано): аккаунты игроков делятся между ними по нику (`crc32(ник) % 3 + 1`). DB1 вдобавок хранит таблицы сайта. Это удобно, если у хостинга лимит размера на одну базу.
- **Одна база** тоже работает: впишите её во все три набора `DB1_*`, `DB2_*`, `DB3_*`. Миграции увидят, что таблицы уже есть, и пропустят их.

Для каждой базы запишите: хост, порт (обычно 3306), имя базы, пользователя, пароль. Пользователю нужны права на создание таблиц в своей базе.

Если поднимаете MySQL сами:
```sql
CREATE DATABASE sitegate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sitegate'@'%' IDENTIFIED BY 'длинный-случайный-пароль';
GRANT ALL PRIVILEGES ON sitegate.* TO 'sitegate'@'%';
```

---

## 4. Настройки `.env` и секреты

### 4.1. `.env`
```bash
cd /opt/sitegate/infra
cp .env.example .env
chmod 600 .env
nano .env
```
Заполните:
```ini
DOMAIN=example.com          # ваш домен, без https://
SITE_NAME=MyServer          # название сервера в письмах
GAME_ADDRESS=               # адрес для игроков в приветственном письме (пусто = DOMAIN)
SMP_MEMORY=4G               # память Java для SMP: оставьте ~2 ГБ системе и остальным контейнерам

DB1_HOST=db.example.net
DB1_NAME=sitegate1
DB1_USER=sitegate1
DB1_PASSWORD='пароль'       # в одинарных кавычках, если есть $ или пробелы
# ...так же DB2_* и DB3_* (или те же значения, если база одна)
```
> Если ставите `SMP_MEMORY` больше 5G, поднимите в `compose.yml` и `mem_limit` у сервиса `smp`: он должен быть на ~1.5 ГБ больше.

### 4.2. Секреты
```bash
sh bin/init-secrets.sh
ls -l secrets/
```
Появятся четыре файла:
- `rcon_pass`: пароль RCON для консоли SMP;
- `auth_api_token`: токен между сайтом и SiteGateAuth;
- `shop_api_token`: токен между сайтом и SiteGateShop;
- `forwarding_secret`: секрет передачи данных игрока Velocity → SMP.

Повторный запуск ничего не перезаписывает. Папка `secrets/` и `.env` в git не попадают.

Проверка, что Compose всё видит:
```bash
docker compose config -q && echo ok
```

---

## 5. Сборка плагинов

Плагины собираются **на вашем компьютере** с JDK 25 (на VPS тоже можно, если поставить JDK).

SiteGateShop собирается против библиотек вашего сервера Paper/Leaf. Их сервер сам скачивает в папку `libraries` при первом запуске. Поэтому сначала один раз запустите `server.jar` где угодно, например на своём компьютере:
```bash
mkdir ~/mc-libs && cd ~/mc-libs
cp /путь/к/server.jar .
echo "eula=true" > eula.txt
java -jar server.jar --nogui     # дождитесь "Done", затем введите stop
```
Leaf при самом первом запуске может создать только свой конфиг и выйти. Тогда запустите его ещё раз.

Теперь сборка:
```bash
git clone https://github.com/FeniksGames/NightCastle.git && cd NightCastle/plugins
export JAVA_HOME=/путь/к/jdk-25          # если java по умолчанию не 25
./gradlew build -PserverLibs=$HOME/mc-libs/libraries
```
Результат:
- `plugins/auth/build/libs/SiteGateAuth-1.0.0.jar`: плагин для Velocity;
- `plugins/shop/build/libs/SiteGateShop-1.0.0.jar`: плагин для SMP;
- `plugins/libs/velocity-4.3.1-45.jar`: сам Velocity. Gradle скачивает его и сверяет sha256.

`./gradlew build` заодно гоняет тесты (JUnit). Если они упали, плагин не соберётся. Разберитесь, прежде чем ставить его на сервер.

---

## 6. Игровой сервер (SMP)

### 6.1. Первый запуск
```bash
cd /opt/sitegate/infra
mkdir -p servers/SMP
cp /путь/к/server.jar servers/SMP/server.jar      # Paper или Leaf 26.3
docker compose up -d smp
docker compose logs -f smp       # дождитесь "Done (...)! For help, type "help"", затем Ctrl+C
docker compose stop smp
```
> Если файлы в `servers/SMP` созданы от root и не редактируются: `sudo chown -R $USER:$USER servers/`.

### 6.2. `server.properties`
Откройте `servers/SMP/server.properties` и выставьте:
```properties
online-mode=false            # проверку делает SiteGateAuth на прокси
white-list=false             # в 26.3 по умолчанию true: иначе не пустит никого
enable-rcon=true
rcon.port=25700
rcon.password=ВСТАВЬТЕ_ИЗ_secrets/rcon_pass
server-port=25565
```
Пароль RCON берётся из файла: `cat secrets/rcon_pass`.

### 6.3. Передача данных игрока через Velocity
Откройте `servers/SMP/config/paper-global.yml`, найдите блок `proxies:` и приведите `velocity` к такому виду:
```yaml
proxies:
  velocity:
    enabled: true
    online-mode: false
    secret: 'ВСТАВЬТЕ_ИЗ_secrets/forwarding_secret'
```
Секрет: `cat secrets/forwarding_secret`. Он должен совпадать символ в символ, иначе SMP не пустит никого с ошибкой про forwarding.

### 6.4. Плагин SiteGateShop
```bash
mkdir -p servers/SMP/plugins/SiteGateShop
cp /путь/к/SiteGateShop-1.0.0.jar servers/SMP/plugins/
cp smp/sitegateshop.yml servers/SMP/plugins/SiteGateShop/config.yml
nano servers/SMP/plugins/SiteGateShop/config.yml    # site.url: "https://example.com"
```
Токен трогать не надо: в шаблоне уже стоит `token-file: "/run/secrets/shop_api_token"`. Compose сам кладёт туда файл из `secrets/`.

---

## 7. Прокси Velocity и SiteGateAuth

```bash
cd /opt/sitegate/infra
mkdir -p servers/proxy/plugins/sitegateauth
cp /путь/к/velocity-4.3.1-45.jar servers/proxy/velocity.jar
cp /путь/к/SiteGateAuth-1.0.0.jar servers/proxy/plugins/
cp proxy/velocity.toml servers/proxy/velocity.toml
cp proxy/sitegateauth.toml servers/proxy/plugins/sitegateauth/config.toml
nano servers/proxy/plugins/sitegateauth/config.toml   # [site] url = "https://example.com"
nano servers/proxy/velocity.toml                      # motd: название вашего сервера
```
Что уже настроено в шаблоне `velocity.toml`:
- `online-mode = false`;
- `player-info-forwarding-mode = "MODERN"`, секрет из `/run/secrets/forwarding_secret`;
- `smp = "smp:25565"`, `try = ["smp"]`.

> **Velocity обновлять только вместе с SiteGateAuth.** Плагин опирается на внутренние классы именно 4.3.1 (build 45). На другой версии он пишет об этом в лог и закрывает вход для всех.

Тексты для игроков (капча, кнопка, кики) живут в `[messages]` того же `config.toml`, формат MiniMessage.

---

## 8. Запуск сайта и всего стека

```bash
cd /opt/sitegate/infra
docker compose build                 # соберёт образ сайта и Caddy с плагином rate limit (несколько минут)
docker compose run --rm --no-deps -T web php bin/migrate.php
```
Миграции создадут таблицы во всех трёх базах. Повторный запуск безопасен.

Затем:
```bash
docker compose up -d
docker compose ps                    # все контейнеры Up, у smp/proxy/mail — healthy
docker exec -it sg-web php bin/create-admin.php admin   # логин админки, спросит пароль (от 12 символов)
```
Caddy сам получит сертификат Let's Encrypt для `example.com` и `www.example.com`: DNS из шага 2 должен уже работать.

Проверка:
```bash
curl -sI https://example.com/ | head -1          # HTTP/2 200
curl -s https://example.com/api/status           # {"online":true,...} — пульс от SiteGateAuth
```
Админка: `https://example.com/admin`. В «Настройках» задайте название сервера, слоган, адрес для игроков и Discord. Правила и страницы правятся в соседних разделах.

---

## 9. Почта

Контейнер `sg-mail` при первом старте сгенерировал ключ DKIM. Достаньте публичную часть:
```bash
docker exec sg-mail cat /var/lib/rspamd/dkim/example.com.sg1.txt
```
Там будет `sg1._domainkey IN TXT ( "v=DKIM1; k=rsa; " "p=MIIB..." )`. Создайте в DNS запись:

| Тип | Имя | Значение |
|---|---|---|
| TXT | `sg1._domainkey.example.com` | `v=DKIM1; k=rsa; p=MIIB...` (все кусочки в кавычках склеить в одну строку) |

Проверка отправки:
```bash
docker exec sg-web php -r 'require "vendor/autoload.php"; [$s,$t,$h] = SiteGate\MailTemplates::welcome("Test", "https://example.com"); SiteGate\Mailer::fromEnv()->send("ВАШ_АДРЕС@gmail.com", $s, $t, $h); echo "ok\n";'
docker logs sg-mail 2>&1 | grep "status="      # status=sent — принято сервером получателя
```
В Gmail откройте письмо → «Показать оригинал»: должно быть `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`.

> Ключ DKIM живёт в томе `sg-mail-dkim`. Удалите том — получите новый ключ, и запись придётся обновить.

**Логотип в письмах:** `site/public/assets/img/mail-logo.png`, квадрат 256×256. **Иконка сайта:** `site/public/favicon.ico` и соседние `*.png`. Замените на свои и пересоберите сайт (`docker compose build web && docker compose up -d web`).

---

## 10. Панель управления

Панель — это служба на самом VPS (не контейнер), она слушает только `127.0.0.1:8780`. Снаружи её не видно, открывается через SSH-туннель.

```bash
cd /opt/sitegate/infra
nano panel/sitegate-panel.service      # User=/Group= — ваш пользователь из группы docker
sudo cp panel/sitegate-panel.service /etc/systemd/system/
echo 'длинный пароль панели' | python3 panel/panel.py set-password
sudo systemctl daemon-reload
sudo systemctl enable --now sitegate-panel
systemctl status sitegate-panel --no-pager
```
С вашего компьютера:
```bash
ssh -N -L 8780:127.0.0.1:8780 ubuntu@203.0.113.10
```
и откройте http://localhost:8780.

Что в панели:
- статус и память контейнеров, запуск, перезапуск и остановка;
- консоль SMP, объявления;
- игроки: кик, бан, op, вайтлист, аккаунт на сайте, новый пароль, баланс монет;
- бэкапы мира: вручную и по расписанию, в `infra/backups`;
- редактор `server.properties` и конфигов, плагины SMP.

---

## 11. Первый вход: проверка всей цепочки

1. В клиенте Minecraft 26.3: «Сетевая игра» → «Добавить» → адрес `example.com`.
2. При входе появится окно с примером. Решите его.
3. Появится окно «Нажмите, чтобы авторизоваться». Кнопка откроет браузер на `https://example.com/auth/…`.
4. На сайте:
   - аккаунта нет → регистрация: почта и пароль;
   - есть → пароль;
   - затем «Подтвердить вход».
5. Через пару секунд вы на SMP. Следующие 7 дней с того же IP игра пускает сразу.

Либо зарегистрируйтесь заранее на `https://example.com/register` и при входе в игру просто введите пароль.

Если что-то не так, смотрите логи:
```bash
docker compose logs -f proxy    # SiteGateAuth: капча, тикеты, ошибки связи с сайтом
docker compose logs -f web      # PHP
docker compose logs -f smp
```

---

## 12. Магазин

1. Опишите выдачу: файл `servers/SMP/plugins/SiteGateShop/products/<код>.yml`. Пример `example_diamonds.yml` создаётся сам. Код — это имя файла, только `a-z`, `0-9` и `_`.
   ```yaml
   require-online: false                         # true = выдать, только когда игрок в игре
   commands:
     - "lp user {player} parent addtemp vip 30d"
   message: "<green>VIP активирован!"
   ```
   Подстановки в командах: `{player}`, `{uuid}`, `{order}`, `{title}`.
2. В консоли SMP (через панель или `docker exec -i sg-smp rcon-cli shop reload`) выполните `shop reload`. Плагин отправит сайту список кодов.
3. В админке → «Магазин» → «Новый товар»: тот же код, название, цена в монетах, описание.
4. Монеты игрокам начисляются в админке («Кошельки») или в панели («Игроки» → «Изменить баланс»). Приёма платежей нет: подключать платёжную систему придётся самим.

---

## 13. Обновление

```bash
cd /opt/sitegate && git pull
cd infra
docker compose build web
docker compose run --rm --no-deps -T web php bin/migrate.php
docker compose up -d web
```
Плагины: соберите заново (шаг 5), положите jar в `servers/proxy/plugins` или `servers/SMP/plugins` и перезапустите `proxy` или `smp`. **Velocity не обновляйте отдельно от SiteGateAuth.**

---

## 14. Частые проблемы

| Симптом | Причина и решение |
|---|---|
| В игре «Вход временно закрыт» | SiteGateAuth не настроен: пустой токен, нет файла токена или не та версия Velocity. Смотрите `docker compose logs proxy` |
| «Сайт авторизации недоступен» | Прокси не достучался до сайта. Проверьте `[site] url` в `config.toml` плагина и что `https://example.com/api/status` открывается |
| SMP пишет про velocity/forwarding и не пускает | `secret` в `paper-global.yml` не совпадает с `secrets/forwarding_secret`, либо `proxies.velocity.enabled` не `true` |
| «You are not whitelisted» | В 26.3 `white-list=true` по умолчанию. Поставьте `false` (шаг 6.2) |
| Сайт выглядит без стилей после обновления | Кеш браузера: Ctrl+Shift+R. CSS и JS подключаются с версией в адресе, так что это разовая проблема |
| Письма не доходят до Gmail | Нет PTR (шаг 2) или DKIM (шаг 9). Смотрите `docker logs sg-mail \| grep status=` |
| Контейнер убивается (OOM) | Не хватает памяти: уменьшите `SMP_MEMORY` |
| `docker compose up` ругается на secrets | Не запущен `sh bin/init-secrets.sh` (шаг 4.2) |

---

## 15. Безопасность: коротко

- SMP, почта и PHP наружу не открыты. Зайти в игру можно только через прокси с SiteGateAuth.
- Токены и пароли живут только в `infra/.env` и `infra/secrets/` на сервере. Не коммитьте их.
- Админка сайта и панель защищены паролями. Панель вообще не видна из интернета.
- Делайте бэкапы мира (панель умеет по расписанию) и баз (средствами хостинга).
