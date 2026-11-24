#!/usr/bin/env python3
"""Мини-клиент Minecraft 26.3 (протокол 777) для проверки SiteGateAuth без настоящей игры. Только stdlib.

Заходит на прокси в offline-режиме, проходит фазу конфигурации и реагирует на диалоги SiteGateAuth.
Отвечает на keepalive, а «браузер» имитирует GET-запросом на ссылку из диалога (её понимает
tools/mock-site.py).

Режимы --mode:
  approve  верно решить капчу, подождать --wait секунд, «открыть» ссылку → ждём подключения к SMP
  session  ждём, что диалогов не будет вовсе и нас сразу пустят на SMP
  deny     верно решить капчу, на сайте нажать «Это не я» → ждём кик
  wrong    отвечать на капчу неверно, пока не кикнут
  leave    нажать «Выйти» → ждём кик
  idle     решить капчу и ничего не делать → ждём кик по таймауту
  external решить капчу, напечатать ссылку и ждать: вход подтверждает кто-то снаружи (живой сайт)

Код выхода 0 = сценарий прошёл как ожидалось. Каждое событие печатается строкой «EVENT ...».
"""
import argparse
import io
import json
import re
import socket
import struct
import sys
import time
import urllib.request
import uuid
import zlib

PROTOCOL = 777

# CONFIG, 26.3 (сверено с StateRegistry Velocity 4.3.1)
C_PLUGIN, C_DISCONNECT, C_FINISH, C_KEEPALIVE, C_PING = 0x01, 0x02, 0x03, 0x04, 0x05
C_KNOWN_PACKS, C_DIALOG_CLEAR, C_DIALOG_SHOW = 0x0F, 0x12, 0x13
S_KEEPALIVE, S_PONG, S_CUSTOM_CLICK = 0x04, 0x05, 0x08


def event(*parts):
    print("EVENT", *parts, flush=True)


# --- примитивы протокола ---
def varint(n):
    n &= 0xFFFFFFFF
    out = bytearray()
    while True:
        b = n & 0x7F
        n >>= 7
        if n:
            out.append(b | 0x80)
        else:
            out.append(b)
            return bytes(out)


def read_varint(stream):
    num = shift = 0
    while True:
        raw = stream.read(1)
        if not raw:
            raise EOFError("соединение закрыто")
        b = raw[0]
        num |= (b & 0x7F) << shift
        if not b & 0x80:
            break
        shift += 7
        if shift > 35:
            raise ValueError("varint слишком длинный")
    return num - (1 << 32) if num & (1 << 31) else num


def mc_string(s):
    b = s.encode("utf-8")
    return varint(len(b)) + b


def read_string(buf):
    n = read_varint(buf)
    return buf.read(n).decode("utf-8")


# --- сетевой NBT (без имени корня) ---
def read_nbt(buf):
    t = buf.read(1)[0]
    return read_payload(buf, t)


def read_payload(buf, t):
    if t == 0:
        return None
    if t == 1:
        return struct.unpack(">b", buf.read(1))[0]
    if t == 2:
        return struct.unpack(">h", buf.read(2))[0]
    if t == 3:
        return struct.unpack(">i", buf.read(4))[0]
    if t == 4:
        return struct.unpack(">q", buf.read(8))[0]
    if t == 5:
        return struct.unpack(">f", buf.read(4))[0]
    if t == 6:
        return struct.unpack(">d", buf.read(8))[0]
    if t == 7:
        n = struct.unpack(">i", buf.read(4))[0]
        return buf.read(n)
    if t == 8:
        n = struct.unpack(">H", buf.read(2))[0]
        return buf.read(n).decode("utf-8", "replace")
    if t == 9:
        et = buf.read(1)[0]
        n = struct.unpack(">i", buf.read(4))[0]
        return [read_payload(buf, et) for _ in range(n)]
    if t == 10:
        out = {}
        while True:
            et = buf.read(1)[0]
            if et == 0:
                return out
            name = read_payload(buf, 8)
            out[name] = read_payload(buf, et)
    if t == 11:
        n = struct.unpack(">i", buf.read(4))[0]
        return list(struct.unpack(f">{n}i", buf.read(4 * n)))
    if t == 12:
        n = struct.unpack(">i", buf.read(4))[0]
        return list(struct.unpack(f">{n}q", buf.read(8 * n)))
    raise ValueError(f"неизвестный тег NBT {t}")


def plain(comp):
    """Текст компонента без форматирования."""
    if comp is None:
        return ""
    if isinstance(comp, str):
        return comp
    if isinstance(comp, list):
        return "".join(plain(c) for c in comp)
    if isinstance(comp, dict):
        s = comp.get("text", comp.get("", ""))
        s = s if isinstance(s, str) else plain(s)
        if "translate" in comp:
            s += "{" + comp["translate"] + "}"
        return s + plain(comp.get("extra", []))
    return str(comp)


class Conn:
    def __init__(self, host, port):
        self.sock = socket.create_connection((host, port), timeout=10)
        self.rf = self.sock.makefile("rb")
        self.threshold = -1

    def send(self, pid, payload=b""):
        data = varint(pid) + payload
        if self.threshold >= 0:
            if len(data) >= self.threshold:
                comp = zlib.compress(data)
                body = varint(len(data)) + comp
            else:
                body = varint(0) + data
        else:
            body = data
        self.sock.sendall(varint(len(body)) + body)

    def recv(self, timeout):
        self.sock.settimeout(timeout)
        length = read_varint(self.rf)
        raw = self.rf.read(length)
        buf = io.BytesIO(raw)
        if self.threshold >= 0:
            dlen = read_varint(buf)
            if dlen:
                buf = io.BytesIO(zlib.decompress(buf.read()))
        pid = read_varint(buf)
        return pid, buf


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=25577)
    ap.add_argument("--name", default="Steve")
    ap.add_argument("--mode", default="approve",
                    choices=["approve", "session", "deny", "wrong", "leave", "idle", "external"])
    ap.add_argument("--wait", type=float, default=0, help="сколько секунд «думать» перед кликом по ссылке")
    ap.add_argument("--timeout", type=float, default=60)
    args = ap.parse_args()

    c = Conn(args.host, args.port)
    c.send(0x00, varint(PROTOCOL) + mc_string(args.host) + struct.pack(">H", args.port) + varint(2))
    offline = uuid.uuid3(uuid.NAMESPACE_OID, "OfflinePlayer:" + args.name)
    c.send(0x00, mc_string(args.name) + offline.bytes)

    # LOGIN
    while True:
        pid, buf = c.recv(10)
        if pid == 0x03:
            c.threshold = read_varint(buf)
        elif pid == 0x02:
            event("login-success")
            c.send(0x03)  # Login Acknowledged → CONFIG
            break
        elif pid == 0x00:
            reason = read_string(buf)
            try:
                reason = plain(json.loads(reason))
            except json.JSONDecodeError:
                pass
            event("kicked-at-login", reason)
            return 0 if args.mode in ("wrong",) else 1
        elif pid == 0x01:
            event("encryption-request", "прокси в online-mode, а нужен offline")
            return 1
        elif pid == 0x04:
            mid = read_varint(buf)
            c.send(0x02, varint(mid) + b"\x00")

    deadline = time.time() + args.timeout
    dialogs = 0
    link_at = None
    link_url = None
    keepalives = 0
    while time.time() < deadline:
        try:
            pid, buf = c.recv(max(0.5, deadline - time.time()))
        except (EOFError, ConnectionError):
            event("connection-closed")
            return 1
        except socket.timeout:
            break
        if pid == C_KEEPALIVE:
            ka = buf.read(8)
            c.send(S_KEEPALIVE, ka)
            keepalives += 1
            event("keepalive", keepalives)
        elif pid == C_PING:
            c.send(S_PONG, buf.read(4))
        elif pid == C_DISCONNECT:
            reason = plain(read_nbt(buf))
            event("kicked", reason.replace("\n", " | "))
            expected = {"deny": "отклонён", "wrong": "Капча", "leave": "вышли", "idle": "истекло"}
            want = expected.get(args.mode)
            return 0 if want and want in reason else 1
        elif pid == C_DIALOG_CLEAR:
            event("dialog-clear")
        elif pid == C_DIALOG_SHOW:
            dialogs += 1
            d = read_nbt(buf)
            body = " ".join(plain(b.get("contents")) for b in (d.get("body") or []))
            actions = d.get("actions") or []
            labels = [plain(a.get("label")) for a in actions]
            event("dialog", d.get("type"), "|", plain(d.get("title")), "|", body.replace("\n", " "), "|", labels)
            if args.mode == "session":
                event("unexpected-dialog")
                return 1
            m = re.search(r"(\d+) \+ (\d+)", body)
            if m:  # капча
                if args.mode == "leave":
                    exit_id = d["exit_action"]["action"]["id"]
                    click(c, exit_id)
                    continue
                answer = str(int(m.group(1)) + int(m.group(2)))
                if args.mode == "wrong":
                    idx = next(i for i, l in enumerate(labels) if l != answer)
                else:
                    idx = labels.index(answer)
                click(c, actions[idx]["action"]["id"])
                continue
            url_actions = [a["action"] for a in actions if a.get("action", {}).get("type", "").endswith("open_url")]
            if url_actions:
                link_url = url_actions[0]["url"]
                event("login-link", link_url)
                if args.mode in ("idle", "external"):
                    continue
                link_at = time.time() + args.wait
        elif pid == C_KNOWN_PACKS:
            event("reached-backend", f"dialogs={dialogs}", f"keepalives={keepalives}")
            ok = (args.mode == "session" and dialogs == 0) or (args.mode in ("approve", "external") and dialogs >= 2)
            return 0 if ok else 1
        elif pid == C_PLUGIN:
            pass
        else:
            event("packet", hex(pid))
        if link_at is not None and time.time() >= link_at:
            link_at = None
            suffix = "?deny=1" if args.mode == "deny" else ""
            with urllib.request.urlopen(link_url + suffix, timeout=5) as r:
                event("browser", r.status, r.read().decode())
    event("timeout")
    return 1


def click(c, action_id):
    event("click", action_id)
    c.send(S_CUSTOM_CLICK, mc_string(action_id) + b"\x00")


if __name__ == "__main__":
    sys.exit(main())
