#!/usr/bin/env python3
"""Мок API сайта для локальной проверки SiteGateAuth. Только stdlib.

Запуск: python3 -I tools/mock-site.py --port 8765 --token devtoken
Контракт: docs/auth-api.md.

Вместо настоящей страницы /auth/{ticket} здесь «браузер-заглушка»:
  GET /auth/{ticket}           — игрок подтвердил вход (approved)
  GET /auth/{ticket}?deny=1    — игрок нажал «Это не я» (denied)
  GET /_state                  — всё состояние в JSON, для проверок
"""
import argparse
import base64
import hashlib
import hmac
import json
import re
import secrets
import threading
import time
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

NICK = re.compile(r"^[A-Za-z0-9_]{3,16}$")
TICKET_TTL = 600
SESSION_TTL = 7 * 24 * 3600

lock = threading.Lock()
state = {
    "accounts": {},   # name_lower -> {"name", "shard", "playtime"}
    "sessions": {},   # "name_lower|ip" -> expires
    "tickets": {},    # sha256 -> {"name", "ip", "status", "expires"}
    "played": [],     # принятые сессии
    "heartbeat": None,
    "calls": [],      # журнал вызовов API
}


def shard(name: str) -> int:
    return zlib.crc32(name.lower().encode()) % 3 + 1


class Handler(BaseHTTPRequestHandler):
    server_version = "mock-site"
    sys_version = ""

    def log_message(self, fmt, *args):
        print(f"[mock-site] {self.command} {self.path} -> {args[1] if len(args) > 1 else ''}", flush=True)

    # --- утилиты ---
    def send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authorized(self):
        token = self.server.token
        if not token:
            self.send(404, {"error": "not_found"})
            return False
        given = self.headers.get("Authorization", "")
        if not hmac.compare_digest(given, "Bearer " + token):
            self.send(401, {"error": "unauthorized"})
            return False
        return True

    def body(self):
        n = int(self.headers.get("Content-Length") or 0)
        try:
            return json.loads(self.rfile.read(n) or b"{}")
        except json.JSONDecodeError:
            return None

    def name_ip(self, data):
        if not isinstance(data, dict):
            return None, None
        name, ip = data.get("name"), data.get("ip")
        if not isinstance(name, str) or not NICK.match(name) or not isinstance(ip, str) or not ip:
            return None, None
        return name, ip

    # --- маршруты ---
    def do_GET(self):
        url = urlparse(self.path)
        if url.path == "/_state":
            with lock:
                return self.send(200, state)
        m = re.fullmatch(r"/auth/([1-3][A-Za-z0-9_-]{20,64})", url.path)
        if m:
            return self.browser(m.group(1), "deny" in parse_qs(url.query))
        m = re.fullmatch(r"/api/auth/tickets/([^/]+)", url.path)
        if m:
            if not self.authorized():
                return
            h = hashlib.sha256(m.group(1).encode()).hexdigest()
            with lock:
                state["calls"].append("status")
                t = state["tickets"].get(h)
                if t is None or (t["status"] == "pending" and t["expires"] < time.time()):
                    return self.send(200, {"status": "expired"})
                return self.send(200, {"status": t["status"]})
        self.send(404, {"error": "not_found"})

    def do_POST(self):
        path = urlparse(self.path).path
        if not path.startswith("/api/"):
            return self.send(404, {"error": "not_found"})
        if not self.authorized():
            return
        data = self.body()
        if data is None:
            return self.send(400, {"error": "bad_json"})
        if path == "/api/auth/check":
            name, ip = self.name_ip(data)
            if not name:
                return self.send(400, {"error": "bad_request"})
            key = f"{name.lower()}|{ip}"
            with lock:
                state["calls"].append("check")
                registered = name.lower() in state["accounts"]
                if registered and state["sessions"].get(key, 0) > time.time():
                    state["sessions"][key] = time.time() + SESSION_TTL
                    return self.send(200, {"status": "ok", "registered": True})
                return self.send(200, {"status": "need_auth", "registered": registered})
        if path == "/api/auth/tickets":
            name, ip = self.name_ip(data)
            if not name:
                return self.send(400, {"error": "bad_request"})
            ticket = str(shard(name)) + base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip("=")
            h = hashlib.sha256(ticket.encode()).hexdigest()
            with lock:
                state["calls"].append("ticket")
                for t in state["tickets"].values():
                    if t["name"].lower() == name.lower() and t["status"] == "pending":
                        t["status"] = "expired"
                state["tickets"][h] = {"name": name, "ip": ip, "status": "pending",
                                       "expires": time.time() + TICKET_TTL}
            base = self.server.public_url
            return self.send(200, {"ticket": ticket, "url": f"{base}/auth/{ticket}", "expiresIn": TICKET_TTL})
        if path == "/api/stats/sessions":
            items = data.get("sessions") if isinstance(data, dict) else None
            if not isinstance(items, list):
                return self.send(400, {"error": "bad_request"})
            accepted = 0
            with lock:
                seen = {(p["name"].lower(), p["start"]) for p in state["played"]}
                for s in items:
                    key = (str(s.get("name", "")).lower(), s.get("start"))
                    acc = state["accounts"].get(key[0])
                    if acc is None or key in seen or not isinstance(s.get("end"), int):
                        continue
                    dur = max(0, min(86400, s["end"] - s["start"]))
                    acc["playtime"] += dur
                    state["played"].append({"name": s["name"], "start": s["start"], "end": s["end"]})
                    seen.add(key)
                    accepted += 1
            return self.send(200, {"accepted": accepted})
        if path == "/api/server/heartbeat":
            with lock:
                state["heartbeat"] = {"online": data.get("online"), "max": data.get("max"), "at": time.time()}
            return self.send(200, {})
        self.send(404, {"error": "not_found"})

    def browser(self, ticket, deny):
        """Заглушка страницы сайта: игрок вошёл/зарегистрировался и нажал кнопку."""
        h = hashlib.sha256(ticket.encode()).hexdigest()
        with lock:
            t = state["tickets"].get(h)
            if t is None or t["status"] != "pending" or t["expires"] < time.time():
                return self.send(410, {"error": "ticket_gone"})
            if deny:
                t["status"] = "denied"
            else:
                t["status"] = "approved"
                low = t["name"].lower()
                state["accounts"].setdefault(low, {"name": t["name"], "shard": shard(t["name"]), "playtime": 0})
                state["sessions"][f"{low}|{t['ip']}"] = time.time() + SESSION_TTL
        self.send(200, {"ok": True, "status": t["status"]})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8765)
    ap.add_argument("--token", default="devtoken")
    args = ap.parse_args()
    srv = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    srv.token = args.token
    srv.public_url = f"http://127.0.0.1:{args.port}"
    print(f"[mock-site] слушаю {srv.public_url}, токен задан: {bool(args.token)}", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
