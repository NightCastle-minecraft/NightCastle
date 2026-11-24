# Установка SiteGate: полная инструкция

Инструкция ведёт от пустого VPS до работающего сервера:
- игроки заходят в Minecraft 26.3 по адресу вашего домена;
- проходят капчу и подтверждают вход на вашем сайте;
- получают письма;
- покупают в магазине.

Шаги идут строго по порядку: каждый следующий опирается на предыдущий. После каждого шага есть **проверка**. Не идите дальше, пока она не прошла.

**Время:** 1,5–3 часа, если делаете впервые. Дольше всего ждать DNS и сборку образов.

Рядом лежат:
- [CONFIGURATION.md](CONFIGURATION.md): справочник по каждой настройке;
- [OPERATIONS.md](OPERATIONS.md): жизнь сервера после установки (бэкапы, обновления, переезд, смена паролей);
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): что делать, если что-то не работает.

---

## Как читать эту инструкцию

**Заглушки.** В командах и примерах стоят условные значения. Везде меняйте их на свои:

| В инструкции | Что это | Пример вашего |
|---|---|---|
| `example.com` | Ваш домен | `mycraft.net` |
| `203.0.113.10` | IP вашего VPS | `91.200.14.7` |
| `MyServer` | Название сервера | `MyCraft` |
| `ubuntu` | Ваш пользователь на VPS | `ubuntu`, `admin` |
| `Steve` | Ваш ник в игре | `Notch_2010` |
| `you@gmail.com` | Ваша почта | `me@gmail.com` |

**Где выполнять команды.** У каждого блока команд стоит пометка:
- **[VPS]**: в терминале, подключённом к серверу по SSH;
- **[ПК]**: на вашем компьютере.

**Правила:**
- Блок команд копируйте целиком и вставляйте в терминал. В терминале Linux вставка обычно `Ctrl+Shift+V` или правая кнопка мыши.
- Строки, которые начинаются с `#`, — это комментарии, их можно не копировать.
- Если команда выдала ошибку (`error`, `ошибка`, `permission denied`, `not found`), **остановитесь**. Найдите текст ошибки в [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
- Редактор файлов в инструкции — `nano`:
  - стрелки двигают курсор;
  - `Ctrl+W` — поиск;
  - `Ctrl+O`, затем `Enter` — сохранить;
  - `Ctrl+X` — выйти.

---

## Содержание

0. [Как всё устроено](#0-как-всё-устроено)
1. [Что понадобится](#1-что-понадобится)
2. [VPS: первый вход и подготовка](#2-vps-первый-вход-и-подготовка)
3. [Домен и DNS](#3-домен-и-dns)
4. [Базы данных](#4-базы-данных)
5. [Код на VPS](#5-код-на-vps)
6. [Файл .env](#6-файл-env)
7. [Секреты](#7-секреты)
8. [Игровой сервер SMP](#8-игровой-сервер-smp)
9. [Сборка плагинов](#9-сборка-плагинов)
10. [SiteGateShop на SMP](#10-sitegateshop-на-smp)
11. [Velocity и SiteGateAuth](#11-velocity-и-sitegateauth)
12. [Сайт: сборка, миграции, запуск](#12-сайт-сборка-миграции-запуск)
13. [Настройка сайта в админке](#13-настройка-сайта-в-админке)
14. [Почта](#14-почта)
15. [Панель управления](#15-панель-управления)
16. [Первый вход в игру](#16-первый-вход-в-игру)
17. [Магазин](#17-магазин)
18. [Финальная проверка](#18-финальная-проверка)
19. [Что сделать сразу после установки](#19-что-сделать-сразу-после-установки)

---

## 0. Как всё устроено

```
игрок (Minecraft 26.3)
   │  example.com:25565
   ▼
sg-proxy: Velocity 4.3.1 + SiteGateAuth ── HTTPS API ──► sg-site: Caddy (HTTPS)
   │  капча, окно «Нажмите, чтобы авторизоваться»               │
   │  ждёт подтверждения на сайте                               ▼
   ▼                                                  sg-web: сайт PHP 8.3 ──► базы MySQL/MariaDB (1–3)
sg-smp: Paper/Leaf 26.3 + SiteGateShop ── HTTPS API ────────┘       │
   (наружу не открыт)                                               ▼
                                                     sg-mail: Postfix + DKIM ──► письма игрокам
панель: служба на VPS, 127.0.0.1:8780, открывается через SSH-туннель
```

Всё, кроме панели, работает в Docker-контейнерах. Их описывает файл `infra/compose.yml`, а запускает Docker Compose.

| Контейнер | Что делает | Порт наружу |
|---|---|---|
| `sg-site` | Caddy: HTTPS-сертификат, лимиты запросов, отдаёт сайт | 80, 443 |
| `sg-web` | Сайт на PHP: аккаунты, вход, кабинет, магазин, админка, API для плагинов | нет |
| `sg-mail` | Postfix: только отправка писем, подписывает их DKIM | нет |
| `sg-proxy` | Velocity + SiteGateAuth: единственный вход в игру | 25565 |
| `sg-smp` | Игровой сервер Paper или Leaf + SiteGateShop | нет |

Подробно про устройство написано в [ARCHITECTURE.md](ARCHITECTURE.md).

---

## 1. Что понадобится

### 1.1. VPS

| Параметр | Минимум | Рекомендуется | Почему |
|---|---|---|---|
| ОС | Ubuntu 22.04 | **Ubuntu 24.04 LTS** | Все команды в инструкции для Ubuntu/Debian |
| Память | 4 ГБ | **8 ГБ** | Minecraft-сервер ест больше всех |
| Процессор | 2 ядра | 4 ядра с высокой частотой | Основной поток Minecraft одноядерный: частота важнее количества |
| Диск | 20 ГБ SSD | 40+ ГБ NVMe | Мир растёт, бэкапы хранятся там же (по умолчанию 14 штук) |
| Доступ | SSH | SSH по ключу | |

Сколько памяти отдать игровому серверу (подробно в шаге 6.3):

| Память VPS | Игроков онлайн | `SMP_MEMORY` | `mem_limit` у `smp` |
|---|---|---|---|
| 4 ГБ | до 5–8 | `2G` | `3200m` |
| 6 ГБ | до 10–15 | `3G` | `4500m` |
| 8 ГБ | до 20–30 | `5G` | `6500m` (стоит в шаблоне) |
| 16 ГБ | 40+ | `10G` | `12000m` |

> Остальные контейнеры вместе берут примерно 1–1,4 ГБ, система ещё 0,5 ГБ. На VPS с 4–6 ГБ обязательно включите swap (шаг 2.5).

**Почта.** Многие хостинги VPS по умолчанию закрывают исходящий порт 25: от спама. Без него письма не уйдут. Как проверить, сказано в шаге 2.9. Если порт закрыт, его открывают по запросу в поддержку.

### 1.2. Остальное

| Что | Зачем | Где взять |
|---|---|---|
| Домен | Адрес сайта и игры | Любой регистратор. DNS удобно держать в Cloudflare (бесплатно) |
| 1–3 базы MySQL 8 или MariaDB 10.4+ | Аккаунты, сайт, магазин | У хостинга баз или своя на том же VPS (шаг 4, вариант Б) |
| `server.jar` под Minecraft 26.3 | Сам игровой сервер | **Leaf**: leafmc.one, или **Paper**: papermc.io |
| Клиент Minecraft: Java Edition **26.3** | Проверить вход | Любой лаунчер. Лицензия не нужна |
| SSH-клиент | Управлять VPS | Linux/macOS: встроенный `ssh`. Windows 10/11: встроенный `ssh` в PowerShell или PuTTY |

> Игроки должны играть именно на **26.3**. Окна-диалоги, на которых держится вход, есть в клиенте с 1.21.6, а плагин рассчитан на протокол 26.3.

---

## 2. VPS: первый вход и подготовка

### 2.1. Подключение по SSH

**[ПК]** Хостинг выдаёт IP, логин (часто `root` или `ubuntu`) и пароль или ключ.
```bash
ssh root@203.0.113.10          # или ubuntu@203.0.113.10
```
- На первый вопрос `Are you sure you want to continue connecting?` ответьте `yes`.
- Пароль при вводе не отображается, так и задумано.

**Windows.** Откройте PowerShell (Пуск → «PowerShell») и введите ту же команду.

### 2.2. Пользователь с sudo (если вам дали только root)

Работать от `root` небезопасно, а файлы игрового сервера должны принадлежать обычному пользователю. Если вы уже зашли как `ubuntu` или другой обычный пользователь, пропустите этот шаг.

**[VPS]** под root:
```bash
adduser ubuntu                       # задайте пароль, остальные вопросы можно пропустить Enter
usermod -aG sudo ubuntu
# если вы входили по ключу, скопируйте его новому пользователю:
rsync --archive --chown=ubuntu:ubuntu ~/.ssh /home/ubuntu
```
Выйдите (`exit`) и зайдите заново уже как `ubuntu`:
```bash
ssh ubuntu@203.0.113.10
```

**Проверка [VPS]:**
```bash
id
```
Должно быть `uid=1000(ubuntu) gid=1000(ubuntu) groups=...,sudo,...`.

> **Важно про `uid=1000`.** Контейнеры Minecraft по умолчанию работают от пользователя с номером 1000. Если у вас `uid` другой, например 1001, сделайте всё, что написано в шаге 8.2, иначе будут ошибки доступа к файлам.

### 2.3. Обновление системы и нужные программы

**[VPS]**
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y ca-certificates curl git python3 openssl nano dnsutils ufw unzip
```
Если после обновления появилось `*** System restart required ***`, перезагрузитесь и зайдите снова:
```bash
sudo reboot
```

### 2.4. Часовой пояс (по желанию)

От него зависит время в логах и в именах бэкапов.

**[VPS]**
```bash
timedatectl list-timezones | grep Europe     # найдите свой
sudo timedatectl set-timezone Europe/Kyiv
```
> У игрового сервера свой часовой пояс в `compose.yml` (`TZ: Europe/Kyiv`). Как его сменить, сказано в шаге 6.4.

### 2.5. Swap (обязательно при 4–6 ГБ памяти)

Swap — это запасная память на диске. Она не даст системе убить игровой сервер при кратком всплеске нагрузки.

**[VPS]**
```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swappiness.conf && sudo sysctl -p /etc/sysctl.d/99-swappiness.conf
```
**Проверка:** `free -h`, в строке `Swap:` должно стоять `2.0Gi`.

### 2.6. Docker и Docker Compose

**[VPS]**
```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```
Ограничьте размер логов контейнеров. Без этого они растут бесконечно и за несколько месяцев могут занять весь диск:
```bash
echo '{"log-driver":"json-file","log-opts":{"max-size":"20m","max-file":"3"}}' | sudo tee /etc/docker/daemon.json
sudo systemctl restart docker
```
**Выйдите из SSH (`exit`) и зайдите снова.** Без этого группа `docker` не применится и каждая команда `docker` будет требовать `sudo`.

**Проверка [VPS]:**
```bash
docker version --format '{{.Server.Version}}'      # версия, например 28.x
docker compose version                             # Docker Compose version v2.x
docker run --rm hello-world                        # "Hello from Docker!"
```
Если пишет `permission denied ... docker.sock`, значит вы не перезашли после `usermod`.

### 2.7. Свободны ли нужные порты

Сайту нужны порты 80 и 443, игре — 25565. Если на VPS уже стоит веб-сервер (nginx, apache), он их занимает.

**[VPS]**
```bash
sudo ss -ltnp | grep -E ':(80|443|25565)\s' || echo "порты свободны"
```
Если вывод не `порты свободны`, посмотрите, кто занял порт (`users:(("nginx",...` и т.п.), и остановите его:
```bash
sudo systemctl disable --now nginx apache2 2>/dev/null; sudo ss -ltnp | grep -E ':(80|443)\s' || echo "порты свободны"
```
> На этом VPS уже работает другой сайт, который нельзя выключать? Тогда SiteGate по этой инструкции не встанет: Caddy должен сам держать 80 и 443, чтобы получить сертификат. Возьмите отдельный VPS. Либо (только для опытных) настройте свой веб-сервер проксировать `example.com` на контейнер `sg-site`.

### 2.8. Фаервол

**Сначала разрешите SSH**, иначе отрежете себе доступ.

**[VPS]**
```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443
sudo ufw allow 25565/tcp
sudo ufw enable                 # на вопрос ответьте y
sudo ufw status
```
В `ufw status` должны быть `OpenSSH`, `80/tcp`, `443`, `25565/tcp` со статусом `ALLOW`.

> **Docker и ufw.** Docker открывает порты контейнеров в обход ufw. Поэтому защита сделана в `compose.yml`: наружу опубликованы только 80, 443 и 25565. SMP, почта, PHP и RCON наружу не смотрят вообще.

> **Фаервол хостинга.** У некоторых хостингов есть свой фаервол в панели («Security groups», «Firewall»). Если он включён, откройте там те же порты: 22, 80, 443, 25565/tcp.

### 2.9. Исходящий порт 25 (для почты)

**[VPS]**
```bash
timeout 7 bash -c '</dev/tcp/gmail-smtp-in.l.google.com/25' && echo "порт 25 ОТКРЫТ" || echo "порт 25 ЗАКРЫТ"
```
- `ОТКРЫТ` — всё хорошо.
- `ЗАКРЫТ` — напишите в поддержку хостинга: *«Прошу открыть исходящий порт 25/tcp для сервера 203.0.113.10. Отправляю только письма своего сайта (подтверждение почты, сброс пароля), SPF, DKIM и DMARC настроены»*. Пока порт закрыт, всё остальное работает, только письма не уходят.

---

## 3. Домен и DNS

### 3.1. Записи

Откройте управление DNS вашего домена: в Cloudflare это раздел **DNS → Records**, у регистратора обычно «DNS», «Зона» или «Записи». Создайте записи:

| Тип | Имя (Name) | Значение (Content) | Зачем |
|---|---|---|---|
| `A` | `@` (= `example.com`) | `203.0.113.10` | Сайт и игра на одном адресе |
| `A` | `www` | `203.0.113.10` | `www.example.com` перекидывает на `example.com` |
| `A` | `mail` | `203.0.113.10` | Имя почтового сервера |
| `TXT` | `@` | `v=spf1 ip4:203.0.113.10 -all` | SPF: письма от домена шлёт только ваш VPS |
| `TXT` | `mail` | `v=spf1 a -all` | SPF для имени почтового сервера |
| `TXT` | `_dmarc` | `v=DMARC1; p=quarantine; pct=100; adkim=s; aspf=s` | DMARC: что делать с поддельными письмами |

**В Cloudflare у каждой A-записи выключите прокси**: значок облака должен быть **серым** («DNS only»). Через оранжевое облако Minecraft не подключится, а Caddy не получит сертификат.

> Если у домена уже есть другие SPF-записи (`v=spf1 ...` на `@`), не заводите вторую: объедините их в одну. Две SPF-записи ломают проверку.

> Запись DKIM появится позже, в шаге 14: ключ генерирует сам почтовый контейнер.

### 3.2. Проверка

DNS расходится от минуты до пары часов, у Cloudflare обычно 1–5 минут.

**[VPS]**
```bash
dig +short A example.com @1.1.1.1          # → 203.0.113.10
dig +short A www.example.com @1.1.1.1      # → 203.0.113.10
dig +short A mail.example.com @1.1.1.1     # → 203.0.113.10
dig +short TXT example.com @1.1.1.1        # → "v=spf1 ip4:203.0.113.10 -all"
dig +short TXT _dmarc.example.com @1.1.1.1 # → "v=DMARC1; p=quarantine; ..."
```
Если в ответе IP Cloudflare (`104.x.x.x`, `172.67.x.x`), значит облако оранжевое: выключите прокси.

### 3.3. PTR (обратная запись)

PTR говорит, какое имя у IP `203.0.113.10`. Gmail, Outlook и другие почтовики часто отбивают письма от IP без PTR с ошибкой `550 5.7.25`. PTR ставит **не регистратор домена, а хостинг VPS**: в панели (раздел «Reverse DNS», «rDNS», «PTR») или через поддержку.

Текст для поддержки: *«Прошу поставить PTR (reverse DNS) для 203.0.113.10 → mail.example.com»*.

**Проверка:** `dig +short -x 203.0.113.10` → `mail.example.com.`

---

## 4. Базы данных

Сайт хранит данные в MySQL 8 или MariaDB 10.4 и новее. Есть два варианта:
- **Вариант А:** базы у хостинга. Хостинг сам их бэкапит и следит за ними.
- **Вариант Б:** своя MariaDB в контейнере на этом же VPS. Ничего покупать не нужно, но бэкапы базы делаете вы сами (см. [OPERATIONS.md](OPERATIONS.md)). Займёт около 200–400 МБ памяти.

### Одна база или три?

Сайт рассчитан на **три базы**: аккаунты игроков делятся между ними по нику (`crc32(ник) % 3 + 1`). Это нужно, если хостинг ограничивает размер одной базы. DB1 вдобавок хранит таблицы сайта (новости, правила, магазин и т.д.).

**Одна база тоже работает.** Впишите её во все три набора `DB1_*`, `DB2_*`, `DB3_*` одинаково. Миграции увидят, что таблицы уже созданы, и пропустят повтор. Единственная особенность: в админке на странице «Игроки» каждый ник будет показан трижды (как DB1, DB2 и DB3), а размер базы на дашборде посчитается три раза. Это одна и та же запись, ничего не ломается.

### 4.1. Вариант А: базы у хостинга

1. Создайте у хостинга от одной до трёх баз. Если есть выбор кодировки, берите `utf8mb4` и сравнение `utf8mb4_unicode_ci`.
2. Для каждой базы запишите: **хост**, **порт** (обычно `3306`), **имя базы**, **пользователя**, **пароль**.
3. **Разрешите подключение с IP VPS.** У многих хостингов внешний доступ к базе закрыт по умолчанию, ищите «Remote MySQL», «Внешние подключения», «Доступ по IP». Добавьте `203.0.113.10`.
4. Пользователю нужны права создавать таблицы и индексы в своей базе (обычно «все права на базу»).

**Проверка [VPS]** (для каждой базы; замените хост, пользователя и базу):
```bash
timeout 5 bash -c '</dev/tcp/db.hosting.net/3306' && echo "порт базы доступен" || echo "порт базы ЗАКРЫТ"
docker run --rm -it mysql:8.4 mysql -h db.hosting.net -P 3306 -u USER -p DBNAME -e 'SELECT VERSION();'
```
Вторая команда спросит пароль и должна напечатать версию (`8.0.x`, `10.6.x-MariaDB` и т.п.).
- `Access denied` — неверный пароль, или хостинг не пускает с этого IP.
- `Can't connect` — порт закрыт, вернитесь к пункту 3.

### 4.2. Вариант Б: своя MariaDB на VPS

Ничего создавать вручную не нужно: база, пользователь и пароль создадутся при первом запуске контейнера. Делать в шаге 6, когда будет готов `.env`. Пока просто запомните, что выбрали этот вариант.

---

## 5. Код на VPS

**[VPS]**
```bash
sudo mkdir -p /opt/sitegate
sudo chown $USER:$USER /opt/sitegate
git clone https://github.com/NightCastle-minecraft/NightCastle.git /opt/sitegate
cd /opt/sitegate/infra
```
**Проверка:**
```bash
ls /opt/sitegate            # INSTALL.md  README.md  infra  plugins  site  ...
ls /opt/sitegate/infra      # bin  compose.yml  panel  proxy  site  smp
```
Дальше почти все команды выполняются из папки `/opt/sitegate/infra`. Если вы перезашли по SSH, сначала `cd /opt/sitegate/infra`.

---

## 6. Файл .env

`.env` — главный файл настроек стека: домен, название, память, доступы к базам. Он лежит только на VPS и в git не попадает.

### 6.1. Создание

**[VPS]**
```bash
cd /opt/sitegate/infra
cp .env.example .env
chmod 600 .env
nano .env
```

### 6.2. Что вписать

| Переменная | Что это | Пример |
|---|---|---|
| `DOMAIN` | Ваш домен **без** `https://` и без `/` в конце | `example.com` |
| `SITE_NAME` | Название сервера: имя отправителя и текст писем | `MyServer` |
| `GAME_ADDRESS` | Адрес для игроков в письмах. Пусто = `DOMAIN` | пусто или `play.example.com` |
| `SMP_MEMORY` | Память (heap) Java для игрового сервера | `5G` (таблица в шаге 1.1) |
| `DB1_HOST` | Хост первой базы | `db.hosting.net` |
| `DB1_PORT` | Порт. Если строки нет, берётся `3306` | `3306` |
| `DB1_NAME` | Имя базы | `sitegate1` |
| `DB1_USER` | Пользователь | `sitegate1` |
| `DB1_PASSWORD` | Пароль | `'Kx7#p...'` |
| `DB2_*`, `DB3_*` | То же для второй и третьей базы. Одна база — те же значения, что в `DB1_*` | |

**Пароли пишите в одинарных кавычках:** `DB1_PASSWORD='па$$word#1'`. Без кавычек символы `$` и ` #` ломают значение. Если в самом пароле есть одинарная кавычка `'`, смените пароль на хостинге: так проще всего.

Пример заполненного `.env` (вариант А, три базы):
```ini
DOMAIN=example.com
SITE_NAME=MyServer
GAME_ADDRESS=
SMP_MEMORY=5G

DB1_HOST=db1.hosting.net
DB1_NAME=sitegate1
DB1_USER=sitegate1
DB1_PASSWORD='пароль-1'
DB2_HOST=db2.hosting.net
DB2_NAME=sitegate2
DB2_USER=sitegate2
DB2_PASSWORD='пароль-2'
DB3_HOST=db3.hosting.net
DB3_NAME=sitegate3
DB3_USER=sitegate3
DB3_PASSWORD='пароль-3'
```
Сохраните: `Ctrl+O`, `Enter`, `Ctrl+X`.

### 6.3. Только для варианта Б: своя MariaDB

**[VPS]** Сгенерируйте пароль и впишите одинаковые значения во все три набора:
```bash
cd /opt/sitegate/infra
PASS=$(openssl rand -hex 24)
sed -i '/^DB[123]_/d' .env
for n in 1 2 3; do
  printf "DB%s_HOST=db\nDB%s_NAME=sitegate\nDB%s_USER=sitegate\nDB%s_PASSWORD='%s'\n" $n $n $n $n "$PASS" >> .env
done
grep '^DB' .env | sed 's/PASSWORD=.*/PASSWORD=<скрыт>/'
```
Создайте файл `compose.override.yml`: Docker Compose сам добавит его к `compose.yml`.
```bash
cat > compose.override.yml <<'EOF'
# Локальные дополнения к compose.yml. В git не попадает.
services:
  # Своя база MariaDB для сайта (INSTALL.md, вариант Б)
  db:
    image: mariadb:10.11
    container_name: sg-db
    restart: unless-stopped
    environment:
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
      MARIADB_DATABASE: ${DB1_NAME}
      MARIADB_USER: ${DB1_USER}
      MARIADB_PASSWORD: ${DB1_PASSWORD}
    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci"]
    volumes:
      - sg-db-data:/var/lib/mysql
    mem_limit: 512m
    networks: [sg]
  web:
    depends_on: [db]

volumes:
  sg-db-data:
    name: sg-db-data
EOF
```
> База живёт в docker-томе `sg-db-data` и наружу не открыта: доступ к ней есть только у контейнеров стека.

### 6.4. Память игрового сервера и часовой пояс

Если у вас **не** 8 ГБ памяти или вы не в часовом поясе Киева, поменяйте лимиты. Правьте не `compose.yml` (его перезапишет `git pull`), а `compose.override.yml`.

`SMP_MEMORY` в `.env` — это память Java. `mem_limit` — жёсткий потолок контейнера, он должен быть примерно на 1,2–1,5 ГБ больше: Java тратит память не только на heap. Значения берите из таблицы в шаге 1.1.

**Если `compose.override.yml` ещё нет** (вариант А):
```bash
cd /opt/sitegate/infra
cat > compose.override.yml <<'EOF'
# Локальные дополнения к compose.yml. В git не попадает.
services:
  smp:
    mem_limit: 3200m
    environment:
      TZ: Europe/Berlin
EOF
```
**Если он уже есть** (вариант Б): откройте `nano compose.override.yml` и допишите блок `smp:` **внутрь** существующего `services:`, на одном уровне с `db:` и `web:`:
```yaml
services:
  db:
    ...
  web:
    depends_on: [db]
  smp:
    mem_limit: 3200m
    environment:
      TZ: Europe/Berlin
```
Отступы в YAML — это пробелы, не табы. У `smp:` их 2, у `mem_limit:` 4, у `TZ:` 6.

### 6.5. Проверка

Полная проверка будет после шага 7: Compose требует, чтобы файлы секретов уже существовали.

---

## 7. Секреты

Секреты — это случайные пароли и токены, по которым части системы доверяют друг другу. Их создаёт скрипт.

**[VPS]**
```bash
cd /opt/sitegate/infra
sh bin/init-secrets.sh
ls -l secrets/
```
Появятся четыре файла:

| Файл | Кто им пользуется |
|---|---|
| `rcon_pass` | Пароль RCON, удалённой консоли SMP. Им пользуются панель и `rcon-cli` |
| `auth_api_token` | Токен между сайтом и SiteGateAuth |
| `shop_api_token` | Токен между сайтом и SiteGateShop |
| `forwarding_secret` | Секрет, которым Velocity подписывает данные игрока для SMP |

- Повторный запуск скрипта ничего не перезаписывает.
- Папка `secrets/` и файл `.env` в git не попадают. **Никому их не показывайте:** с токеном можно впустить на сервер любой ник.

**Проверка [VPS]:**
```bash
docker compose config -q && echo "compose OK"
```
Должно быть `compose OK`. Если пишет `required variable DB1_HOST is missing a value`, значит в `.env` не заполнена переменная (шаг 6.2). Если пишет про `secrets/...: no such file`, не запущен `init-secrets.sh`.

---

## 8. Игровой сервер SMP

### 8.1. server.jar

На сайте **Leaf** (leafmc.one, раздел загрузок) или **Paper** (papermc.io → Downloads → Paper) выберите версию **26.3** и скопируйте ссылку на скачивание jar (правая кнопка на кнопке Download → «Копировать адрес ссылки»).

**[VPS]**
```bash
cd /opt/sitegate/infra
mkdir -p servers/SMP
wget -O servers/SMP/server.jar 'ВСТАВЬТЕ_ССЫЛКУ'
ls -lh servers/SMP/server.jar
```
Файл должен весить больше 10 МБ. Если он маленький (килобайты), вы скачали страницу, а не jar: возьмите прямую ссылку.

Есть `server.jar` у себя на компьютере? Залейте его так:
**[ПК]**
```bash
scp server.jar ubuntu@203.0.113.10:/opt/sitegate/infra/servers/SMP/server.jar
```
На Windows то же самое в PowerShell или через WinSCP.

### 8.2. Если ваш uid не 1000

Пропустите, если в шаге 2.2 `id` показал `uid=1000`.

Иначе контейнеры не смогут писать в ваши файлы, а вы — править их. Узнайте свои номера:
```bash
id -u; id -g
```
Допишите в `compose.override.yml` (создайте, если его нет), подставив свои числа вместо `1001`:
```yaml
services:
  smp:
    environment:
      UID: "1001"
      GID: "1001"
  proxy:
    user: "1001:1001"
```
Если в файле уже есть `smp:` (шаг 6.4), строки `UID` и `GID` добавьте в его `environment:`, а не заводите второй `smp:`.

### 8.3. Первый запуск

При первом запуске сервер создаст мир, конфиги и папку `libraries` (она понадобится для сборки плагина в шаге 9).

**[VPS]**
```bash
cd /opt/sitegate/infra
docker compose up -d smp
docker compose logs -f smp
```
Ждите строку вида:
```
[Server thread/INFO]: Done (25.123s)! For help, type "help"
```
Первый запуск занимает 1–5 минут: сервер скачивает файлы Mojang и генерирует мир. Leaf при самом первом старте может создать конфиг и перезапуститься, это нормально. После `Done` нажмите `Ctrl+C`: это закрывает просмотр логов, сервер продолжает работать. Остановите его:
```bash
docker compose stop smp
```
**Проверка:**
```bash
ls servers/SMP
```
Должны быть `server.properties`, `config/`, `libraries/`, `world/`, `plugins/`.

> Если файлы в `servers/SMP` принадлежат `root` и не редактируются: `sudo chown -R $USER:$USER servers/`.

### 8.4. server.properties

Одна команда выставит всё нужное, пароль RCON подставится из секрета.

**[VPS]**
```bash
cd /opt/sitegate/infra
P=servers/SMP/server.properties
RCON=$(cat secrets/rcon_pass)
sed -i \
  -e 's/^online-mode=.*/online-mode=false/' \
  -e 's/^white-list=.*/white-list=false/' \
  -e 's/^enforce-whitelist=.*/enforce-whitelist=false/' \
  -e 's/^enable-rcon=.*/enable-rcon=true/' \
  -e 's/^rcon.port=.*/rcon.port=25700/' \
  -e "s/^rcon.password=.*/rcon.password=$RCON/" \
  -e 's/^server-port=.*/server-port=25565/' \
  "$P"
grep -E '^(online-mode|white-list|enable-rcon|rcon.port|server-port)=' "$P"
```
Должно вывести:
```
enable-rcon=true
online-mode=false
rcon.port=25700
server-port=25565
white-list=false
```
Что это значит:
- `online-mode=false`: сервер не проверяет лицензию у Mojang. Ник проверяет SiteGateAuth на прокси, а снаружи SMP недоступен.
- `white-list=false`: иначе сервер не пустит никого, кого нет в вайтлисте.
- `enable-rcon`, `rcon.port`, `rcon.password`: удалённая консоль для панели и `rcon-cli`. Порт 25700 открыт только внутри docker-сети.
- `server-port=25565`: порт внутри docker-сети, на него ходит прокси. Снаружи этот порт занимает прокси.

Остальное (MOTD, слоты, сложность, PvP, дальность прорисовки) удобно менять потом в панели (шаг 15).

### 8.5. Передача данных игрока от Velocity

SMP должен принимать игроков только от вашего Velocity и получать от него ник, UUID и IP. Для этого включается modern forwarding с общим секретом.

**[VPS]**
```bash
cd /opt/sitegate/infra
S=$(cat secrets/forwarding_secret)
F=servers/SMP/config/paper-global.yml
sed -i "/^  velocity:/,/^    secret:/{s/^    enabled: .*/    enabled: true/;s/^    online-mode: .*/    online-mode: false/;s/^    secret: .*/    secret: '$S'/}" "$F"
grep -A3 '^  velocity:' "$F"
```
Должно вывести (вместо `abc123...` ваш секрет):
```
  velocity:
    enabled: true
    online-mode: false
    secret: 'abc123...'
```
**Если вывод другой**, отредактируйте руками: `nano servers/SMP/config/paper-global.yml`, `Ctrl+W` → `velocity` → `Enter`, и приведите блок к виду выше. Секрет посмотреть: `cat secrets/forwarding_secret`. Он должен совпадать символ в символ, в одинарных кавычках.

SMP пока не запускайте.

---

## 9. Сборка плагинов

Плагины поставляются исходниками, их нужно собрать. На выходе получатся:
- `SiteGateAuth-1.0.0.jar`: плагин для Velocity;
- `SiteGateShop-1.0.0.jar`: плагин для SMP;
- `velocity-4.3.1-45.jar`: сам Velocity. Сборка скачивает его и сверяет контрольную сумму.

Есть два способа. **Вариант А** проще: всё на VPS, ничего ставить не нужно.

### 9.1. Вариант А: на VPS в Docker (рекомендуется)

**[VPS]**
```bash
cd /opt/sitegate
docker run --rm -u "$(id -u):$(id -g)" \
  -e HOME=/tmp -e GRADLE_USER_HOME=/work/.gradle-home \
  -v /opt/sitegate:/work -w /work/plugins \
  eclipse-temurin:25-jdk \
  ./gradlew build --no-daemon -PserverLibs=/work/infra/servers/SMP/libraries
```
- Первая сборка идёт 3–8 минут: скачиваются JDK-образ, Gradle и зависимости. Повторные быстрее.
- Сборка заодно гоняет тесты. В конце должно быть `BUILD SUCCESSFUL`.

### 9.2. Вариант Б: на своём компьютере

Нужен **JDK 25** (например, Temurin 25 с adoptium.net) и сам репозиторий.

**[ПК]**
```bash
git clone https://github.com/NightCastle-minecraft/NightCastle.git && cd NightCastle/plugins
# библиотеки сервера с VPS (появились в шаге 8.3):
scp -r ubuntu@203.0.113.10:/opt/sitegate/infra/servers/SMP/libraries ./server-libs
./gradlew build -PserverLibs="$PWD/server-libs"       # Windows: gradlew.bat build -PserverLibs=...
```
Если `java -version` показывает не 25, укажите путь: `export JAVA_HOME=/путь/к/jdk-25`.

Затем залейте результат на VPS:
```bash
ssh ubuntu@203.0.113.10 'mkdir -p /opt/sitegate/plugins/auth/build/libs /opt/sitegate/plugins/shop/build/libs /opt/sitegate/plugins/libs'
scp auth/build/libs/SiteGateAuth-1.0.0.jar ubuntu@203.0.113.10:/opt/sitegate/plugins/auth/build/libs/
scp shop/build/libs/SiteGateShop-1.0.0.jar ubuntu@203.0.113.10:/opt/sitegate/plugins/shop/build/libs/
scp libs/velocity-4.3.1-45.jar ubuntu@203.0.113.10:/opt/sitegate/plugins/libs/
```

### 9.3. Проверка

**[VPS]**
```bash
ls -l /opt/sitegate/plugins/auth/build/libs/SiteGateAuth-1.0.0.jar \
      /opt/sitegate/plugins/shop/build/libs/SiteGateShop-1.0.0.jar \
      /opt/sitegate/plugins/libs/velocity-4.3.1-45.jar
sha256sum /opt/sitegate/plugins/libs/velocity-4.3.1-45.jar
```
Все три файла должны существовать. sha256 у Velocity: `0844bcabdcdb4f5b875906fd4c3365bc1bb76918e42bc595ed88ad195258dd75`.

---

## 10. SiteGateShop на SMP

**[VPS]**
```bash
cd /opt/sitegate/infra
D=$(grep '^DOMAIN=' .env | cut -d= -f2)
mkdir -p servers/SMP/plugins/SiteGateShop
cp /opt/sitegate/plugins/shop/build/libs/SiteGateShop-1.0.0.jar servers/SMP/plugins/
cp smp/sitegateshop.yml servers/SMP/plugins/SiteGateShop/config.yml
sed -i "s#^  url: .*#  url: \"https://$D\"#" servers/SMP/plugins/SiteGateShop/config.yml
grep -E '^  (url|token-file):' servers/SMP/plugins/SiteGateShop/config.yml
```
Должно быть:
```
  url: "https://example.com"
  token-file: "/run/secrets/shop_api_token"
```
Токен вручную вписывать не надо: Compose сам кладёт файл секрета в `/run/secrets/shop_api_token` внутри контейнера SMP.

---

## 11. Velocity и SiteGateAuth

**[VPS]**
```bash
cd /opt/sitegate/infra
D=$(grep '^DOMAIN=' .env | cut -d= -f2)
mkdir -p servers/proxy/plugins/sitegateauth
cp /opt/sitegate/plugins/libs/velocity-4.3.1-45.jar servers/proxy/velocity.jar
cp /opt/sitegate/plugins/auth/build/libs/SiteGateAuth-1.0.0.jar servers/proxy/plugins/
cp proxy/velocity.toml servers/proxy/velocity.toml
cp proxy/sitegateauth.toml servers/proxy/plugins/sitegateauth/config.toml
sed -i "s#^url = .*#url = \"https://$D\"#" servers/proxy/plugins/sitegateauth/config.toml
grep -E '^(url|token-file) =' servers/proxy/plugins/sitegateauth/config.toml
```
Должно быть:
```
url = "https://example.com"
token-file = "/run/secrets/auth_api_token"
```
Название сервера в списке серверов игрока (MOTD) и заголовок окон входа:
```bash
nano servers/proxy/velocity.toml                        # строка motd = "...": замените MyServer
nano servers/proxy/plugins/sitegateauth/config.toml     # [messages] title = "...": замените MyServer
```
Формат текста — MiniMessage: `<b>` жирный, `<gray>` серый, `<#9b8cff>` цвет. Документация: https://docs.advntr.dev/minimessage/format.html

Что уже настроено в шаблоне `velocity.toml`:
- `online-mode = false`: лицензию не проверяем, вход проверяет SiteGateAuth;
- `player-info-forwarding-mode = "MODERN"`, секрет из `/run/secrets/forwarding_secret`: тот же, что вписан в SMP в шаге 8.5;
- `[servers] smp = "smp:25565"` и `try = ["smp"]`: куда отправлять игрока после входа.

> **Velocity обновлять только вместе с SiteGateAuth.** Плагин опирается на внутренние классы именно версии 4.3.1 (build 45). На другой версии он пишет об этом в лог и закрывает вход для всех.

Если у вас `uid` не 1000 и вы сделали шаг 8.2, ничего дополнительно делать не нужно. Иначе убедитесь, что файлы прокси ваши: `ls -ln servers/proxy` (в третьей и четвёртой колонке `1000`).

---

## 12. Сайт: сборка, миграции, запуск

### 12.1. Сборка образов

**[VPS]**
```bash
cd /opt/sitegate/infra
docker compose build
```
Собираются два образа:
- сайт (PHP 8.3 + Apache), 1–3 минуты;
- Caddy с плагином ограничения запросов, 2–6 минут: он компилируется из исходников.

Сборке Caddy нужно около 1 ГБ свободной памяти. На маленьком VPS делайте это, пока SMP остановлен (он сейчас и так остановлен). В конце не должно быть строк с `ERROR`.

### 12.2. Только для варианта Б: запустить базу

**[VPS]**
```bash
docker compose up -d db
sleep 20
docker compose logs db | grep -i "ready for connections" | tail -1
```
Должна найтись строка `ready for connections`. Если пусто, подождите ещё 20 секунд и повторите последнюю команду.

### 12.3. Миграции (таблицы в базах)

**[VPS]**
```bash
docker compose run --rm --no-deps -T web php bin/migrate.php
```
Ожидаемый вывод при первом запуске:
```
apply DB1 site/001_init.sql
apply DB1 site/002_content.sql
apply DB1 site/003_shop.sql
apply DB1 site/004_mail.sql
apply DB1 shard/001_accounts.sql
apply DB1 shard/002_email.sql
apply DB2 shard/001_accounts.sql
apply DB2 shard/002_email.sql
apply DB3 shard/001_accounts.sql
apply DB3 shard/002_email.sql
Done, applied: 10
```
Повторный запуск безопасен: уже применённые миграции он пропускает (`skip`).
- `FAIL DB1: SQLSTATE[HY000] [2002]` — до базы не достучаться (хост, порт, доступ по IP).
- `[1045] Access denied` — неверный пользователь или пароль.

Подробнее в [TROUBLESHOOTING.md](TROUBLESHOOTING.md#базы-и-миграции).

### 12.4. Запуск всего стека

**[VPS]**
```bash
docker compose up -d
docker compose ps
```
Все контейнеры должны быть в состоянии `Up` (у некоторых через минуту появится `(healthy)`): `sg-site`, `sg-web`, `sg-mail`, `sg-proxy`, `sg-smp` и `sg-db` в варианте Б.

Если какой-то контейнер в состоянии `Restarting` или `Exited`, посмотрите его лог: `docker compose logs --tail 50 ИМЯ` (`site`, `web`, `mail`, `proxy`, `smp`).

### 12.5. HTTPS-сертификат

Caddy сам получает сертификат Let's Encrypt для `example.com` и `www.example.com`.

**[VPS]**
```bash
docker compose logs site | grep -iE "certificate obtained|error" | tail -5
```
Через 10–60 секунд после запуска должна появиться строка `certificate obtained successfully` (для обоих имён). Если видите `error` с `NXDOMAIN`, `timeout` или `unauthorized`, значит DNS ещё не указывает на VPS или закрыт порт 80. Проверьте шаги 2.8 и 3.2, затем `docker compose restart site`.

**Проверка:**
```bash
curl -sI https://example.com/ | head -1           # HTTP/2 200
curl -sI http://www.example.com/ | grep -i location   # location: https://www.example.com/ (потом → example.com)
curl -s https://example.com/api/status; echo      # {"online":true,"players":0,...}
```
`/api/status` покажет `"online":true`, когда SiteGateAuth отправит первый «пульс»: через 5–30 секунд после старта прокси. Если стабильно `"online":false`, смотрите `docker compose logs proxy`.

Откройте `https://example.com` в браузере: должна быть главная страница сайта с замком в адресной строке.

### 12.6. Администратор сайта

**[VPS]**
```bash
docker exec -it sg-web php bin/create-admin.php admin
```
Вместо `admin` можно любой логин. Команда дважды спросит пароль, **не короче 12 символов**. При вводе он не отображается.

**Проверка:** откройте `https://example.com/admin` и войдите.

Забыли пароль админа: `docker exec -it sg-web php bin/create-admin.php admin --reset`.

---

## 13. Настройка сайта в админке

Админка: `https://example.com/admin`.

### 13.1. Настройки (раздел «Настройки»)

| Поле | Что это | Что вписать |
|---|---|---|
| Название сервера | В шапке, заголовках страниц, админке | `MyServer` |
| Слоган | Под названием на главной | Коротко о сервере |
| IP для показа | Адрес сервера для игроков: в шапке (кнопка «скопировать»), на странице входа и после регистрации | `example.com` |
| Версия игры | На главной | `Java 26.3` |
| Ссылка на сборку | Кнопка скачивания сборки. Для ванильного клиента оставьте пустым | пусто |
| Discord | Кнопка Discord на сайте | `https://discord.gg/ваш-инвайт` |
| Фон hero | Картинка на первом экране главной: JPG, PNG или WebP до 5 МБ | По желанию |
| Текст баннера + «Показывать баннер» | Полоса-объявление над шапкой на всех страницах | По желанию |

> «IP для показа» из админки показывается на сайте, а `GAME_ADDRESS` из `.env` — в письмах. Если адрес отличается от домена, укажите одинаковый в обоих местах.

### 13.2. Тексты

- **Страницы** («Страницы»): «О сервере», «Как начать играть», приветственный блок. После установки в них стоят тексты-примеры с `MyServer` и `example.com`: **замените на свои**. Формат — Markdown: `**жирный**`, `## Заголовок`, `- список`, `[ссылка](https://...)`.
- **Правила** («Правила»): разделы и пункты, порядок меняется стрелками.
- **Новости** («Новости»): первая новость-пример создаётся при установке, удалите или отредактируйте её.

### 13.3. Логотип и иконки

Файлы лежат в репозитории, после замены сайт нужно пересобрать.

| Файл | Где виден | Размер |
|---|---|---|
| `site/public/assets/img/logo.svg` | Логотип в шапке сайта | SVG |
| `site/public/favicon.ico` | Иконка во вкладке браузера | 16, 32, 48 px в одном .ico |
| `site/public/assets/img/favicon-32.png` | Иконка во вкладке (PNG) | 32×32 |
| `site/public/apple-touch-icon.png` | Иконка на iPhone при «На экран Домой» | 180×180 |
| `site/public/assets/img/icon-192.png` | Иконка на Android | 192×192 |
| `site/public/assets/img/mail-logo.png` | Логотип в шапке писем | 256×256 |

**[VPS]** После замены:
```bash
cd /opt/sitegate/infra
docker compose build web && docker compose up -d web
```
Браузер может держать старую иконку в кеше: проверяйте в режиме инкогнито.

> Иконку сервера в списке серверов Minecraft задаёт `servers/SMP/server-icon.png` (64×64 PNG), но Velocity её не передаёт (`ping-passthrough` выключен). Чтобы показывать её, в `servers/proxy/velocity.toml` в разделе `[ping-passthrough]` поставьте `favicon = true` и перезапустите прокси.

---

## 14. Почта

Сайт шлёт три письма:
- подтверждение почты при регистрации, ссылка живёт 24 часа;
- приветствие после подтверждения;
- сброс пароля, ссылка живёт 1 час.

Чтобы они доходили и не падали в спам, нужны SPF и DMARC (шаг 3), PTR (шаг 3.3) и DKIM.

### 14.1. Запись DKIM

Почтовый контейнер при первом запуске сам сгенерировал ключ DKIM.

**[VPS]**
```bash
docker exec sg-mail sh -c 'cat /var/lib/rspamd/dkim/*.sg1.txt'
```
Будет примерно так:
```
sg1._domainkey IN TXT ( "v=DKIM1; k=rsa; "
	"p=MIIBIjANBgkqh...первая часть..."
	"...вторая часть...IDAQAB" ) ;
```
Создайте в DNS запись:

| Тип | Имя | Значение |
|---|---|---|
| `TXT` | `sg1._domainkey` | `v=DKIM1; k=rsa; p=MIIBIjANBgkqh...IDAQAB` |

Значение — это **все куски в кавычках, склеенные в одну строку без кавычек и пробелов между кусками**. Вот команда, которая склеит их сама:
```bash
docker exec sg-mail sh -c 'cat /var/lib/rspamd/dkim/*.sg1.txt' | tr -d '\n\t' | sed 's/.*( *//; s/ *).*//; s/" *"//g; s/"//g'; echo
```
Скопируйте её вывод целиком в поле «Значение».

**Проверка** (через пару минут):
```bash
dig +short TXT sg1._domainkey.example.com @1.1.1.1
```
Должен вернуться ваш ключ (DNS может разбить его на куски в кавычках, это нормально).

> Ключ хранится в docker-томе `sg-mail-dkim`. Удалите том — получите новый ключ, и запись в DNS придётся обновить. Не удаляйте его без нужды и включите в бэкап ([OPERATIONS.md](OPERATIONS.md)).

### 14.2. Тестовое письмо

**[VPS]** Замените `you@gmail.com` на свою почту:
```bash
docker exec sg-web php -r 'require "vendor/autoload.php"; [$s,$t,$h] = SiteGate\MailTemplates::welcome("Test", SiteGate\Env::siteUrl()); SiteGate\Mailer::fromEnv()->send("you@gmail.com", $s, $t, $h); echo "ok\n";'
sleep 5
docker logs sg-mail 2>&1 | grep "status=" | tail -3
```
- `status=sent (250 ...)` — письмо принято сервером получателя. Проверьте входящие и папку «Спам».
- `status=deferred` / `status=bounced` — смотрите текст после статуса и [TROUBLESHOOTING.md](TROUBLESHOOTING.md#почта).

### 14.3. Проверка подписи

В Gmail откройте письмо → «⋮» → **«Показать оригинал»**. Должно быть:
```
SPF:   PASS
DKIM:  PASS
DMARC: PASS
```
Оценку от 0 до 10 и подсказки даёт сервис mail-tester.com: отправьте тестовое письмо на адрес, который он выдаст (команда из 14.2), и откройте результат. Хорошо — 9–10.

---

## 15. Панель управления

Панель — это одна программа на Python, она работает прямо на VPS как служба systemd, не в Docker. Слушает только `127.0.0.1:8780`: из интернета её не видно, открывается через SSH-туннель.

Что умеет:
- статус и память контейнеров, запуск, перезапуск, остановка;
- консоль SMP, объявления в чат;
- игроки: кик, бан, op, вайтлист, аккаунт на сайте, новый пароль, монеты;
- бэкапы мира вручную и по расписанию;
- правка `server.properties` и конфигов, история изменений;
- плагины SMP: загрузить, выключить, удалить.

### 15.1. Установка службы

**[VPS]**
```bash
cd /opt/sitegate/infra
python3 --version                        # нужен 3.10 или новее
D=$(grep '^DOMAIN=' .env | cut -d= -f2)
sudo cp panel/sitegate-panel.service /etc/systemd/system/sitegate-panel.service
sudo sed -i "s/^User=.*/User=$(id -un)/; s/^Group=.*/Group=$(id -gn)/" /etc/systemd/system/sitegate-panel.service
sudo sed -i "/^Environment=PYTHONUNBUFFERED=1/a Environment=SG_SITE_URL=https://$D" /etc/systemd/system/sitegate-panel.service
grep -E '^(User|Group|Environment)=' /etc/systemd/system/sitegate-panel.service
```
Должно быть:
```
User=ubuntu
Group=ubuntu
Environment=PYTHONUNBUFFERED=1
Environment=SG_SITE_URL=https://example.com
```
Пользователь должен состоять в группе `docker` (шаг 2.6): панель управляет контейнерами через команду `docker`.

### 15.2. Пароль панели

**[VPS]** От своего пользователя, **без `sudo`**: иначе файл с паролем станет принадлежать root, и панель не сможет его прочитать.
```bash
cd /opt/sitegate/infra
read -rs -p "Пароль панели (от 10 символов): " PW; echo; echo "$PW" | python3 panel/panel.py set-password; unset PW
```
Должно напечатать `пароль панели задан`.

> Если запустить панель без пароля, она сама создаст случайный и положит его в `infra/panel/data/INITIAL_PASSWORD.txt`.

### 15.3. Запуск

**[VPS]**
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now sitegate-panel
systemctl status sitegate-panel --no-pager | head -5
sudo ss -ltnp | grep 8780
```
- В статусе должно быть `active (running)`.
- В последней строке должно быть `127.0.0.1:8780`, а **не** `0.0.0.0:8780`.

### 15.4. Открыть панель

**[ПК]** Откройте туннель и **не закрывайте это окно**, пока работаете в панели:
```bash
ssh -N -L 8780:127.0.0.1:8780 ubuntu@203.0.113.10
```
Команда ничего не выводит и «висит»: так и должно быть. Откройте в браузере **http://localhost:8780** и войдите паролем из шага 15.2. Закрыть туннель: `Ctrl+C`.

- **Windows:** та же команда в PowerShell.
- **PuTTY:** Connection → SSH → Tunnels: Source port `8780`, Destination `127.0.0.1:8780`, нажмите «Add», затем подключитесь как обычно.

Чтобы не набирать каждый раз, добавьте в `~/.ssh/config` на ПК:
```
Host sg-panel
    HostName 203.0.113.10
    User ubuntu
    LocalForward 8780 127.0.0.1:8780
    ExitOnForwardFailure yes
    ServerAliveInterval 30
```
и запускайте `ssh -N sg-panel`.

> Панель блокирует вход на 15 минут после 10 неверных паролей подряд. Сессия живёт 12 часов.

### 15.5. Первые настройки в панели

Раздел «Бэкапы»: по умолчанию автобэкап **каждые 12 часов**, хранятся **14 последних**. Бэкапы лежат в `/opt/sitegate/infra/backups`. Нажмите «Сделать бэкап», чтобы убедиться, что всё работает.

---

## 16. Первый вход в игру

### 16.1. Вход

1. Клиент Minecraft **26.3** → «Сетевая игра» → «Добавить сервер» → адрес `example.com`.
2. Сервер в списке должен показать ваш MOTD (шаг 11). Подключайтесь.
3. Откроется окно **«Проверка, что ты не бот»** с примером на сложение и кнопками-ответами. Нажмите правильный.
4. Откроется окно с кнопкой **«Нажмите, чтобы авторизоваться»**. Нажмите её: игра спросит, открыть ли ссылку, — «Да». Откроется браузер на `https://example.com/auth/...`.
5. На сайте:
   - аккаунта нет → регистрация: почта и пароль (от 8 символов);
   - аккаунт есть → пароль;
   - затем **«Подтвердить вход»**.
6. Через 1–3 секунды игра сама пустит вас на сервер.

Следующие **7 дней с того же IP** игра пускает сразу, без капчи и сайта.

Зарегистрироваться можно и заранее, на `https://example.com/register`. Тогда при входе в игру на сайте нужно только ввести пароль.

### 16.2. Права оператора себе

**[VPS]**
```bash
docker exec -i sg-smp rcon-cli op Steve
```
Или в панели: «Игроки» → введите ник → «Выдать op».

### 16.3. Что смотреть, если не пускает

```bash
cd /opt/sitegate/infra
docker compose logs --tail 50 proxy    # SiteGateAuth: капча, тикеты, связь с сайтом
docker compose logs --tail 50 web      # сайт
docker compose logs --tail 50 smp      # игровой сервер
```
Частые причины с решениями — в [TROUBLESHOOTING.md](TROUBLESHOOTING.md#вход-в-игру).

---

## 17. Магазин

Магазин работает на внутренних монетах:
- монеты игрокам начисляет админ;
- игрок покупает товар на сайте;
- SiteGateShop выдаёт покупку на сервере командами.

**Приёма реальных платежей нет**, его придётся подключать самим.

### 17.1. Описать выдачу товара на сервере

Каждый товар — это файл `servers/SMP/plugins/SiteGateShop/products/<код>.yml`. **Код = имя файла**, только маленькие латинские буквы, цифры и `_`, от 2 до 64 символов. Пример `example_diamonds.yml` создаётся сам при первом запуске.

**[VPS]**
```bash
cd /opt/sitegate/infra
cat > servers/SMP/plugins/SiteGateShop/products/vip_30d.yml <<'EOF'
# VIP на 30 дней через LuckPerms
require-online: false
commands:
  - "lp user {player} parent addtemp vip 30d"
message: "<green>VIP на 30 дней активирован!</green>"
EOF
```
| Поле | Что это |
|---|---|
| `require-online` | `true` — выдать, только когда игрок в игре (предметы в инвентарь). `false` — выдать сразу, даже если игрок не в игре (права, валюта) |
| `commands` | Команды от имени консоли, по порядку. Подстановки: `{player}` ник, `{uuid}` UUID, `{order}` номер заказа, `{title}` название товара |
| `message` | Сообщение игроку после выдачи, MiniMessage. Пусто = стандартное |

### 17.2. Перечитать товары

**[VPS]**
```bash
docker exec -i sg-smp rcon-cli shop reload
```
Ответ: `Товары перечитаны: N шт.`. Плагин сразу отправит сайту список кодов.

### 17.3. Завести товар на сайте

Админка → **«Магазин» → «Новый товар»**: **тот же код**, что имя файла, название, цена в монетах, описание. Купить на сайте можно только товар, код которого прислал плагин: иначе заказ некому было бы выдать.

### 17.4. Начислить монеты и проверить

1. Админка → «Магазин» → «Кошельки и пополнение» → ник → «Показать» → сумма → «Изменить баланс». Или панель → «Игроки» → «Изменить баланс».
2. Зайдите на сайт под игроком → «Магазин» → «Купить».
3. В течение 15 секунд (или сразу при входе в игру) плагин выдаст покупку. В игре: `/shop` показывает баланс и ссылку на магазин.
4. Админка → «Магазин» → «Заказы»: статус «выдан». Если «ошибка выдачи», исправьте команду в `products/<код>.yml`, сделайте `shop reload` и нажмите «Выдать снова». Или «Отменить и вернуть»: монеты вернутся игроку.

---

## 18. Финальная проверка

**[VPS]**
```bash
cd /opt/sitegate/infra
docker compose ps --format 'table {{.Name}}\t{{.Status}}'        # все Up
curl -s https://example.com/api/status; echo                     # "online":true
docker port sg-smp; docker port sg-mail; docker port sg-web      # пусто: наружу не открыты
docker port sg-proxy                                             # 25565/tcp -> 0.0.0.0:25565
sudo ss -ltnp | grep 8780                                        # только 127.0.0.1:8780
free -h                                                          # available не меньше 300–500 МБ
```

**[ПК]**
```bash
curl -sI https://example.com/ | head -1                                  # HTTP/2 200
timeout 5 bash -c '</dev/tcp/example.com/25565' && echo "игра доступна"
```

Чек-лист:
- [ ] Сайт открывается по HTTPS, `www` перекидывает на основной домен.
- [ ] В админке заменены тексты-примеры, название, адрес, Discord.
- [ ] Новый игрок проходит капчу, регистрируется на сайте и попадает на сервер.
- [ ] Письмо подтверждения приходит, в «Показать оригинал» SPF, DKIM, DMARC = PASS.
- [ ] «Забыли пароль?» присылает письмо, ссылка меняет пароль.
- [ ] Панель открывается через туннель, бэкап делается.
- [ ] Покупка в магазине выдаётся в игре (если пользуетесь магазином).
- [ ] PTR для IP поставлен (шаг 3.3).

---

## 19. Что сделать сразу после установки

1. **Бэкапы не только на этом VPS.** Бэкапы мира лежат на том же диске, что и мир: если VPS умрёт, умрут и они. Настройте копирование к себе или в облако: [OPERATIONS.md → Бэкапы](OPERATIONS.md#бэкапы).
2. **Сохраните в надёжное место** `infra/.env` и папку `infra/secrets/`: без них стек не восстановить один в один.
3. **Прочитайте [OPERATIONS.md](OPERATIONS.md)**: как обновлять сайт и плагины, менять токены, переезжать на другой VPS.
4. **Держите под рукой [TROUBLESHOOTING.md](TROUBLESHOOTING.md).**
