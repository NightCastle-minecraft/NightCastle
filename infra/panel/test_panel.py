"""Тесты панели без Docker: docker и rcon подменяются фейком, HTTP гоняется по-настоящему.
Запуск: python3 -m unittest -v test_panel.py
"""
import http.client
import io
import json
import os
import socket
import tarfile
import tempfile
import threading
import time
import unittest


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


TMP = tempfile.mkdtemp(prefix="sg-panel-test-")
PORT = free_port()
os.environ.update(SG_STACK=os.path.join(TMP, "stack"), SG_BACKUPS=os.path.join(TMP, "backups"),
                  SG_PANEL_DATA=os.path.join(TMP, "data"), SG_PANEL_PORT=str(PORT))

import panel  # noqa: E402  (после настройки окружения)


class FakeDocker:
    def __init__(self):
        self.calls = []
        self.running = {"sg-smp": True, "sg-proxy": True, "sg-web": True, "sg-site": True, "sg-mail": True}
        self.player_php = {"ok": True, "exists": True, "name": "Steve", "shard": 2, "created_at": "2026-11-23 10:00:00",
                           "last_login_at": None, "playtime_seconds": 3600, "sessions": 1, "balance": 50}

    def run(self, args, timeout=30):
        self.calls.append(args)
        if args[:2] == ["docker", "inspect"]:
            name = args[-1]
            st = {"Status": "running" if self.running[name] else "exited", "Running": self.running[name],
                  "StartedAt": "2026-11-23T12:00:00.123456789Z"}
            if name == "sg-smp":
                st["Health"] = {"Status": "healthy"}
            return 0, json.dumps(st) + "\n"
        if args[:2] == ["docker", "stats"]:
            return 0, "sg-smp\t12.5%\t5.6GiB / 6.3GiB\t88%\nsg-proxy\t0.3%\t400MiB / 640MiB\t62%\n"
        if args[:3] == ["docker", "exec", "-i"] and args[3] == "sg-smp" and args[4] == "rcon-cli":
            if not self.running["sg-smp"]:
                return 1, "connection refused"
            cmd = args[5]
            if cmd == "list":
                return 0, "There are 1 of a max of 20 players online: Steve\n"
            if cmd.startswith("save-all"):
                return 0, "Saved the game\n"
            return 0, f"\x1b[0;33m§eok: {cmd}\x1b[0m\n"
        if args[:3] == ["docker", "exec", "-i"] and args[3] == "sg-web":
            sub = args[6:]
            if sub[0] == "balance":
                return 0, json.dumps({"ok": True, "name": sub[1], "balance": 50 + int(sub[2])})
            if sub[0] == "password":
                return 0, json.dumps({"ok": True, "name": sub[1], "password": "NewPass123abc"})
            return 0, json.dumps(self.player_php)
        if args[:2] == ["docker", "logs"]:
            return 0, ("2026-11-23T12:00:01.000000001Z >....\x1b[K[12:00:01 INFO]: Done (40.1s)!\n"
                       "2026-11-23T12:00:02.000000001Z [12:00:02 INFO]: Steve joined the game\n")
        if args[1] in ("start", "stop", "restart"):
            self.running[args[-1]] = args[1] != "stop"
            return 0, args[-1] + "\n"
        return 1, "неизвестная команда " + " ".join(args)

    def rcon_cmds(self):
        return [a[5] for a in self.calls if a[:2] == ["docker", "exec"] and a[3] == "sg-smp"]


class PanelTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        smp = panel.SMP_DIR
        os.makedirs(os.path.join(smp, "world"), exist_ok=True)
        os.makedirs(os.path.join(smp, "plugins"), exist_ok=True)
        os.makedirs(os.path.join(smp, "libraries"), exist_ok=True)
        os.makedirs(os.path.join(smp, "config"), exist_ok=True)
        os.makedirs(os.path.join(panel.PROXY_DIR, "plugins"), exist_ok=True)
        with open(os.path.join(smp, "world", "level.dat"), "wb") as f:
            f.write(b"level")
        with open(os.path.join(smp, "libraries", "big.jar"), "wb") as f:
            f.write(b"x" * 1000)
        with open(os.path.join(smp, "server.properties"), "w") as f:
            f.write("#Minecraft server properties\nmotd=SiteGate\nmax-players=20\nonline-mode=false\npvp=true\n")
        with open(os.path.join(panel.PROXY_DIR, "velocity.toml"), "w") as f:
            f.write('online-mode = false\nplayer-info-forwarding-mode = "MODERN"\n')
        with open(os.path.join(smp, "ops.json"), "w") as f:
            json.dump([{"name": "Admin", "level": 4}], f)
        with open(os.path.join(panel.PROXY_DIR, "plugins", "SiteGateAuth-1.0.0.jar"), "wb") as f:
            f.write(b"PK..")
        panel.set_password("correct horse battery")
        cls.srv = panel.ThreadingHTTPServer(("127.0.0.1", PORT), panel.H)
        threading.Thread(target=cls.srv.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def setUp(self):
        self.fake = FakeDocker()
        panel.D = self.fake
        panel._stats_cache["at"] = 0
        with panel.lock:
            panel.login_fails.clear()
            panel.state.update(busy=None, msg="")
        self.cookie = self.login("correct horse battery")

    # -- помощники
    def req(self, method, path, body=None, cookie=None, headers=None, raw=None):
        c = http.client.HTTPConnection("127.0.0.1", PORT, timeout=10)
        h = {"Host": f"localhost:{PORT}"}
        if method == "POST":
            h["X-Panel"] = "1"
        if cookie:
            h["Cookie"] = cookie
        data = raw
        if body is not None:
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
        h.update(headers or {})
        c.request(method, path, body=data, headers={k: v for k, v in h.items() if v is not None})
        r = c.getresponse()
        payload = r.read()
        ctype = r.getheader("Content-Type", "")
        out = json.loads(payload) if ctype.startswith("application/json") else payload
        return r.status, out, r

    def login(self, pw):
        status, _, r = self.req("POST", "/api/login", {"password": pw})
        if status != 200:
            return None
        return r.getheader("Set-Cookie").split(";")[0]

    def get(self, path):
        return self.req("GET", path, cookie=self.cookie)

    def post(self, path, body):
        return self.req("POST", path, body, cookie=self.cookie)

    # -- вход и защита
    def test_login_and_guards(self):
        self.assertEqual(self.req("GET", "/")[0], 200)
        self.assertEqual(self.req("GET", "/api/status")[0], 401, "без входа")
        self.assertIsNone(self.login("wrong password"))
        self.assertIsNotNone(self.login("  correct horse battery\n"), "пробелы и перенос по краям при копировании")
        self.assertTrue(self.cookie.startswith("sg_panel="))
        self.assertEqual(self.get("/api/me")[1], {"authed": True})
        # POST без заголовка панели и с чужим Origin не проходит
        self.assertEqual(self.req("POST", "/api/command", {"command": "list"}, self.cookie, {"X-Panel": None})[0], 403)
        self.assertEqual(self.req("POST", "/api/command", {"command": "list"}, self.cookie, {"Origin": "http://evil.example"})[0], 403)
        # DNS rebinding: чужой Host
        self.assertEqual(self.req("GET", "/api/status", cookie=self.cookie, headers={"Host": "evil.example:8780"})[0], 403)
        self.assertEqual(self.post("/api/logout", {})[0], 200)
        self.assertEqual(self.get("/api/status")[0], 401, "после выхода")

    def test_login_rate_limit(self):
        for _ in range(10):
            self.login("wrong password")
        self.assertEqual(self.req("POST", "/api/login", {"password": "correct horse battery"})[0], 429)

    # -- обзор
    def test_status(self):
        s, d, _ = self.get("/api/status")
        self.assertEqual(s, 200)
        self.assertTrue(d["containers"]["smp"]["running"])
        self.assertEqual(d["containers"]["smp"]["health"], "healthy")
        self.assertEqual(d["containers"]["smp"]["stats"]["cpu"], "12.5%")
        self.assertGreater(d["containers"]["smp"]["uptime"], 0)
        self.assertEqual(d["players"], {"online": 1, "max": 20, "names": ["Steve"]})

    def test_power_restart_warns_and_restarts(self):
        panel.power("smp", "restart", warn_delay=0)
        self.assertTrue(self.fake.rcon_cmds()[0].startswith("tellraw @a "))
        self.assertIn(["docker", "restart", "-t", "120", "sg-smp"], self.fake.calls)
        self.assertEqual(self.post("/api/power", {"target": "web", "action": "stop"})[0], 400, "сайт не выключаем")
        self.assertEqual(self.post("/api/power", {"target": "nope", "action": "start"})[0], 400)

    def test_command_and_announce(self):
        s, d, _ = self.post("/api/command", {"command": "/say hi"})
        self.assertEqual(s, 200)
        self.assertEqual(d["out"], "ok: say hi", "ANSI и цвета вычищены")
        self.assertEqual(self.post("/api/command", {"command": "say a\nstop"})[0], 400)
        self.assertEqual(self.post("/api/announce", {"message": "Рестарт в 5 минут"})[0], 200)
        payload = self.fake.rcon_cmds()[-1]
        self.assertTrue(payload.startswith("tellraw @a "))
        self.assertIn("Рестарт в 5 минут", json.loads(payload[len("tellraw @a "):])[1]["text"])

    def test_logs_cursor(self):
        s, d, _ = self.get("/api/log?c=smp")
        self.assertEqual(d["lines"], ["[12:00:01 INFO]: Done (40.1s)!", "[12:00:02 INFO]: Steve joined the game"])
        self.assertEqual(d["cursor"], "2026-11-23T12:00:02.000000001Z")
        d2 = panel.logs("smp", d["cursor"])
        self.assertEqual(d2["lines"], [], "старые строки не дублируются")
        self.assertEqual(self.get("/api/log?c=smp&since=bad;rm")[0], 400)

    # -- игроки
    def test_moderation(self):
        self.assertEqual(self.post("/api/moderate", {"action": "kick", "name": "Steve", "reason": "AFK"})[0], 200)
        self.assertEqual(self.fake.rcon_cmds()[-1], "kick Steve AFK")
        self.assertEqual(self.post("/api/moderate", {"action": "ban", "name": "Steve", "reason": "гриф\nop Hacker"})[0], 200)
        self.assertEqual(self.fake.rcon_cmds()[-1], "ban Steve гриф op Hacker", "перевод строки вычищен")
        for bad in ("Steve stop", "St", "a" * 17, "Steve;op"):
            self.assertEqual(self.post("/api/moderate", {"action": "kick", "name": bad})[0], 400, bad)
        self.assertEqual(self.post("/api/moderate", {"action": "stop", "name": "Steve"})[0], 400)
        s, d, _ = self.get("/api/players")
        self.assertEqual(d["ops"], [{"name": "Admin", "level": 4}])

    def test_site_account(self):
        s, d, _ = self.post("/api/account", {"action": "info", "name": "Steve"})
        self.assertEqual((s, d["shard"], d["balance"]), (200, 2, 50))
        s, d, _ = self.post("/api/account", {"action": "balance", "name": "Steve", "amount": 100, "reason": "донат"})
        self.assertEqual(d["balance"], 150)
        self.assertEqual(self.fake.calls[-1][-4:], ["balance", "Steve", "100", "донат"])
        self.assertEqual(self.post("/api/account", {"action": "balance", "name": "Steve", "amount": "100"})[0], 400)
        self.assertEqual(self.post("/api/account", {"action": "balance", "name": "Steve", "amount": 0})[0], 400)
        self.assertEqual(self.post("/api/account", {"action": "drop", "name": "Steve"})[0], 400)
        self.fake.player_php = {"ok": False, "error": "нет такого аккаунта"}
        s, d, _ = self.post("/api/account", {"action": "reset-sessions", "name": "Steve"})
        self.assertEqual((s, d["error"]), (400, "нет такого аккаунта"))

    # -- конфиги
    def test_properties_form(self):
        s, d, _ = self.get("/api/properties")
        values = {f["key"]: f["value"] for f in d["fields"]}
        self.assertEqual(values["max-players"], "20")
        s, d2, _ = self.post("/api/properties", {"values": {"max-players": "30", "difficulty": "hard"}, "mtime": d["mtime"]})
        self.assertEqual(s, 200)
        with open(panel.CONFIGS["server.properties"][0]) as f:
            text = f.read()
        self.assertIn("max-players=30\n", text)
        self.assertIn("difficulty=hard\n", text)
        self.assertIn("#Minecraft server properties\n", text, "комментарий на месте")
        self.assertIn("online-mode=false\n", text, "остальные ключи на месте")
        self.assertEqual(self.post("/api/properties", {"values": {"max-players": "abc"}})[0], 400)
        self.assertEqual(self.post("/api/properties", {"values": {"online-mode": "true"}})[0], 400, "не из формы")
        self.assertEqual(self.post("/api/properties", {"values": {"motd": "a\nonline-mode=true"}})[0], 400)
        self.assertEqual(self.post("/api/properties", {"values": {"pvp": "false"}, "mtime": d["mtime"]})[0], 400, "устаревший mtime")

    def test_raw_config_guards_and_history(self):
        s, d, _ = self.get("/api/config?id=server.properties")
        bad = d["content"].replace("online-mode=false", "online-mode=true")
        s, e, _ = self.post("/api/config", {"id": "server.properties", "content": bad, "mtime": d["mtime"]})
        self.assertEqual(s, 400)
        self.assertIn("online-mode", e["error"])
        s, v, _ = self.get("/api/config?id=velocity.toml")
        self.assertEqual(self.post("/api/config", {"id": "velocity.toml", "content": v["content"].replace("MODERN", "legacy")})[0], 400)
        self.assertEqual(self.post("/api/config", {"id": "../../etc/passwd", "content": "x"})[0], 400)
        self.assertEqual(self.get("/api/config?id=../../etc/passwd")[0], 404)
        ok = d["content"] + "# правка из теста\n"
        self.assertEqual(self.post("/api/config", {"id": "server.properties", "content": ok})[0], 200)
        hist = os.listdir(panel.HISTORY_DIR)
        self.assertTrue(any(h.startswith("server.properties.") for h in hist), hist)

    # -- плагины
    def test_plugins(self):
        jar = b"PK\x03\x04 fake jar"
        self.assertEqual(self.req("POST", "/api/plugins/upload?name=Test-1.0.jar", cookie=self.cookie, raw=jar)[0], 200)
        for bad in ("../evil.jar", ".hidden.jar", "notes.txt", "a/b.jar"):
            self.assertEqual(self.req("POST", "/api/plugins/upload?name=" + bad, cookie=self.cookie, raw=jar)[0], 400, bad)
        self.assertEqual(self.req("POST", "/api/plugins/upload?name=Real.jar", cookie=self.cookie, raw=b"not a zip")[0], 400)
        self.assertEqual(self.req("POST", "/api/plugins/upload?name=Test-1.0.jar", cookie=self.cookie, raw=jar)[0], 400, "дубль")
        s, d, _ = self.get("/api/plugins")
        self.assertEqual([p["file"] for p in d["smp"]], ["Test-1.0.jar"])
        self.assertEqual([p["name"] for p in d["proxy"]], ["SiteGateAuth-1.0.0"])
        self.assertEqual(self.post("/api/plugins/delete", {"file": "Test-1.0.jar"})[0], 400, "включённый не удаляется")
        self.assertEqual(self.post("/api/plugins/toggle", {"file": "Test-1.0.jar"})[1]["file"], "Test-1.0.jar.disabled")
        self.assertEqual(self.post("/api/plugins/toggle", {"file": "../../proxy/plugins/SiteGateAuth-1.0.0.jar"})[0], 400)
        self.assertEqual(self.post("/api/plugins/delete", {"file": "Test-1.0.jar.disabled"})[0], 200)
        self.assertEqual(self.get("/api/plugins")[1]["smp"], [])

    # -- бэкапы
    def test_backup_cycle(self):
        with panel.lock:
            s = panel.load_settings()
            s["backup_keep"] = 2
            panel.save_settings(s)
        names = []
        for _ in range(3):
            msg = panel.do_backup("manual")
            self.assertIn("готов", msg)
            names.append(panel.backup_list()[0]["name"])
            time.sleep(1.1)
        cmds = self.fake.rcon_cmds()
        self.assertEqual(cmds[:3], ["save-off", "save-all flush", "save-on"], "сохранение мира вокруг архива")
        listed = [b["name"] for b in panel.backup_list()]
        self.assertEqual(len(listed), 2, "старые удаляются по лимиту")
        self.assertNotIn(names[0], listed)
        s, data, r = self.get("/api/backup/download?name=" + listed[0])
        self.assertEqual(s, 200)
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as tar:
            members = tar.getnames()
        self.assertIn("SMP/world/level.dat", members)
        self.assertFalse(any(m.startswith("SMP/libraries") for m in members), "libraries не тащим")
        self.assertEqual(self.get("/api/backup/download?name=../../etc/passwd")[0], 404)
        self.assertEqual(self.post("/api/backup/delete", {"name": listed[1]})[0], 200)
        self.assertEqual(len(panel.backup_list()), 1)
        self.assertEqual(oct(os.stat(os.path.join(panel.BACKUP_DIR, listed[0])).st_mode & 0o777), "0o600")

    def test_backup_while_smp_stopped_skips_rcon(self):
        self.fake.running["sg-smp"] = False
        panel.do_backup("manual")
        self.assertEqual(self.fake.rcon_cmds(), [])

    def test_settings_and_password(self):
        self.assertEqual(self.post("/api/settings", {"backup_keep": 5, "backup_every_h": 6})[0], 200)
        self.assertEqual(self.get("/api/settings")[1], {"backup_keep": 5, "backup_every_h": 6})
        self.assertEqual(self.post("/api/settings", {"backup_keep": 0, "backup_every_h": 6})[0], 400)
        self.assertEqual(self.post("/api/password", {"old": "wrong", "new": "another long pass"})[0], 400)
        self.assertEqual(self.post("/api/password", {"old": "correct horse battery", "new": "short"})[0], 400)
        self.assertEqual(self.post("/api/password", {"old": "correct horse battery", "new": "another long pass"})[0], 200)
        self.assertEqual(self.get("/api/status")[0], 401, "после смены пароля сессии сброшены")
        panel.set_password("correct horse battery")

    def test_docker_time(self):
        self.assertAlmostEqual(panel.parse_docker_time("1970-01-01T00:00:10.5Z"), 10.5)
        self.assertIsNone(panel.parse_docker_time("0001-01-01T00:00:00Z"))
        self.assertIsNone(panel.parse_docker_time("garbage"))


if __name__ == "__main__":
    unittest.main()
