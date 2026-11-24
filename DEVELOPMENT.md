# Разработка

## Плагины (`plugins/`)
Нужен **JDK 25**. Gradle скачивает wrapper сам.
```bash
cd plugins
./gradlew build -PserverLibs=/путь/к/серверу/libraries
```
- Первая сборка скачивает `libs/velocity-4.3.1-45.jar` и сверяет sha256. SiteGateAuth компилируется против **полного** jar прокси: окна-диалоги в фазе конфигурации есть только во внутренних классах Velocity.
- SiteGateShop компилируется против библиотек Paper/Leaf 26.3 из папки `libraries` сервера (её создаёт первый запуск). Путь передаётся через `-PserverLibs`. По умолчанию это `plugins/run/smp/libraries`.
- В jar плагинов ничего не кладётся (shade нет): Gson, Adventure, MiniMessage, SnakeYAML берутся из сервера.
- Тесты: JUnit 5, `./gradlew test`. Логика вынесена в классы без Velocity/Bukkit (`flow/AuthSession`, `Delivery`) и гоняется на фейках.

### Правила
- **Внутренности Velocity трогает только `net/ConfigChannel`.** Обновляешь Velocity — прогоняй стенд заново (ниже).
- С пустым токеном вход закрыт (fail closed). В jar токен всегда пустой.
- Тексты для игроков только в `[messages]` конфига, MiniMessage. Подстановки через `Placeholder.unparsed`.
- Журнал выдачи SiteGateShop пишется **до** подтверждения сайту.

## Локальный стенд без настоящей игры
В `plugins/run/` (в git не попадает):
- `run/velocity/`: Velocity на `127.0.0.1:25577`, `online-mode=false`, modern forwarding, SiteGateAuth в `plugins/`;
- `run/smp/`: Paper/Leaf на `127.0.0.1:25566`, `white-list=false`, `proxies.velocity` с тем же секретом.

Моки сайта и мини-клиент (только stdlib Python):
```bash
python3 -I tools/mock-site.py --port 8765 --token devtoken      # API входа; GET /auth/{тикет} = «игрок подтвердил»
python3 -I tools/mock-shop.py --port 8766 --token shoptoken     # API магазина; GET /_add?name=&code= кладёт заказ
python3 -I tools/fake-client.py --mode approve                  # мини-клиент 26.3 (протокол 777)
```
Режимы `fake-client.py`:

| Режим | Что делает |
|---|---|
| `approve` | Решает капчу, «открывает» ссылку, ждёт подключения к SMP |
| `session` | Ждёт, что диалогов не будет и пустит сразу (живая IP-сессия) |
| `deny` | На сайте жмёт «Это не я», ждёт кик |
| `wrong` | Отвечает на капчу неверно, пока не кикнут |
| `leave` | Жмёт «Выйти» |
| `idle` | Решает капчу и ничего не делает, ждёт кик по таймауту |
| `external` | Печатает ссылку и ждёт, пока вход подтвердят снаружи, например на настоящем сайте |

Между входами выжидайте больше 3 секунд: иначе сработает встроенный лимит Velocity.

## Сайт (`site/`)
PHP **8.3** без фреймворка: свой роутер, PDO, шаблоны на чистом PHP, `league/commonmark`. CSS и JS без сборки.
```bash
docker build -t sitegate-web:test site
docker run --rm --entrypoint sh -v "$PWD/site:/src:ro" sitegate-web:test -c \
  'cp -r /src /tmp/app && cd /tmp/app && composer install -q && vendor/bin/phpunit \
   && find src public bin tests templates -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors"'
```
Соглашения:
- `declare(strict_types=1);`, PSR-12, namespace `SiteGate\` → `src/`;
- SQL только через prepared statements и только совместимый с MySQL 8 и MariaDB 10.4+;
- вывод в шаблонах только через `e()`;
- CSRF на каждой форме, никаких inline-скриптов (CSP);
- новые CSS и JS подключать через `asset()`.

Полезные команды в контейнере:
```bash
php bin/migrate.php                       # миграции на все три базы
php bin/create-admin.php <логин> [--reset]
php bin/player.php info|reset-sessions|password|balance <ник> [...]   # JSON, этим пользуется панель
php bin/wipe-players.php [--yes]          # удалить ВСЕ аккаунты игроков (без --yes только показывает)
```

## Панель (`infra/panel/`)
Один файл `panel.py`, только stdlib, Python 3.10+.
```bash
cd infra/panel && python3 test_panel.py   # docker подменяется фейком, HTTP гоняется по-настоящему
```

## Контракты API
- [AUTH-API.md](AUTH-API.md): SiteGateAuth ↔ сайт.
- [SHOP-API.md](SHOP-API.md): SiteGateShop ↔ сайт.

Меняешь контракт — меняй обе стороны и документ.
