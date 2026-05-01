#!/usr/bin/env python3
"""
PulseTerminal v0.3 - Multi-session web terminal
Persistent sessions backed by named tmux sessions, temporary sessions via bare PTY.

Routes:
  GET  /                    → session manager UI
  GET  /terminal?sid=ID     → terminal UI (persistent session)
  GET  /ws/p/{sid}          → WebSocket – persistent session
  GET  /api/sessions        → list persistent sessions (JSON)
  POST /api/sessions        → create persistent session
  DELETE /api/sessions/{sid}→ kill persistent session
"""

import argparse
import asyncio
import fcntl
import json
import os
import pty
import re
import signal
import struct
import subprocess
import sys
import termios

from aiohttp import web

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
SESSION_PREFIX = "pt-"


# ─── tmux helpers ────────────────────────────────────────────────────────────

def _tmux_list():
    """Return list of our tmux sessions as dicts."""
    try:
        r = subprocess.run(
            ["tmux", "list-sessions", "-F",
             "#{session_name}\t#{session_created}\t#{session_windows}"],
            capture_output=True, text=True, timeout=3
        )
        sessions = []
        for line in r.stdout.strip().splitlines():
            if not line:
                continue
            parts = line.split("\t")
            tname = parts[0]
            if not tname.startswith(SESSION_PREFIX):
                continue
            sid = tname[len(SESSION_PREFIX):]
            created = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 0
            windows = int(parts[2]) if len(parts) > 2 and parts[2].isdigit() else 1
            sessions.append({
                "id":      sid,
                "name":    sid,
                "tmux":    tname,
                "created": created,
                "windows": windows,
            })
        return sessions
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return []


def _tmux_ensure(tname):
    """Create tmux session if it doesn't exist. Returns True on success."""
    r = subprocess.run(
        ["tmux", "new-session", "-d", "-s", tname],
        capture_output=True, text=True
    )
    return r.returncode == 0 or "duplicate session" in r.stderr


# ─── PTY bridge ──────────────────────────────────────────────────────────────

async def _pty_bridge(ws, cmd, args, tmux_target=None):
    """
    Spawn cmd with a PTY and bridge I/O with the WebSocket.
    tmux_target: tmux session name (None = temp session).
    """
    pid, fd = pty.fork()
    if pid == 0:
        os.execvp(cmd, args)
        sys.exit(1)

    flags = fcntl.fcntl(fd, fcntl.F_GETFL)
    fcntl.fcntl(fd, fcntl.F_SETFL, flags | os.O_NONBLOCK)

    loop = asyncio.get_event_loop()
    closed = asyncio.Event()

    def on_pty_output():
        try:
            data = os.read(fd, 65536)
            if data:
                asyncio.ensure_future(ws.send_bytes(data))
        except OSError:
            closed.set()
            try:
                loop.remove_reader(fd)
            except Exception:
                pass

    loop.add_reader(fd, on_pty_output)

    async def watch_exit():
        while not closed.is_set():
            try:
                p, _ = os.waitpid(pid, os.WNOHANG)
                if p != 0:
                    closed.set()
                    break
            except ChildProcessError:
                closed.set()
                break
            await asyncio.sleep(0.5)
        if not ws.closed:
            await ws.close()

    watch_task = asyncio.ensure_future(watch_exit())

    try:
        async for msg in ws:
            if msg.type == web.WSMsgType.TEXT:
                ctrl = None
                try:
                    ctrl = json.loads(msg.data)
                except (json.JSONDecodeError, ValueError):
                    pass

                if isinstance(ctrl, dict) and ctrl.get("type") == "resize":
                    cols = ctrl.get("cols", 80)
                    rows = ctrl.get("rows", 24)
                    winsize = struct.pack("HHHH", rows, cols, 0, 0)
                    fcntl.ioctl(fd, termios.TIOCSWINSZ, winsize)
                    try:
                        os.kill(pid, signal.SIGWINCH)
                    except ProcessLookupError:
                        pass
                    continue

                if isinstance(ctrl, dict) and ctrl.get("type") == "set_mouse":
                    if tmux_target:
                        val = "on" if ctrl.get("enabled", True) else "off"
                        try:
                            subprocess.run(
                                ["tmux", "set", "-g", "mouse", val],
                                capture_output=True, timeout=2
                            )
                        except Exception:
                            pass
                    continue

                os.write(fd, msg.data.encode())

            elif msg.type == web.WSMsgType.BINARY:
                os.write(fd, msg.data)

            elif msg.type in (web.WSMsgType.ERROR, web.WSMsgType.CLOSE):
                break
    finally:
        watch_task.cancel()
        try:
            loop.remove_reader(fd)
        except Exception:
            pass
        try:
            os.close(fd)
        except OSError:
            pass
        try:
            os.kill(pid, signal.SIGTERM)
            os.waitpid(pid, 0)
        except (ProcessLookupError, ChildProcessError):
            pass


# ─── WebSocket handlers ──────────────────────────────────────────────────────

async def ws_persistent(request):
    """Attach to a named persistent tmux session."""
    sid = request.match_info["sid"]
    tname = SESSION_PREFIX + sid
    _tmux_ensure(tname)
    ws = web.WebSocketResponse()
    await ws.prepare(request)
    await _pty_bridge(ws, "tmux", ["tmux", "attach-session", "-t", tname],
                      tmux_target=tname)
    return ws


# ─── REST API ────────────────────────────────────────────────────────────────

async def api_sessions_list(request):
    return web.json_response(_tmux_list())


def _sanitize_sid(raw: str) -> str:
    """Lowercase, spaces→-, keep only alphanum/-/_, collapse repeated -, strip edges."""
    s = raw.lower().replace(" ", "-")
    s = re.sub(r"[^a-z0-9_-]", "-", s)
    s = re.sub(r"-{2,}", "-", s).strip("-")
    return s


async def api_sessions_create(request):
    try:
        body = await request.json()
    except Exception:
        body = {}
    raw_name = (body.get("name") or "").strip()
    if not raw_name:
        return web.json_response({"error": "Session name is required."}, status=400)
    sid = _sanitize_sid(raw_name)
    if not sid:
        return web.json_response({"error": "Invalid session name."}, status=400)

    tname = SESSION_PREFIX + sid

    # Check if already exists in tmux
    check = subprocess.run(
        ["tmux", "has-session", "-t", tname],
        capture_output=True
    )
    if check.returncode == 0:
        return web.json_response(
            {"error": f"Session '{sid}' already exists."}, status=409
        )

    r = subprocess.run(
        ["tmux", "new-session", "-d", "-s", tname],
        capture_output=True, text=True
    )
    if r.returncode != 0:
        return web.json_response({"error": r.stderr.strip()}, status=500)

    return web.json_response({"id": sid, "name": sid, "tmux": tname})


async def api_sessions_delete(request):
    sid = request.match_info["sid"]
    tname = SESSION_PREFIX + sid
    subprocess.run(["tmux", "kill-session", "-t", tname], capture_output=True)
    return web.json_response({"ok": True})


# ─── Page handlers ───────────────────────────────────────────────────────────

async def sessions_handler(request):
    html_path = os.path.join(SCRIPT_DIR, "sessions.html")
    with open(html_path) as f:
        return web.Response(text=f.read(), content_type="text/html")


async def terminal_handler(request):
    html_path = os.path.join(SCRIPT_DIR, "terminal.html")
    with open(html_path) as f:
        return web.Response(text=f.read(), content_type="text/html")


# ─── Main ────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="PulseTerminal v0.3 — Multi-session")
    parser.add_argument("-p", "--port", type=int, default=7681)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--shell", default=os.environ.get("SHELL", "/bin/bash"))
    # --tmux kept for backwards compat with start-server scripts (now a no-op)
    parser.add_argument("--tmux", action="store_true", help=argparse.SUPPRESS)
    args = parser.parse_args()

    app = web.Application()
    app["shell"] = args.shell

    # Pages
    app.router.add_get("/",         sessions_handler)
    app.router.add_get("/terminal", terminal_handler)

    # WebSocket
    app.router.add_get("/ws/p/{sid}", ws_persistent)

    # REST API
    app.router.add_get(   "/api/sessions",      api_sessions_list)
    app.router.add_post(  "/api/sessions",      api_sessions_create)
    app.router.add_delete("/api/sessions/{sid}", api_sessions_delete)

    print(f"\n\033[1;36m  PulseTerminal v0.3\033[0m")
    print(f"  ───────────────────────────────────────────────")
    print(f"  Listening : http://{args.host}:{args.port}")
    print(f"  Sessions  : GET  / → manager UI")
    print(f"  Terminal  : GET  /terminal?sid=ID")
    print(f"  ───────────────────────────────────────────────\n")

    web.run_app(app, host=args.host, port=args.port, print=None)


if __name__ == "__main__":
    main()
