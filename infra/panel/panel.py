#!/usr/bin/env python3
"""SiteGate: панель управления сервером Minecraft (стек /opt/sitegate/infra).

Только stdlib, Python 3.10+. Слушает 127.0.0.1:8780, снаружи её не видно: открывается через
SSH-туннель: ssh -L 8780:127.0.0.1:8780 ваш-vps. Сервером управляет через docker CLI и rcon-cli.

Запуск:   python3 panel.py
Пароль:   echo 'новый пароль' | python3 panel.py set-password
"""
import base64
import hashlib
import hmac
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import tarfile
import threading
import time
from datetime import datetime
from http import cookies
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, quote, urlparse

# ---------------------------------------------------------------- настройки
STACK = os.environ.get("SG_STACK", "/opt/sitegate/infra")
SMP_DIR = os.path.join(STACK, "servers", "SMP")
PROXY_DIR = os.path.join(STACK, "servers", "proxy")
BACKUP_DIR = os.environ.get("SG_BACKUPS", "/opt/sitegate/infra/backups")
DATA_DIR = os.environ.get("SG_PANEL_DATA", os.path.join(os.path.dirname(os.path.abspath(__file__)), "data"))
SETTINGS_FILE = os.path.join(DATA_DIR, "panel.json")
HISTORY_DIR = os.path.join(DATA_DIR, "config-history")
LISTEN = (os.environ.get("SG_PANEL_HOST", "127.0.0.1"), int(os.environ.get("SG_PANEL_PORT", "8780")))
ALLOWED_HOSTS = {f"localhost:{LISTEN[1]}", f"127.0.0.1:{LISTEN[1]}"}
SITE_URL = os.environ.get("SG_SITE_URL", "https://example.com")

CONTAINERS = {"smp": "sg-smp", "proxy": "sg-proxy", "web": "sg-web", "site": "sg-site", "mail": "sg-mail"}
TITLES = {"smp": "SMP (Leaf 26.3)", "proxy": "Прокси (Velocity + SiteGateAuth)",
          "web": "Сайт (PHP)", "site": "Caddy (HTTPS)", "mail": "Почта (Postfix)"}
STOP_TIMEOUT = {"smp": 120, "proxy": 30, "web": 15, "site": 15, "mail": 30}

# Конфиги, которые можно править из панели: id -> (путь, подсказка)
CONFIGS = {
    "server.properties": (os.path.join(SMP_DIR, "server.properties"), "SMP. Применится после перезапуска SMP."),
    "bukkit.yml": (os.path.join(SMP_DIR, "bukkit.yml"), "SMP. После перезапуска."),
    "spigot.yml": (os.path.join(SMP_DIR, "spigot.yml"), "SMP. После перезапуска."),
    "paper-global.yml": (os.path.join(SMP_DIR, "config", "paper-global.yml"),
                         "SMP. Блок proxies.velocity не трогать: на нём держится вход."),
    "paper-world-defaults.yml": (os.path.join(SMP_DIR, "config", "paper-world-defaults.yml"), "SMP. После перезапуска."),
    "leaf-global.yml": (os.path.join(SMP_DIR, "config", "leaf-global.yml"), "SMP. После перезапуска."),
    "purpur.yml": (os.path.join(SMP_DIR, "purpur.yml"), "SMP. После перезапуска."),
    "sitegateshop.yml": (os.path.join(SMP_DIR, "plugins", "SiteGateShop", "config.yml"), "SiteGateShop. После перезапуска SMP."),
    "velocity.toml": (os.path.join(PROXY_DIR, "velocity.toml"),
                      "Прокси. После перезапуска прокси (все игроки отключатся)."),
    "sitegateauth.toml": (os.path.join(PROXY_DIR, "plugins", "sitegateauth", "config.toml"), "SiteGateAuth. После перезапуска прокси."),
}

# Поля server.properties, которые правятся формой: ключ -> (тип, подпись, параметры)
PROPERTIES_FORM = {
    "motd": ("str", "MOTD (описание в списке серверов)", 200),
    "max-players": ("int", "Слоты", (1, 1000)),
    "difficulty": ("enum", "Сложность", ["peaceful", "easy", "normal", "hard"]),
    "gamemode": ("enum", "Режим игры", ["survival", "creative", "adventure", "spectator"]),
    "pvp": ("bool", "PvP", None),
    "hardcore": ("bool", "Хардкор", None),
    "allow-flight": ("bool", "Разрешить полёт (нужно для некоторых плагинов)", None),
    "white-list": ("bool", "Вайтлист", None),
    "view-distance": ("int", "Дальность прорисовки (чанков)", (2, 32)),
    "simulation-distance": ("int", "Дальность симуляции (чанков)", (2, 32)),
    "spawn-protection": ("int", "Защита спавна (блоков)", (0, 1000)),
}

BACKUP_EXCLUDE = {"libraries", "versions", "cache", "logs", "crash-reports", "server.jar", ".paper-remapped"}
BACKUP_RE = re.compile(r"smp-[0-9_-]+-(manual|auto)\.tar\.gz")
NICK_RE = re.compile(r"[A-Za-z0-9_]{3,16}")
PLUGIN_RE = re.compile(r"[A-Za-z0-9 ._+()\[\]-]{1,120}\.jar(\.disabled)?")
ANSI_RE = re.compile(r"\x1b\[[0-9;?]*[A-Za-z]|\x1b\][^\x07]*\x07|[\x00-\x08\x0b-\x1f\x7f]")
SESSION_TTL = 12 * 3600
MAX_UPLOAD = 64 * 1024 * 1024

DEFAULT_SETTINGS = {"password_hash": "", "salt": "", "backup_keep": 14, "backup_every_h": 12}

lock = threading.RLock()
sessions = {}       # token -> expiry
login_fails = []    # моменты неудачных входов (панель за туннелем, IP у всех 127.0.0.1)
state = {"busy": None, "msg": ""}
_stats_cache = {"at": 0.0, "data": {}}


def log(*a):
    print(time.strftime("%H:%M:%S"), *a, flush=True)


# ---------------------------------------------------------------- docker
class Docker:
    """Запуск внешних команд. В тестах подменяется фейком."""

    def run(self, args, timeout=30):
        try:
            p = subprocess.run(args, capture_output=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            return 124, "таймаут команды"
        except FileNotFoundError:
            return 127, "нет программы " + args[0]
        out = (p.stdout or b"") + (p.stderr or b"")
        return p.returncode, out.decode("utf-8", "replace")


D = Docker()


def clean(text):
    lines = []
    for line in text.replace("\r\n", "\n").split("\n"):
        line = line.split("\r")[-1]
        line = ANSI_RE.sub("", line)
        line = re.sub(r"^>\.*\s*", "", line)                    # подсказка консоли itzg «>....»
        line = re.sub(r"§[0-9a-fk-orx]", "", line, flags=re.I)  # цвета Minecraft
        lines.append(line)
    return "\n".join(lines).strip("\n")


def rcon(command, timeout=30):
    code, out = D.run(["docker", "exec", "-i", CONTAINERS["smp"], "rcon-cli", command], timeout=timeout)
    return code == 0, clean(out)


def inspect(key):
    code, out = D.run(["docker", "inspect", "--format", "{{json .State}}", CONTAINERS[key]], timeout=10)
    if code != 0:
        return None
    try:
        return json.loads(out.strip().splitlines()[-1])
    except (ValueError, IndexError):
        return None


def parse_docker_time(s):
    """2026-11-23T12:45:44.123456789Z -> unix time (Python 3.10 не понимает наносекунды и Z)."""
    if not s or s.startswith("0001-"):
        return None
    m = re.fullmatch(r"(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.(\d+))?(Z|[+-]\d\d:\d\d)?", s)
    if not m:
        return None
    frac = (m.group(2) or "0")[:6].ljust(6, "0")
    tz = m.group(3) or "Z"
    tz = "+00:00" if tz == "Z" else tz
    return datetime.fromisoformat(f"{m.group(1)}.{frac}{tz}").timestamp()


def container_running(key):
    st = inspect(key)
    return bool(st and st.get("Running"))


def stats():
    with lock:
        if time.time() - _stats_cache["at"] < 10:
            return _stats_cache["data"]
    names = list(CONTAINERS.values())
    code, out = D.run(["docker", "stats", "--no-stream", "--format",
                       "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}", *names], timeout=20)
    data = {}
    if code == 0:
        for line in out.splitlines():
            p = line.split("\t")
            if len(p) == 4:
                data[p[0]] = {"cpu": p[1], "mem": p[2], "memPerc": p[3]}
    with lock:
        _stats_cache.update(at=time.time(), data=data)
    return data


def host_info():
    mem = {}
    try:
        with open("/proc/meminfo") as f:
            for line in f:
                k, v = line.split(":", 1)
                mem[k] = int(v.split()[0]) * 1024
    except (OSError, ValueError):
        pass
    du = shutil.disk_usage(STACK if os.path.isdir(STACK) else "/")
    return {"memTotal": mem.get("MemTotal"), "memAvailable": mem.get("MemAvailable"),
            "diskTotal": du.total, "diskFree": du.free}


def players_online():
    ok, out = rcon("list", timeout=10)
    if not ok:
        return None
    m = re.search(r"There are (\d+) of a max of (\d+) players online:?(.*)", out, re.S)
    if not m:
        return None
    names = [n.strip() for n in m.group(3).replace("\n", " ").split(",") if n.strip()]
    return {"online": int(m.group(1)), "max": int(m.group(2)), "names": names}


def status():
    now = time.time()
    cs = {}
    st_all = stats()
    for key, name in CONTAINERS.items():
        st = inspect(key)
        started = parse_docker_time(st.get("StartedAt")) if st else None
        running = bool(st and st.get("Running"))
        cs[key] = {
            "title": TITLES[key],
            "name": name,
            "exists": st is not None,
            "running": running,
            "state": (st or {}).get("Status", "нет контейнера"),
            "health": ((st or {}).get("Health") or {}).get("Status"),
            "uptime": int(now - started) if running and started else None,
            "stats": st_all.get(name),
        }
    with lock:
        busy, msg = state["busy"], state["msg"]
    return {"containers": cs, "players": players_online() if cs["smp"]["running"] else None,
            "host": host_info(), "busy": busy, "msg": msg, "lastBackup": last_backup_time(), "site": SITE_URL}


# ---------------------------------------------------------------- фоновые задачи
def background(name, fn, *args):
    with lock:
        if state["busy"]:
            return False
        state["busy"] = name
        state["msg"] = name + "…"

    def worker():
        msg = name + ": готово"
        try:
            r = fn(*args)
            if isinstance(r, str):
                msg = r
        except Exception as e:  # noqa: BLE001 — ошибку показываем в панели
            log("ошибка", name, repr(e))
            msg = f"{name}: ошибка: {e}"
        with lock:
            state.update(busy=None, msg=msg)

    threading.Thread(target=worker, daemon=True).start()
    return True


def wait_idle(timeout=60):
    """Для тестов: дождаться окончания фоновой задачи."""
    end = time.time() + timeout
    while time.time() < end:
        with lock:
            if not state["busy"]:
                return state["msg"]
        time.sleep(0.05)
    raise TimeoutError("фоновая задача не закончилась")


def power(key, action, warn_delay=10):
    name = CONTAINERS[key]
    if key == "smp" and action in ("stop", "restart") and container_running("smp"):
        word = "перезапустится" if action == "restart" else "выключится"
        tellraw(f"Сервер {word} через {warn_delay} секунд")
        time.sleep(warn_delay)
    if action == "start":
        code, out = D.run(["docker", "start", name], timeout=60)
    elif action == "stop":
        code, out = D.run(["docker", "stop", "-t", str(STOP_TIMEOUT[key]), name], timeout=STOP_TIMEOUT[key] + 30)
    else:
        code, out = D.run(["docker", "restart", "-t", str(STOP_TIMEOUT[key]), name], timeout=STOP_TIMEOUT[key] + 90)
    if code != 0:
        raise RuntimeError(out.strip()[:300])
    words = {"start": "запущен", "stop": "остановлен", "restart": "перезапущен"}
    return f"{TITLES[key]}: {words[action]}"


def tellraw(text):
    payload = json.dumps([{"text": "[Сервер] ", "color": "light_purple", "bold": True},
                          {"text": text, "color": "white", "bold": False}], ensure_ascii=False)
    return rcon("tellraw @a " + payload)


# ---------------------------------------------------------------- бэкапы
def backup_list():
    if not os.path.isdir(BACKUP_DIR):
        return []
    out = []
    for n in os.listdir(BACKUP_DIR):
        p = os.path.join(BACKUP_DIR, n)
        if BACKUP_RE.fullmatch(n) and os.path.isfile(p):
            st = os.stat(p)
            out.append({"name": n, "size": st.st_size, "mtime": int(st.st_mtime)})
    return sorted(out, key=lambda b: (b["mtime"], b["name"]), reverse=True)


def last_backup_time():
    b = backup_list()
    return b[0]["mtime"] if b else None


def backup_path(name):
    if not isinstance(name, str) or not BACKUP_RE.fullmatch(name):
        return None
    p = os.path.join(BACKUP_DIR, name)
    return p if os.path.isfile(p) else None


def do_backup(reason="manual"):
    os.makedirs(BACKUP_DIR, mode=0o700, exist_ok=True)
    name = f"smp-{time.strftime('%Y-%m-%d_%H-%M-%S')}-{reason}.tar.gz"
    path = os.path.join(BACKUP_DIR, name)
    running = container_running("smp")
    if running:
        rcon("save-off")
        ok, out = rcon("save-all flush", timeout=300)
        if not ok:
            rcon("save-on")
            raise RuntimeError("save-all не прошёл: " + out[:200])
    tmp = path + ".part"
    try:
        old = os.umask(0o077)
        try:
            with tarfile.open(tmp, "w:gz", compresslevel=6) as tar:
                for entry in sorted(os.listdir(SMP_DIR)):
                    if entry in BACKUP_EXCLUDE:
                        continue
                    tar.add(os.path.join(SMP_DIR, entry), arcname="SMP/" + entry)
        finally:
            os.umask(old)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)
        if running:
            rcon("save-on")
    rotate_backups()
    return f"Бэкап {name} готов ({os.path.getsize(path) / 1048576:.1f} МБ)"


def rotate_backups():
    keep = max(1, int(load_settings().get("backup_keep", 14)))
    for b in backup_list()[keep:]:
        try:
            os.unlink(os.path.join(BACKUP_DIR, b["name"]))
            log("удалён старый бэкап", b["name"])
        except OSError:
            pass


def auto_backup_tick():
    every = float(load_settings().get("backup_every_h", 0) or 0)
    last = last_backup_time() or 0
    if every > 0 and time.time() - last >= every * 3600 and container_running("smp"):
        return background("Автобэкап", do_backup, "auto")
    return False


def auto_backup_loop():
    while True:
        time.sleep(60)
        try:
            auto_backup_tick()
        except Exception as e:  # noqa: BLE001
            log("автобэкап:", repr(e))


# ---------------------------------------------------------------- конфиги
def config_list():
    return [{"id": k, "hint": v[1], "exists": os.path.isfile(v[0])} for k, v in CONFIGS.items()]


def config_read(cid):
    if cid not in CONFIGS:
        return None
    path = CONFIGS[cid][0]
    if not os.path.isfile(path):
        return None
    with open(path, encoding="utf-8", errors="replace", newline="") as f:
        return {"id": cid, "hint": CONFIGS[cid][1], "content": f.read(), "mtime": os.path.getmtime(path)}


def config_check(cid, content):
    """Защита от правок, которые ломают вход через сайт. None = можно сохранять."""
    if cid == "server.properties" and re.search(r"(?m)^\s*online-mode\s*=\s*true\s*$", content):
        return "online-mode=true сломает вход: проверку делает SiteGateAuth на прокси. Оставь false."
    if cid == "velocity.toml":
        if re.search(r"(?m)^\s*online-mode\s*=\s*true\s*$", content):
            return "online-mode = true пустит только лицензию, вход через сайт перестанет работать."
        if not re.search(r'(?mi)^\s*player-info-forwarding-mode\s*=\s*"modern"', content):
            return 'player-info-forwarding-mode должен остаться "modern", иначе SMP не пустит никого.'
    return None


def config_write(cid, content, expect_mtime=None):
    if cid not in CONFIGS:
        raise ValueError("нет такого конфига")
    if not isinstance(content, str) or len(content.encode("utf-8")) > 2 * 1024 * 1024:
        raise ValueError("конфиг пустой или слишком большой")
    err = config_check(cid, content)
    if err:
        raise ValueError(err)
    path = CONFIGS[cid][0]
    if not os.path.isfile(path):
        raise ValueError("файла нет на диске")
    if expect_mtime is not None and abs(os.path.getmtime(path) - float(expect_mtime)) > 0.001:
        raise ValueError("файл изменился, пока ты его правил: обнови страницу")
    os.makedirs(HISTORY_DIR, mode=0o700, exist_ok=True)
    shutil.copy2(path, os.path.join(HISTORY_DIR, f"{cid}.{time.strftime('%Y-%m-%d_%H-%M-%S')}"))
    atomic_write(path, content)
    return os.path.getmtime(path)


def atomic_write(path, content):
    mode = os.stat(path).st_mode & 0o777 if os.path.exists(path) else 0o644
    tmp = path + ".panel-tmp"
    with open(tmp, "w", encoding="utf-8", newline="") as f:
        f.write(content)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def properties_read():
    cfg = config_read("server.properties")
    values = {}
    if cfg:
        for line in cfg["content"].splitlines():
            if "=" in line and not line.lstrip().startswith("#"):
                k, v = line.split("=", 1)
                if k.strip() in PROPERTIES_FORM:
                    values[k.strip()] = v
    fields = [{"key": k, "type": t, "label": label,
               "options": opts if t == "enum" else None, "value": values.get(k, "")}
              for k, (t, label, opts) in PROPERTIES_FORM.items()]
    return {"fields": fields, "mtime": cfg["mtime"] if cfg else None}


def properties_validate(values):
    out = {}
    for k, raw in values.items():
        if k not in PROPERTIES_FORM:
            raise ValueError(f"поле {k} не правится формой")
        t, label, opts = PROPERTIES_FORM[k]
        v = str(raw).strip()
        if t == "int":
            if not re.fullmatch(r"-?\d+", v) or not opts[0] <= int(v) <= opts[1]:
                raise ValueError(f"{label}: число от {opts[0]} до {opts[1]}")
        elif t == "bool":
            if v not in ("true", "false"):
                raise ValueError(f"{label}: true или false")
        elif t == "enum":
            if v not in opts:
                raise ValueError(f"{label}: одно из {', '.join(opts)}")
        elif len(v) > opts or re.search(r"[\x00-\x1f]", v):
            raise ValueError(f"{label}: до {opts} символов, одной строкой")
        out[k] = v
    return out


def properties_write(values, expect_mtime=None):
    values = properties_validate(values)
    cfg = config_read("server.properties")
    if not cfg:
        raise ValueError("server.properties не найден")
    lines = cfg["content"].split("\n")
    done = set()
    for i, line in enumerate(lines):
        if "=" in line and not line.lstrip().startswith("#"):
            k = line.split("=", 1)[0].strip()
            if k in values:
                lines[i] = f"{k}={values[k]}"
                done.add(k)
    for k in values:
        if k not in done:
            at = len(lines) - 1 if lines and lines[-1] == "" else len(lines)
            lines.insert(at, f"{k}={values[k]}")
    return config_write("server.properties", "\n".join(lines), expect_mtime)


# ---------------------------------------------------------------- плагины
def plugin_dir(where):
    return {"smp": os.path.join(SMP_DIR, "plugins"), "proxy": os.path.join(PROXY_DIR, "plugins")}[where]


def plugins_list():
    out = {}
    for where in ("smp", "proxy"):
        d = plugin_dir(where)
        items = []
        if os.path.isdir(d):
            for n in sorted(os.listdir(d), key=str.lower):
                p = os.path.join(d, n)
                if os.path.isfile(p) and PLUGIN_RE.fullmatch(n):
                    st = os.stat(p)
                    items.append({"file": n, "name": re.sub(r"\.jar(\.disabled)?$", "", n),
                                  "enabled": n.endswith(".jar"), "size": st.st_size, "mtime": int(st.st_mtime)})
        out[where] = items
    return out


def plugin_path(file):
    """Только jar из папки плагинов SMP. Плагины прокси из панели не трогаем: на них держится вход."""
    if not isinstance(file, str) or not PLUGIN_RE.fullmatch(file) or "/" in file or file.startswith("."):
        return None
    p = os.path.join(plugin_dir("smp"), file)
    return p if os.path.isfile(p) else None


def plugin_toggle(file):
    p = plugin_path(file)
    if not p:
        raise ValueError("нет такого плагина")
    target = p[: -len(".disabled")] if p.endswith(".disabled") else p + ".disabled"
    if os.path.exists(target):
        raise ValueError("рядом уже лежит " + os.path.basename(target))
    os.rename(p, target)
    return os.path.basename(target)


def plugin_delete(file):
    p = plugin_path(file)
    if not p:
        raise ValueError("нет такого плагина")
    if not p.endswith(".disabled"):
        raise ValueError("сначала выключи плагин, потом удаляй")
    os.unlink(p)


def plugin_upload(name, data):
    name = name or ""
    if "/" in name or "\\" in name or name.startswith(".") or not PLUGIN_RE.fullmatch(name) or not name.endswith(".jar"):
        raise ValueError("нужен файл .jar с обычным именем")
    if not data.startswith(b"PK"):
        raise ValueError("это не jar (не zip-архив)")
    d = plugin_dir("smp")
    os.makedirs(d, exist_ok=True)
    p = os.path.join(d, name)
    if os.path.exists(p) or os.path.exists(p + ".disabled"):
        raise ValueError("плагин с таким именем уже есть: удали старый или переименуй файл")
    tmp = p + ".upload"
    with open(tmp, "wb") as f:
        f.write(data)
    os.chmod(tmp, 0o644)
    os.replace(tmp, p)
    return name


# ---------------------------------------------------------------- игроки
MOD_ACTIONS = {
    "kick": lambda n, r: f"kick {n} {r or 'Кик от администрации'}",
    "ban": lambda n, r: f"ban {n} {r or 'Бан от администрации'}",
    "pardon": lambda n, r: f"pardon {n}",
    "op": lambda n, r: f"op {n}",
    "deop": lambda n, r: f"deop {n}",
    "wl-add": lambda n, r: f"whitelist add {n}",
    "wl-remove": lambda n, r: f"whitelist remove {n}",
}


def read_json_list(name):
    try:
        with open(os.path.join(SMP_DIR, name), encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, list) else []
    except (OSError, ValueError):
        return []


def players_info():
    return {
        "online": players_online() if container_running("smp") else None,
        "ops": [{"name": o.get("name"), "level": o.get("level")} for o in read_json_list("ops.json")],
        "banned": [{"name": b.get("name"), "reason": b.get("reason"), "created": b.get("created")}
                   for b in read_json_list("banned-players.json")],
        "whitelist": [w.get("name") for w in read_json_list("whitelist.json")],
    }


def check_nick(name):
    if not isinstance(name, str) or not NICK_RE.fullmatch(name):
        raise ValueError("ник: 3–16 символов, латиница, цифры и _")


def one_line(text, limit):
    return re.sub(r"[\x00-\x1f\x7f]", " ", str(text or ""))[:limit].strip()


def moderate(action, name, reason=""):
    if action not in MOD_ACTIONS:
        raise ValueError("нет такого действия")
    check_nick(name)
    ok, out = rcon(MOD_ACTIONS[action](name, one_line(reason, 200)))
    if not ok:
        raise RuntimeError(out or "rcon не ответил: SMP выключен?")
    return out


def site_player(args):
    """Аккаунт на сайте: bin/player.php в контейнере сайта (там доступ к трём базам)."""
    code, out = D.run(["docker", "exec", "-i", CONTAINERS["web"], "php", "bin/player.php", *args], timeout=30)
    try:
        data = json.loads(out.strip().splitlines()[-1])
    except (ValueError, IndexError):
        raise RuntimeError("сайт не ответил: " + out.strip()[:200])
    if not isinstance(data, dict) or code != 0 or not data.get("ok", False):
        raise RuntimeError((data.get("error") if isinstance(data, dict) else None) or "ошибка сайта")
    return data


def account_action(action, name, amount=None, reason=""):
    check_nick(name)
    if action in ("info", "reset-sessions", "password"):
        return site_player([action, name])
    if action == "balance":
        if not isinstance(amount, int) or isinstance(amount, bool) or amount == 0 or abs(amount) > 1_000_000:
            raise ValueError("сумма: целое число, не ноль, до миллиона")
        return site_player(["balance", name, str(amount), one_line(reason, 120) or "панель"])
    raise ValueError("нет такого действия")


# ---------------------------------------------------------------- логи
def logs(key, since=None, tail=400):
    if key not in CONTAINERS:
        raise ValueError("нет такого контейнера")
    if since is not None and not re.fullmatch(r"\d{4}-\d\d-\d\dT[0-9:.]+Z", since):
        raise ValueError("кривая отметка времени")
    args = ["docker", "logs", "--timestamps"] + (["--since", since] if since else ["--tail", str(tail)])
    code, out = D.run(args + [CONTAINERS[key]], timeout=15)
    if code != 0:
        return {"lines": [], "cursor": since}
    lines, cursor = [], since
    for raw in out.replace("\r\n", "\n").split("\n"):
        m = re.match(r"^(\d{4}-\d\d-\d\dT[0-9:.]+Z) ?(.*)$", raw)
        if not m:
            continue
        ts, text = m.group(1), clean(m.group(2))
        if since and ts <= since:
            continue
        cursor = ts
        if text.strip():
            lines.append(text)
    return {"lines": lines[-2000:], "cursor": cursor}


# ---------------------------------------------------------------- настройки и вход
def load_settings():
    s = dict(DEFAULT_SETTINGS)
    try:
        with open(SETTINGS_FILE, encoding="utf-8") as f:
            s.update(json.load(f))
    except (OSError, ValueError):
        pass
    return s


def save_settings(s):
    os.makedirs(DATA_DIR, mode=0o700, exist_ok=True)
    tmp = SETTINGS_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(s, f, ensure_ascii=False, indent=1)
    os.chmod(tmp, 0o600)
    os.replace(tmp, SETTINGS_FILE)


def public_settings():
    s = load_settings()
    return {"backup_keep": s.get("backup_keep"), "backup_every_h": s.get("backup_every_h")}


def hash_pw(pw, salt):
    return hashlib.pbkdf2_hmac("sha256", pw.encode("utf-8"), bytes.fromhex(salt), 300_000).hex()


def set_password(pw):
    pw = pw.strip() if isinstance(pw, str) else pw  # пробелы по краям при копировании не в счёт
    if not isinstance(pw, str) or len(pw) < 10:
        raise ValueError("пароль панели: минимум 10 символов")
    with lock:
        s = load_settings()
        s["salt"] = secrets.token_hex(16)
        s["password_hash"] = hash_pw(pw, s["salt"])
        save_settings(s)
        sessions.clear()


def check_password(pw):
    s = load_settings()
    if not s.get("password_hash") or not isinstance(pw, str):
        return False
    return hmac.compare_digest(hash_pw(pw.strip(), s["salt"]), s["password_hash"])


def login_blocked():
    now = time.time()
    with lock:
        login_fails[:] = [t for t in login_fails if now - t < 900]
        return len(login_fails) >= 10


# ---------------------------------------------------------------- HTTP
class H(BaseHTTPRequestHandler):
    server_version = "sg-panel"
    sys_version = ""

    def log_message(self, fmt, *a):
        line = str(a[0]) if a else ""
        if "/api/status" not in line and "/api/log" not in line:
            log(fmt % a)

    def send(self, code, body, ctype="application/json; charset=utf-8", headers=None):
        if isinstance(body, (dict, list)):
            body = json.dumps(body, ensure_ascii=False).encode("utf-8")
        elif isinstance(body, str):
            body = body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy",
                         "default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def token(self):
        try:
            c = cookies.SimpleCookie(self.headers.get("Cookie", ""))
        except cookies.CookieError:
            return ""
        return c["sg_panel"].value if "sg_panel" in c else ""

    def authed(self):
        t = self.token()
        with lock:
            exp = sessions.get(t)
            if t and exp and exp > time.time():
                sessions[t] = time.time() + SESSION_TTL
                return True
        return False

    def host_ok(self):
        # Защита от DNS rebinding: чужая страница не достучится до туннеля под своим именем
        return self.headers.get("Host", "") in ALLOWED_HOSTS

    def post_ok(self):
        origin = self.headers.get("Origin")
        if origin is not None and origin.replace("http://", "", 1) not in ALLOWED_HOSTS:
            return False
        return self.headers.get("X-Panel") == "1"

    def body(self, limit=1024 * 1024):
        try:
            n = int(self.headers.get("Content-Length") or 0)
        except ValueError:
            raise ValueError("кривой Content-Length")
        if n < 0 or n > limit:
            raise ValueError("слишком большой запрос")
        return self.rfile.read(n) if n else b""

    def jbody(self):
        try:
            data = json.loads(self.body() or b"{}")
        except ValueError:
            raise ValueError("кривой JSON")
        if not isinstance(data, dict):
            raise ValueError("кривой JSON")
        return data

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        if not self.host_ok():
            return self.send(403, {"error": "чужой Host"})
        u = urlparse(self.path)
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        if u.path == "/":
            return self.send(200, PAGE, "text/html; charset=utf-8")
        if u.path == "/app.css":
            return self.send(200, CSS, "text/css; charset=utf-8")
        if u.path == "/app.js":
            return self.send(200, JS, "text/javascript; charset=utf-8")
        if u.path in ("/favicon.png", "/favicon.ico"):
            return self.send(200, FAVICON, "image/png")
        if u.path == "/api/me":
            return self.send(200, {"authed": self.authed()})
        if not self.authed():
            return self.send(401, {"error": "нужен вход"})
        try:
            if u.path == "/api/status":
                return self.send(200, status())
            if u.path == "/api/log":
                return self.send(200, logs(q.get("c", "smp"), q.get("since") or None))
            if u.path == "/api/players":
                return self.send(200, players_info())
            if u.path == "/api/backups":
                return self.send(200, {"backups": backup_list(), "settings": public_settings()})
            if u.path == "/api/backup/download":
                return self.download(q.get("name"))
            if u.path == "/api/configs":
                return self.send(200, {"configs": config_list()})
            if u.path == "/api/config":
                c = config_read(q.get("id"))
                return self.send(200, c) if c else self.send(404, {"error": "нет такого конфига"})
            if u.path == "/api/properties":
                return self.send(200, properties_read())
            if u.path == "/api/plugins":
                return self.send(200, plugins_list())
            if u.path == "/api/settings":
                return self.send(200, public_settings())
        except (ValueError, RuntimeError) as e:
            return self.send(400, {"error": str(e)})
        return self.send(404, {"error": "нет такого адреса"})

    def download(self, name):
        p = backup_path(name)
        if not p:
            return self.send(404, {"error": "нет такого бэкапа"})
        self.send_response(200)
        self.send_header("Content-Type", "application/gzip")
        self.send_header("Content-Length", str(os.path.getsize(p)))
        self.send_header("Content-Disposition", f"attachment; filename=\"{name}\"; filename*=UTF-8''{quote(name)}")
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        with open(p, "rb") as f:
            shutil.copyfileobj(f, self.wfile, 1024 * 1024)

    def do_POST(self):
        if not self.host_ok() or not self.post_ok():
            return self.send(403, {"error": "запрос не из панели"})
        u = urlparse(self.path)
        try:
            if u.path == "/api/login":
                return self.login()
            if not self.authed():
                return self.send(401, {"error": "нужен вход"})
            if u.path == "/api/plugins/upload":
                name = parse_qs(u.query).get("name", [""])[0]
                return self.send(200, {"ok": True, "file": plugin_upload(name, self.body(MAX_UPLOAD))})
            return self.send(200, self.action(u.path, self.jbody()))
        except (ValueError, RuntimeError) as e:
            return self.send(400, {"error": str(e)})

    def login(self):
        if login_blocked():
            return self.send(429, {"error": "слишком много неверных паролей, подожди 15 минут"})
        d = self.jbody()
        if not check_password(d.get("password")):
            with lock:
                login_fails.append(time.time())
            return self.send(401, {"error": "неверный пароль"})
        t = secrets.token_urlsafe(32)
        with lock:
            sessions[t] = time.time() + SESSION_TTL
        return self.send(200, {"ok": True}, headers={
            "Set-Cookie": f"sg_panel={t}; HttpOnly; SameSite=Strict; Path=/; Max-Age={SESSION_TTL}"})

    def action(self, path, d):
        if path == "/api/logout":
            with lock:
                sessions.pop(self.token(), None)
            return {"ok": True}
        if path == "/api/power":
            key, act = d.get("target"), d.get("action")
            if key not in CONTAINERS or act not in ("start", "stop", "restart"):
                raise ValueError("нет такого действия")
            if key in ("web", "site", "mail") and act != "restart":
                raise ValueError("сайт и почта из панели только перезапускаются")
            if not background(f"{TITLES[key]}: {act}", power, key, act):
                raise ValueError("панель занята: " + str(state["busy"]))
            return {"ok": True}
        if path == "/api/command":
            cmd = str(d.get("command", "")).strip().lstrip("/")
            if not cmd or len(cmd) > 1000 or re.search(r"[\x00-\x1f]", cmd):
                raise ValueError("пустая или кривая команда")
            ok, out = rcon(cmd, timeout=60)
            if not ok:
                raise RuntimeError(out or "rcon не ответил: SMP выключен?")
            return {"ok": True, "out": out}
        if path == "/api/announce":
            msg = one_line(d.get("message"), 301)
            if not msg or len(msg) > 300:
                raise ValueError("сообщение: от 1 до 300 символов")
            ok, out = tellraw(msg)
            if not ok:
                raise RuntimeError(out or "rcon не ответил: SMP выключен?")
            return {"ok": True}
        if path == "/api/moderate":
            return {"ok": True, "out": moderate(d.get("action"), d.get("name"), d.get("reason", ""))}
        if path == "/api/account":
            return account_action(d.get("action"), d.get("name"), d.get("amount"), d.get("reason", ""))
        if path == "/api/backup":
            if not background("Бэкап", do_backup, "manual"):
                raise ValueError("панель занята: " + str(state["busy"]))
            return {"ok": True}
        if path == "/api/backup/delete":
            p = backup_path(d.get("name"))
            if not p:
                raise ValueError("нет такого бэкапа")
            os.unlink(p)
            return {"ok": True}
        if path == "/api/config":
            return {"ok": True, "mtime": config_write(d.get("id"), d.get("content"), d.get("mtime"))}
        if path == "/api/properties":
            values = d.get("values")
            if not isinstance(values, dict):
                raise ValueError("нет значений")
            return {"ok": True, "mtime": properties_write(values, d.get("mtime"))}
        if path == "/api/plugins/toggle":
            return {"ok": True, "file": plugin_toggle(d.get("file"))}
        if path == "/api/plugins/delete":
            plugin_delete(d.get("file"))
            return {"ok": True}
        if path == "/api/settings":
            keep, every = d.get("backup_keep"), d.get("backup_every_h")
            if not isinstance(keep, int) or isinstance(keep, bool) or not 1 <= keep <= 200:
                raise ValueError("хранить бэкапов: от 1 до 200")
            if not isinstance(every, (int, float)) or isinstance(every, bool) or not 0 <= every <= 168:
                raise ValueError("автобэкап: от 0 (выключен) до 168 часов")
            with lock:
                s = load_settings()
                s.update(backup_keep=keep, backup_every_h=every)
                save_settings(s)
            return {"ok": True}
        if path == "/api/password":
            if not check_password(d.get("old")):
                raise ValueError("текущий пароль неверный")
            set_password(d.get("new"))
            return {"ok": True}
        raise ValueError("нет такого адреса")


# ---------------------------------------------------------------- страница
PAGE = """<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SiteGate · панель</title><link rel="icon" type="image/png" href="/favicon.png"><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head>
<body>
<div id="login" class="login" hidden>
  <form id="lf" class="card"><h1>SiteGate</h1><p class="dim">Панель управления</p>
  <input type="password" id="lpw" placeholder="Пароль панели" autocomplete="current-password">
  <button class="b g">Войти</button><p id="lerr" class="warn"></p></form>
</div>
<div id="app" hidden>
<header><b class="brand">SiteGate</b><nav id="nav"></nav><span id="busy" class="dim"></span><button class="b" id="logout">Выйти</button></header>
<main>
<section data-t="Обзор">
  <div class="grid" id="cards"></div>
  <div class="card"><h2>Объявление в игре</h2><div class="row"><input id="ann" maxlength="300" placeholder="Текст для всех игроков"><button class="b" id="annBtn">Отправить</button></div></div>
  <div class="card"><h2>Ссылки</h2><p><a id="siteLink" target="_blank" rel="noopener">Сайт</a> · <a id="adminLink" target="_blank" rel="noopener">Админка сайта: новости, магазин, игроки</a></p></div>
</section>
<section data-t="Консоль" hidden>
  <div class="card"><div class="row"><select id="logSel"><option value="smp">SMP</option><option value="proxy">Прокси</option><option value="web">Сайт</option><option value="site">Caddy</option><option value="mail">Почта</option></select>
  <label class="chk"><input type="checkbox" id="autoscroll" checked> автопрокрутка</label></div>
  <pre id="log"></pre>
  <form id="cf" class="row"><input id="cmd" placeholder="Команда SMP через RCON, например: list, say привет, lp user Steve info" autocomplete="off"><button class="b">Выполнить</button></form>
  <pre id="cmdOut" class="out" hidden></pre></div>
</section>
<section data-t="Игроки" hidden>
  <div class="card"><h2>Онлайн</h2><p id="online" class="dim"></p></div>
  <div class="card"><h2>Действие с игроком</h2>
    <div class="row"><input id="pName" placeholder="Ник" maxlength="16"><input id="pReason" placeholder="Причина (для кика и бана)" maxlength="200"></div>
    <div class="row">
      <button class="b" data-mod="kick">Кик</button><button class="b r" data-mod="ban">Бан</button><button class="b" data-mod="pardon">Разбан</button>
      <button class="b" data-mod="op">Выдать op</button><button class="b" data-mod="deop">Снять op</button>
      <button class="b" data-mod="wl-add">+ вайтлист</button><button class="b" data-mod="wl-remove">− вайтлист</button>
    </div>
    <h3>Аккаунт на сайте</h3>
    <div class="row"><button class="b" data-acc="info">Показать</button><button class="b" data-acc="reset-sessions">Сбросить входы</button><button class="b r" data-acc="password">Новый пароль</button></div>
    <div class="row"><input id="bAmount" type="number" placeholder="Монеты: 100 или -50"><input id="bReason" placeholder="За что (видно в истории)" maxlength="120"><button class="b g" data-acc="balance">Изменить баланс</button></div>
    <pre id="pOut" class="out" hidden></pre></div>
  <div class="grid3"><div class="card"><h2>Операторы</h2><ul id="ops"></ul></div><div class="card"><h2>Баны</h2><ul id="bans"></ul></div><div class="card"><h2>Вайтлист</h2><ul id="wl"></ul></div></div>
</section>
<section data-t="Бэкапы" hidden>
  <div class="card"><div class="row"><button class="b g" id="bkNow">Сделать бэкап сейчас</button><span class="dim">Мир, плагины и конфиги SMP. Сервер на это время не выключается.</span></div>
  <div class="tw"><table><thead><tr><th>Файл</th><th>Размер</th><th>Когда</th><th></th></tr></thead><tbody id="bk"></tbody></table></div></div>
  <div class="card"><h2>Расписание</h2><div class="row"><label>Автобэкап каждые, ч (0 = выкл)<input type="number" id="sEvery" min="0" max="168"></label><label>Хранить бэкапов<input type="number" id="sKeep" min="1" max="200"></label><button class="b" id="sSave">Сохранить</button></div></div>
</section>
<section data-t="Конфиги" hidden>
  <div class="card"><h2>server.properties</h2><div id="pf" class="form"></div><button class="b g" id="pSave">Сохранить</button> <span class="dim">Применится после перезапуска SMP.</span></div>
  <div class="card"><h2>Файл целиком</h2><div class="row"><select id="cfgSel"></select><span id="cfgHint" class="dim"></span></div>
  <textarea id="cfgText" spellcheck="false"></textarea><button class="b g" id="cfgSave">Сохранить файл</button> <span class="dim">Прошлая версия сохраняется в истории панели.</span></div>
</section>
<section data-t="Плагины" hidden>
  <div class="card"><h2>SMP</h2><div class="row"><input type="file" id="pjar" accept=".jar"><button class="b g" id="pUp">Загрузить</button><span class="dim">Изменения применятся после перезапуска SMP.</span></div>
  <div class="tw"><table><thead><tr><th>Плагин</th><th>Размер</th><th>Состояние</th><th></th></tr></thead><tbody id="plSmp"></tbody></table></div></div>
  <div class="card"><h2>Прокси</h2><p class="dim">Только просмотр: на SiteGateAuth держится вход, его обновляют вместе с Velocity.</p><ul id="plProxy"></ul></div>
</section>
<section data-t="Настройки" hidden>
  <div class="card"><h2>Пароль панели</h2><div class="row"><input type="password" id="oldPw" placeholder="Текущий" autocomplete="current-password"><input type="password" id="newPw" placeholder="Новый, от 10 символов" autocomplete="new-password"><button class="b" id="pwSave">Сменить</button></div></div>
</section>
</main></div>
<div id="toast" class="toast" hidden></div>
</body></html>"""

CSS = """:root{--bg:#0b0d14;--panel:#141725;--panel2:#1b1f31;--line:#2a2f48;--text:#e6e4f0;--dim:#9a9fb8;--acc:#9b8cff;--ok:#5cd68c;--bad:#ff6b6b;--warn:#f5c26b;color-scheme:dark}
*{box-sizing:border-box}[hidden]{display:none!important}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--acc)}h1{margin:0}h2{margin:0 0 10px;font-size:16px}h3{margin:14px 0 8px;font-size:14px;color:var(--dim)}
header{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--line);background:var(--panel);position:sticky;top:0;z-index:2}
.brand{color:var(--acc);letter-spacing:.05em}nav{display:flex;gap:4px;flex-wrap:wrap;flex:1}
nav button{background:none;border:1px solid transparent;color:var(--dim);padding:6px 12px;border-radius:6px;cursor:pointer;font:inherit}
nav button.on{color:var(--text);border-color:var(--line);background:var(--panel2)}
main{padding:16px;max-width:1200px;margin:0 auto}.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:14px}
.grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-bottom:14px}.grid .card{margin:0}
.grid3{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.grid3 .card{margin:0}
.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}.row input:not([type=checkbox]),.row select{flex:1;min-width:140px}
input,select,textarea{background:var(--panel2);color:var(--text);border:1px solid var(--line);border-radius:6px;padding:7px 10px;font:inherit}
textarea{width:100%;min-height:420px;font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;margin:8px 0;resize:vertical}
label{display:flex;flex-direction:column;gap:4px;color:var(--dim);font-size:13px}label.chk{flex-direction:row;align-items:center}
.b{display:inline-block;background:var(--panel2);color:var(--text);border:1px solid var(--line);padding:7px 14px;border-radius:6px;cursor:pointer;font:inherit;text-decoration:none}
.b:hover{border-color:var(--acc)}.b.g{border-color:#2f6b3d}.b.r{border-color:#7a2f2f}.b:disabled{opacity:.4;cursor:default}
.dim{color:var(--dim)}.warn{color:var(--warn)}.ok{color:var(--ok)}
pre{background:#07080d;border:1px solid var(--line);border-radius:6px;padding:10px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:12px/1.45 ui-monospace,Menlo,Consolas,monospace}
#log{height:480px}.out{max-height:260px}
.tw{overflow-x:auto}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--dim);font-weight:600}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:var(--bad)}.dot.on{background:var(--ok)}.dot.wait{background:var(--warn)}
.kv{display:grid;grid-template-columns:auto 1fr;gap:2px 12px;margin:8px 0}.kv span:nth-child(odd){color:var(--dim)}
.form{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:12px}
ul{margin:0;padding-left:18px}.login{min-height:100vh;display:grid;place-items:center}.login .card{width:min(360px,92vw);display:grid;gap:10px}
.toast{position:fixed;right:16px;bottom:16px;max-width:min(520px,90vw);background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:10px 14px;z-index:5;white-space:pre-wrap}
.toast.err{border-color:var(--bad)}"""

JS = r"""'use strict';
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let tab='Обзор',logCursor=null,logKey='smp',pollT=null,cfgMtime=null,propMtime=null;
async function api(path,body,raw){
  const o={headers:{'X-Panel':'1'}};
  if(body!==undefined){o.method='POST';if(raw){o.body=body}else{o.headers['Content-Type']='application/json';o.body=JSON.stringify(body)}}
  const r=await fetch(path,o);let d={};try{d=await r.json()}catch(e){}
  if(r.status===401&&path!=='/api/login'){showLogin();throw new Error('нужен вход')}
  if(!r.ok)throw new Error(d.error||('ошибка '+r.status));return d}
function toast(t,err){const el=$('#toast');el.textContent=t;el.className='toast'+(err?' err':'');el.hidden=false;clearTimeout(el._t);el._t=setTimeout(()=>el.hidden=true,err?8000:4000)}
async function act(fn,okMsg){try{const r=await fn();if(okMsg)toast(okMsg);return r}catch(e){toast(e.message,true)}}
const fmtB=b=>b==null?'—':b>=1073741824?(b/1073741824).toFixed(2)+' ГБ':b>=1048576?(b/1048576).toFixed(1)+' МБ':Math.round(b/1024)+' КБ';
const fmtT=s=>{if(s==null)return'—';const d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);return(d?d+' д ':'')+(h?h+' ч ':'')+m+' мин'};
const fmtD=t=>t?new Date(t*1000).toLocaleString('ru-RU'):'—';
function showLogin(){clearTimeout(pollT);$('#app').hidden=true;$('#login').hidden=false;$('#lpw').focus()}
function showApp(){$('#login').hidden=true;$('#app').hidden=false;show(tab)}
$('#lf').addEventListener('submit',async e=>{e.preventDefault();$('#lerr').textContent='';
  try{await api('/api/login',{password:$('#lpw').value});$('#lpw').value='';showApp()}catch(err){$('#lerr').textContent=err.message}});
$('#logout').addEventListener('click',async()=>{await act(()=>api('/api/logout',{}));showLogin()});
const tabs=$$('section[data-t]');
$('#nav').innerHTML=tabs.map(s=>`<button data-t="${esc(s.dataset.t)}">${esc(s.dataset.t)}</button>`).join('');
$$('#nav button').forEach(b=>b.addEventListener('click',()=>show(b.dataset.t)));
function show(t){tab=t;tabs.forEach(s=>s.hidden=s.dataset.t!==t);$$('#nav button').forEach(b=>b.classList.toggle('on',b.dataset.t===t));
  clearTimeout(pollT);({'Обзор':loadStatus,'Консоль':()=>{logCursor=null;$('#log').textContent='';loadLog()},'Игроки':loadPlayers,'Бэкапы':loadBackups,'Конфиги':loadConfigs,'Плагины':loadPlugins}[t]||(()=>{}))()}
// --- обзор
async function loadStatus(){try{renderStatus(await api('/api/status'))}catch(e){}if(tab==='Обзор'&&!$('#app').hidden)pollT=setTimeout(loadStatus,5000)}
function renderStatus(s){
  $('#busy').textContent=s.busy?('⏳ '+s.busy):(s.msg||'');
  $('#siteLink').href=s.site;$('#siteLink').textContent=s.site;$('#adminLink').href=s.site+'/admin';
  const p=s.players,h=s.host;
  let html=Object.entries(s.containers).map(([k,c])=>{
    const dot=c.running?(c.health==='starting'?'wait':'on'):'';
    const btns=k==='smp'||k==='proxy'?`<button class="b g" data-p="${k}:start" ${c.running?'disabled':''}>▶ Запустить</button><button class="b" data-p="${k}:restart" ${c.running?'':'disabled'}>⟳ Перезапуск</button><button class="b r" data-p="${k}:stop" ${c.running?'':'disabled'}>■ Стоп</button>`:`<button class="b" data-p="${k}:restart">⟳ Перезапуск</button>`;
    return `<div class="card"><h2><span class="dot ${dot}"></span>${esc(c.title)}</h2><div class="kv">
    <span>Состояние</span><span>${esc(c.state)}${c.health?' · '+esc(c.health):''}</span><span>Аптайм</span><span>${fmtT(c.uptime)}</span>
    <span>CPU</span><span>${esc(c.stats?.cpu??'—')}</span><span>Память</span><span>${esc(c.stats?.mem??'—')}</span>
    ${k==='smp'?`<span>Игроки</span><span>${p?p.online+' / '+p.max:'—'}</span>`:''}</div><div class="row">${btns}</div></div>`}).join('');
  html+=`<div class="card"><h2>VPS</h2><div class="kv"><span>Память свободна</span><span>${fmtB(h.memAvailable)} из ${fmtB(h.memTotal)}</span>
    <span>Диск свободен</span><span>${fmtB(h.diskFree)} из ${fmtB(h.diskTotal)}</span><span>Последний бэкап</span><span>${fmtD(s.lastBackup)}</span></div></div>`;
  $('#cards').innerHTML=html;
  $$('#cards [data-p]').forEach(b=>{b.disabled=b.disabled||!!s.busy;b.addEventListener('click',()=>power(...b.dataset.p.split(':')))})}
async function power(target,action){
  if(action!=='start'&&target!=='web'&&target!=='site'&&target!=='mail'){
    const who=target==='smp'?'Игроков на SMP предупредят и через 10 секунд отключат.':'Все игроки отключатся, пока прокси перезапускается.';
    if(!confirm((action==='stop'?'Остановить? ':'Перезапустить? ')+who))return}
  await act(()=>api('/api/power',{target,action}),'Запущено, смотри статус');loadStatus()}
$('#annBtn').addEventListener('click',()=>act(async()=>{await api('/api/announce',{message:$('#ann').value});$('#ann').value=''},'Отправлено'));
// --- консоль
$('#logSel').addEventListener('change',()=>{logKey=$('#logSel').value;logCursor=null;$('#log').textContent='';clearTimeout(pollT);loadLog()});
async function loadLog(){try{const r=await api('/api/log?c='+logKey+(logCursor?'&since='+encodeURIComponent(logCursor):''));
  if(r.lines.length){const el=$('#log');el.textContent+=(el.textContent?'\n':'')+r.lines.join('\n');
    if(el.textContent.length>400000)el.textContent=el.textContent.slice(-300000);if($('#autoscroll').checked)el.scrollTop=el.scrollHeight}
  logCursor=r.cursor||logCursor}catch(e){}if(tab==='Консоль'&&!$('#app').hidden)pollT=setTimeout(loadLog,2000)}
$('#cf').addEventListener('submit',e=>{e.preventDefault();const c=$('#cmd').value.trim();if(!c)return;
  act(async()=>{const r=await api('/api/command',{command:c});const o=$('#cmdOut');o.hidden=false;o.textContent='> '+c+'\n'+(r.out||'(пусто)');$('#cmd').value=''})});
// --- игроки
async function loadPlayers(){await act(async()=>{const r=await api('/api/players');
  $('#online').innerHTML=r.online?`${r.online.online} из ${r.online.max}: `+(r.online.names.map(n=>`<a href="#" data-n="${esc(n)}">${esc(n)}</a>`).join(', ')||'никого'):'SMP выключен';
  $$('#online [data-n]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();$('#pName').value=a.dataset.n}));
  const li=(a,f)=>a.length?a.map(f).join(''):'<li class="dim">пусто</li>';
  $('#ops').innerHTML=li(r.ops,o=>`<li>${esc(o.name)} <span class="dim">ур. ${esc(o.level)}</span></li>`);
  $('#bans').innerHTML=li(r.banned,b=>`<li>${esc(b.name)} <span class="dim">${esc(b.reason)}</span></li>`);
  $('#wl').innerHTML=li(r.whitelist,n=>`<li>${esc(n)}</li>`)})}
const pOut=t=>{const o=$('#pOut');o.hidden=false;o.textContent=t};
$$('[data-mod]').forEach(b=>b.addEventListener('click',()=>act(async()=>{
  const r=await api('/api/moderate',{action:b.dataset.mod,name:$('#pName').value.trim(),reason:$('#pReason').value});pOut(r.out||'готово');loadPlayers()})));
$$('[data-acc]').forEach(b=>b.addEventListener('click',()=>act(async()=>{
  const a=b.dataset.acc,name=$('#pName').value.trim();
  if(a==='password'&&!confirm('Сгенерировать новый пароль на сайте для '+name+'? Старый перестанет работать.'))return;
  const body={action:a,name};if(a==='balance'){body.amount=parseInt($('#bAmount').value,10);body.reason=$('#bReason').value}
  const r=await api('/api/account',body);
  if(a==='info')pOut(r.exists?`Ник: ${r.name}\nПочта: ${r.email||'—'}${r.email?(r.email_verified?' (подтверждена)':' (не подтверждена)'):''}\nБаза: DB${r.shard}\nРегистрация: ${r.created_at} UTC\nПоследний вход: ${r.last_login_at||'—'}\nНаиграно: ${fmtT(r.playtime_seconds)}\nIP-сессий: ${r.sessions}\nБаланс: ${r.balance} монет`:'На сайте такого аккаунта нет');
  else if(a==='password')pOut('Новый пароль для '+r.name+': '+r.password+'\nПокажи его игроку, второй раз он не появится.');
  else if(a==='balance'){pOut(`Баланс ${r.name}: ${r.balance} монет`);$('#bAmount').value=''}
  else pOut('Входы '+r.name+' сброшены: в игре он снова подтвердит вход на сайте.')})));
// --- бэкапы
async function loadBackups(){await act(async()=>{const r=await api('/api/backups');
  $('#bk').innerHTML=r.backups.map(b=>`<tr><td>${esc(b.name)}</td><td>${fmtB(b.size)}</td><td>${fmtD(b.mtime)}</td>
  <td><a class="b" href="/api/backup/download?name=${encodeURIComponent(b.name)}">Скачать</a> <button class="b r" data-del="${esc(b.name)}">Удалить</button></td></tr>`).join('')||'<tr><td colspan="4" class="dim">Бэкапов пока нет</td></tr>';
  $$('[data-del]').forEach(x=>x.addEventListener('click',()=>{if(confirm('Удалить '+x.dataset.del+'?'))act(async()=>{await api('/api/backup/delete',{name:x.dataset.del});loadBackups()},'Удалено')}));
  $('#sEvery').value=r.settings.backup_every_h;$('#sKeep').value=r.settings.backup_keep})}
$('#bkNow').addEventListener('click',()=>act(()=>api('/api/backup',{}),'Бэкап запущен, займёт до пары минут'));
$('#sSave').addEventListener('click',()=>act(()=>api('/api/settings',{backup_every_h:Number($('#sEvery').value),backup_keep:parseInt($('#sKeep').value,10)}),'Сохранено'));
// --- конфиги
async function loadConfigs(){await act(async()=>{
  const p=await api('/api/properties');propMtime=p.mtime;
  $('#pf').innerHTML=p.fields.map(f=>{const id='pp_'+f.key;let inp;
    if(f.type==='bool')inp=`<select id="${id}"><option value="true" ${f.value==='true'?'selected':''}>да</option><option value="false" ${f.value!=='true'?'selected':''}>нет</option></select>`;
    else if(f.type==='enum')inp=`<select id="${id}">${f.options.map(o=>`<option ${o===f.value?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
    else inp=`<input id="${id}" value="${esc(f.value)}" ${f.type==='int'?'type="number"':''}>`;
    return `<label>${esc(f.label)}${inp}</label>`}).join('');
  $('#pf').dataset.keys=p.fields.map(f=>f.key).join(',');
  const c=await api('/api/configs');const sel=$('#cfgSel'),cur=sel.value;
  sel.innerHTML=c.configs.filter(x=>x.exists).map(x=>`<option>${esc(x.id)}</option>`).join('');if(cur)sel.value=cur;await loadCfg()})}
async function loadCfg(){if(!$('#cfgSel').value)return;await act(async()=>{const r=await api('/api/config?id='+encodeURIComponent($('#cfgSel').value));
  $('#cfgText').value=r.content;$('#cfgHint').textContent=r.hint;cfgMtime=r.mtime})}
$('#cfgSel').addEventListener('change',loadCfg);
$('#cfgSave').addEventListener('click',()=>act(async()=>{const r=await api('/api/config',{id:$('#cfgSel').value,content:$('#cfgText').value,mtime:cfgMtime});cfgMtime=r.mtime},'Сохранено'));
$('#pSave').addEventListener('click',()=>act(async()=>{const values={};$('#pf').dataset.keys.split(',').forEach(k=>{const v=$('#pp_'+k).value;if(v!=='')values[k]=v});
  const r=await api('/api/properties',{values,mtime:propMtime});propMtime=r.mtime;if($('#cfgSel').value==='server.properties')await loadCfg()},'Сохранено. Перезапусти SMP, чтобы применилось.'));
// --- плагины
async function loadPlugins(){await act(async()=>{const r=await api('/api/plugins');
  $('#plSmp').innerHTML=r.smp.map(p=>`<tr><td>${esc(p.name)}</td><td>${fmtB(p.size)}</td><td>${p.enabled?'<span class="ok">вкл</span>':'<span class="dim">выкл</span>'}</td>
  <td><button class="b" data-tg="${esc(p.file)}">${p.enabled?'Выключить':'Включить'}</button> ${p.enabled?'':`<button class="b r" data-rm="${esc(p.file)}">Удалить</button>`}</td></tr>`).join('')||'<tr><td colspan="4" class="dim">Плагинов нет</td></tr>';
  $('#plProxy').innerHTML=r.proxy.map(p=>`<li>${esc(p.name)} <span class="dim">${fmtB(p.size)}</span></li>`).join('')||'<li class="dim">пусто</li>';
  $$('[data-tg]').forEach(b=>b.addEventListener('click',()=>act(async()=>{await api('/api/plugins/toggle',{file:b.dataset.tg});loadPlugins()},'Готово. Применится после перезапуска SMP.')));
  $$('[data-rm]').forEach(b=>b.addEventListener('click',()=>{if(confirm('Удалить '+b.dataset.rm+'?'))act(async()=>{await api('/api/plugins/delete',{file:b.dataset.rm});loadPlugins()},'Удалено')}))})}
$('#pUp').addEventListener('click',()=>{const f=$('#pjar').files[0];if(!f)return toast('Выбери .jar',true);
  act(async()=>{await api('/api/plugins/upload?name='+encodeURIComponent(f.name),f,true);$('#pjar').value='';loadPlugins()},'Загружено. Перезапусти SMP, чтобы плагин включился.')});
// --- настройки
$('#pwSave').addEventListener('click',()=>act(async()=>{await api('/api/password',{old:$('#oldPw').value,new:$('#newPw').value});$('#oldPw').value=$('#newPw').value='';showLogin()},'Пароль сменён, войди заново'));
api('/api/me').then(r=>r.authed?showApp():showLogin()).catch(()=>showLogin());"""


# Иконка SiteGate 32×32 для вкладки браузера
FAVICON = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAABmJLR0QA/wD/AP+gvaeTAAADTklEQVRYhcVXS0hUURj+zp0742vMF/hISPKBiRiSiiW2SLLysbDFtEgXoVDUok0rc9OiWkS7IITQwB4QiS2sZUimUqAN+MpSR6IEC8rXqNN47/3jzDg6M9d774yofcMP99zz/ef85/u/udzL4IeslLJkt4DrjKGGQDkAi8GugFYI7CsjvLEoeDD9c+CXb4b5LtJTT9gYQxuAWOwtlolY4+zcQOdmAd7N6YV/QXsMIqILs3MfO1lKSlmyyOSpfTh5ABiwJMGSbYq3pjWD4TT2HxECFJdIAtWC8J9AtSIUygo3LSkxBUdyCxEXl+QZLy7+xucJO/7Mb5o7VGSLBLKGys7PK4Gt7gpyso+CsUC/EhEmp4bx8lUrxiYGQ10ylqUlHzNsgCAIsNVdQ23VpZBW7entQsfze5BkyZArkoEB+EkvN95GaVElJCk0s5wsOw+LJQat7S0eZfRgskan3tIjnK1oQOWpi+DrhBMH07Kw5lrBtGN45wrEWhNQdaZp25M7vo3A4RjxXGdmFiAzo0DFqa5sQv+HbjhXFrQLgE4Bx4trYBZjIPm1cnVtGY+ftmBsoj+Am59Xjsb6O4iK2vK02WxFaXE13r57prmHwHukFXm55ZAlCoj2jpsY/dyn4o6Ov0f7kxYVPz+3XHN9HoJXge0jKeGQR35f8H6OfenT5I9O9MIxMxKQk5iQocnnIeq5NCoiwXMKHyZn7IaunnTYkZ6WvzmOjkzUzRH1FlNIgCJvjV2uVRiBO1/yywEXWQeiXnXB7lcU7xNPD5wTnGegAGlO+svvXVzZ6J1eAYoqTy9HDEcBWTZWgHP2TAEKQQEKWwHoKQC1BwxboM7TyxE9D27NAoI9sPGg1y3A+/8PrGCHCshykAcUzjbwgEKqvN1TQA5BATl8BZa13oiD3ycoBA9wjipPO2eJxR84bAdQ6H+3pKgCzTcewmy2YDewvu7G3ftXMfipJ3jKLgDKa29n/X4GMu8Mql34vW5mtWYlmwRp3z9M+Mu0QuvZJrd7fiXSHDdJgG0/P80EYvVLzh9DJj76614cj7TEjYHROf7FssebLwpE9QvO71184CnAV4TZEt3GwFwKWCxAvCW740LASWCjDPSImNKw5Jwd8k38A3qALmFpA76IAAAAAElFTkSuQmCC")


# ---------------------------------------------------------------- запуск
def main():
    if len(sys.argv) > 1 and sys.argv[1] == "set-password":
        set_password(sys.stdin.readline().rstrip("\r\n"))
        print("пароль панели задан")
        return
    os.makedirs(DATA_DIR, mode=0o700, exist_ok=True)
    if not load_settings().get("password_hash"):
        pw = secrets.token_urlsafe(15)
        set_password(pw)
        p = os.path.join(DATA_DIR, "INITIAL_PASSWORD.txt")
        with open(p, "w", encoding="utf-8") as f:
            f.write(pw + "\n")
        os.chmod(p, 0o600)
        log("пароля не было: создан, лежит в", p)
    threading.Thread(target=auto_backup_loop, daemon=True).start()
    srv = ThreadingHTTPServer(LISTEN, H)
    log(f"панель слушает http://{LISTEN[0]}:{LISTEN[1]}")
    srv.serve_forever()


if __name__ == "__main__":
    main()
