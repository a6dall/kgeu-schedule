#!/usr/bin/env python3
"""
Локальный сервер для расписания КГЭУ.
Всегда отдает файлы без кэширования (Cache-Control: no-cache, no-store, must-revalidate)
для мгновенного применения правок в Safari/Chrome.
Запуск: python3 server.py
"""

import http.server
import socketserver
import urllib.request
import urllib.parse
import json
import os
import re
import webbrowser
from pathlib import Path

PORT = 8000
DIRECTORY = Path(__file__).parent
TODO_FILE = DIRECTORY / "data" / "todo_state.json"
KGEU_API_HOST = "https://kabinet.kgeu.ru"

class NoCacheProxyHTTPRequestHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(DIRECTORY), **kwargs)

    def do_GET(self):
        parsed_path = urllib.parse.urlparse(self.path)

        # Эндпоинт состояния TODO
        if parsed_path.path in ("/api/todo", "/api/todo-state"):
            self.send_todo_state()
            return

        # Проксирование запросов к API КГЭУ
        if parsed_path.path in ("/api/Rasp", "/api/kgeu/Rasp"):
            self.proxy_kgeu_api("/api/Rasp", parsed_path.query)
            return
        elif parsed_path.path in ("/api/GetRaspDates", "/api/kgeu/GetRaspDates"):
            self.proxy_kgeu_api("/api/GetRaspDates", parsed_path.query)
            return

        super().do_GET()

    def do_POST(self):
        parsed_path = urllib.parse.urlparse(self.path)

        if parsed_path.path in ("/api/todo", "/api/todo-state"):
            content_length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(content_length).decode("utf-8")
            try:
                data = json.loads(body)
                current_state = {}
                if TODO_FILE.exists():
                    try:
                        with open(TODO_FILE, "r", encoding="utf-8") as f:
                            current_state = json.load(f)
                    except Exception:
                        current_state = {}

                current_state.update(data)
                with open(TODO_FILE, "w", encoding="utf-8") as f:
                    json.dump(current_state, f, ensure_ascii=False, indent=2)

                self.send_response(200)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Access-Control-Allow-Origin", "*")
                self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
                self.end_headers()
                self.wfile.write(b'{"status":"ok"}')
            except Exception as e:
                self.send_response(500)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Access-Control-Allow-Origin", "*")
                self.end_headers()
                self.wfile.write(json.dumps({"error": str(e)}).encode("utf-8"))
            return

        self.send_response(404)
        self.end_headers()

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()

    def send_todo_state(self):
        state = {}
        if TODO_FILE.exists():
            try:
                with open(TODO_FILE, "r", encoding="utf-8") as f:
                    state = json.load(f)
            except Exception:
                state = {}

        content = json.dumps(state, ensure_ascii=False).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
        self.end_headers()
        self.wfile.write(content)

    def proxy_kgeu_api(self, endpoint, query_string):
        target_url = f"{KGEU_API_HOST}{endpoint}"
        if query_string:
            target_url += f"?{query_string}"

        req = urllib.request.Request(
            target_url,
            headers={
                "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                "Accept": "application/json",
                "Accept-Language": "ru,en;q=0.9",
            }
        )

        try:
            with urllib.request.urlopen(req, timeout=10) as response:
                content = response.read()
                self.send_response(response.status)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Access-Control-Allow-Origin", "*")
                self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
                self.end_headers()
                self.wfile.write(content)
        except Exception as e:
            self.send_response(500)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            error_payload = json.dumps({"error": str(e), "state": 0}).encode("utf-8")
            self.wfile.write(error_payload)

    def end_headers(self):
        # Жесткий запрет кэширования для ВСЕХ статических файлов (html, js, css)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Cache-Control", "no-cache, no-store, must-revalidate, max-age=0")
        self.send_header("Pragma", "no-cache")
        self.send_header("Expires", "0")
        super().end_headers()

def main():
    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer(("", PORT), NoCacheProxyHTTPRequestHandler) as httpd:
        print(f"🚀 Сервер запущен на http://localhost:{PORT} (Кэширование отключено)")
        try:
            webbrowser.open(f"http://localhost:{PORT}")
        except Exception:
            pass
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nСервер остановлен.")

if __name__ == "__main__":
    main()
