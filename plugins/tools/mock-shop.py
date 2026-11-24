#!/usr/bin/env python3
"""Мок API магазина для локальной проверки SiteGateShop. Только stdlib.

Запуск: python3 -I tools/mock-shop.py --port 8766 --token shoptoken
Контракт: docs/shop-api.md.
  GET /_add?name=Steve&code=vip&title=VIP — положить заказ в очередь
  GET /_state                              — состояние в JSON
"""
import argparse
import json
import re
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

lock = threading.Lock()
state = {"orders": {}, "next": 1, "codes": None, "balances": {"steve": 120}, "calls": []}


class H(BaseHTTPRequestHandler):
    def log_message(self, fmt, *a):
        print("[mock-shop]", self.command, self.path, flush=True)

    def send(self, code, obj):
        b = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def authed(self):
        return self.headers.get("Authorization") == "Bearer " + self.server.token

    def do_GET(self):
        u = urlparse(self.path)
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        if u.path == "/_state":
            with lock:
                return self.send(200, state)
        if u.path == "/_add":
            with lock:
                i = state["next"]
                state["next"] += 1
                state["orders"][i] = {"id": i, "name": q["name"], "code": q["code"], "title": q.get("title", q["code"]),
                                      "status": "pending", "note": None}
            return self.send(200, {"id": i})
        if not self.authed():
            return self.send(401, {"error": "unauthorized"})
        with lock:
            state["calls"].append("GET " + u.path)
            if u.path == "/api/shop/pending":
                pend = [{k: o[k] for k in ("id", "name", "code", "title")}
                        for o in state["orders"].values() if o["status"] == "pending"]
                return self.send(200, {"orders": pend[: int(q.get("limit", 50))]})
            m = re.fullmatch(r"/api/shop/balance/([A-Za-z0-9_]{3,16})", u.path)
            if m:
                b = state["balances"].get(m.group(1).lower())
                return self.send(200, {"registered": b is not None, "balance": b or 0})
        return self.send(404, {"error": "not_found"})

    def do_POST(self):
        if not self.authed():
            return self.send(401, {"error": "unauthorized"})
        n = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(n) or b"{}")
        u = urlparse(self.path)
        with lock:
            state["calls"].append("POST " + u.path + " " + json.dumps(body, ensure_ascii=False))
            if u.path == "/api/shop/delivered":
                upd = 0
                for i in body.get("delivered", []):
                    o = state["orders"].get(i)
                    if o and o["status"] == "pending":
                        o["status"] = "delivered"
                        upd += 1
                for f in body.get("failed", []):
                    o = state["orders"].get(f["id"])
                    if o and o["status"] == "pending":
                        o.update(status="failed", note=f["error"])
                        upd += 1
                return self.send(200, {"updated": upd})
            if u.path == "/api/shop/codes":
                state["codes"] = body.get("codes")
                return self.send(200, {"count": len(state["codes"] or [])})
        return self.send(404, {"error": "not_found"})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8766)
    ap.add_argument("--token", default="shoptoken")
    a = ap.parse_args()
    srv = ThreadingHTTPServer(("127.0.0.1", a.port), H)
    srv.token = a.token
    print(f"[mock-shop] http://127.0.0.1:{a.port}", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
