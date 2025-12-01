#!/usr/bin/env python3
"""
Мини-сервер на Python для обслуживания PHP-интерфейса без nginx.
- HTTP сервер: ThreadingHTTPServer
- PHP обработка: php-cgi
- Статика /frontend монтируется из соседнего каталога
"""

import argparse
import os
import shutil
import subprocess
import sys
import urllib.parse
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[1]
WEB_ROOT = PROJECT_ROOT / "monitoring"
FRONTEND_ROOT = PROJECT_ROOT / "frontend"
PHP_CGI = shutil.which(os.environ.get("PHP_CGI", "php-cgi"))

if PHP_CGI is None:
    sys.stderr.write("php-cgi не найден. Установите пакет php-cgi.\n")
    sys.exit(1)


class PHPRequestHandler(SimpleHTTPRequestHandler):
    extensions_map = {
        **SimpleHTTPRequestHandler.extensions_map,
        ".php": "text/html",
    }

    def translate_path(self, path: str) -> str:
        """Маршрутизация /frontend -> отдельная папка."""
        parsed = urllib.parse.urlsplit(path)
        clean_path = urllib.parse.unquote(parsed.path.lstrip("/"))
        base = WEB_ROOT

        if parsed.path.startswith("/frontend/"):
            rel = parsed.path[len("/frontend/") :]
            return str(FRONTEND_ROOT / rel)

        if clean_path == "":
            clean_path = "index.php"
        return str(base / clean_path)

    def do_GET(self):
        if self.path.endswith(".php") or ".php?" in self.path or self.path == "/":
            return self._handle_php()
        return super().do_GET()

    def do_POST(self):
        return self._handle_php()

    def _handle_php(self):
        parsed = urllib.parse.urlsplit(self.path)
        script_path = self.translate_path(parsed.path)
        if not os.path.exists(script_path):
            self.send_error(404, "PHP файл не найден")
            return

        body = b""
        length = int(self.headers.get("Content-Length", 0))
        if length:
            body = self.rfile.read(length)

        env = os.environ.copy()
        env.update(
            {
                "GATEWAY_INTERFACE": "CGI/1.1",
                "REQUEST_METHOD": self.command,
                "QUERY_STRING": parsed.query,
                "CONTENT_TYPE": self.headers.get("Content-Type", ""),
                "CONTENT_LENGTH": str(len(body)),
                "SCRIPT_FILENAME": script_path,
                "SCRIPT_NAME": parsed.path,
                "REQUEST_URI": self.path,
                "DOCUMENT_ROOT": str(WEB_ROOT),
                "REMOTE_ADDR": self.client_address[0],
                "SERVER_NAME": self.server.server_address[0],
                "SERVER_PORT": str(self.server.server_address[1]),
                "SERVER_PROTOCOL": self.protocol_version,
            }
        )

        proc = subprocess.Popen(
            [PHP_CGI, "-q"],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            cwd=str(WEB_ROOT),
        )
        stdout, stderr = proc.communicate(body)

        if proc.returncode != 0:
            self.send_response(500)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.end_headers()
            self.wfile.write(
                f"PHP error ({proc.returncode}):\n{stderr.decode(errors='ignore')}".encode(
                    "utf-8", "ignore"
                )
            )
            return

        header_blob, _, payload = stdout.partition(b"\r\n\r\n")
        header_lines = header_blob.decode("iso-8859-1").split("\r\n")

        status_code = 200
        response_headers = []
        for line in header_lines:
            if not line:
                continue
            if line.lower().startswith("status:"):
                parts = line.split(" ", 1)
                if len(parts) == 2 and parts[1].strip().isdigit():
                    status_code = int(parts[1].strip())
                continue
            if ":" in line:
                key, value = line.split(":", 1)
                response_headers.append((key.strip(), value.strip()))

        self.send_response(status_code)
        for key, value in response_headers:
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(payload)


def main():
    # Читаем порт из переменной окружения или используем значение по умолчанию
    default_port = int(os.environ.get("WEB_PORT", "8080"))
    
    parser = argparse.ArgumentParser(
        description="Простой Python HTTP сервер для каталога monitoring/"
    )
    parser.add_argument("--host", default=os.environ.get("WEB_HOST", "0.0.0.0"), help="Адрес прослушивания (default: 0.0.0.0 или из WEB_HOST)")
    parser.add_argument("--port", type=int, default=default_port, help=f"Порт (default: {default_port} или из WEB_PORT)")
    args = parser.parse_args()

    os.chdir(WEB_ROOT)
    server = ThreadingHTTPServer((args.host, args.port), PHPRequestHandler)

    print(f"Python веб-сервер запущен: http://{args.host}:{args.port}")
    print(f"Документ-рут: {WEB_ROOT}")
    print(f"php-cgi: {PHP_CGI}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nОстановка сервера...")
    finally:
        server.server_close()


if __name__ == "__main__":
    main()

