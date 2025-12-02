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
# Пробуем найти php-cgi в разных местах
PHP_CGI = os.environ.get("PHP_CGI")
if not PHP_CGI:
    # Сначала проверяем стандартные пути (быстрее и надежнее)
    for path in ["/usr/bin/php-cgi", "/usr/local/bin/php-cgi", "/bin/php-cgi"]:
        if os.path.exists(path) and os.access(path, os.X_OK):
            PHP_CGI = path
            break
    # Если не нашли, пробуем через which
    if not PHP_CGI:
        PHP_CGI = shutil.which("php-cgi")

if PHP_CGI is None or not os.path.exists(PHP_CGI):
    sys.stderr.write("ОШИБКА: php-cgi не найден. Установите пакет php-cgi.\n")
    sys.stderr.write(f"Проверка PATH: {os.environ.get('PATH', 'не установлен')}\n")
    sys.stderr.write("Проверка стандартных путей: /usr/bin/php-cgi, /usr/local/bin/php-cgi\n")
    sys.exit(1)

# Логируем найденный путь (для отладки)
if os.environ.get("DEBUG"):
    sys.stderr.write(f"Используется php-cgi: {PHP_CGI}\n")

# Проверяем что директории существуют
if not WEB_ROOT.exists():
    sys.stderr.write(f"ОШИБКА: Директория {WEB_ROOT} не найдена.\n")
    sys.exit(1)

if not FRONTEND_ROOT.exists():
    sys.stderr.write(f"ПРЕДУПРЕЖДЕНИЕ: Директория {FRONTEND_ROOT} не найдена.\n")


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
    
    def do_PUT(self):
        return self._handle_php()
    
    def do_DELETE(self):
        return self._handle_php()
    
    def do_PATCH(self):
        return self._handle_php()
    
    def do_OPTIONS(self):
        # Обрабатываем OPTIONS для CORS
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, PATCH, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.send_header("Access-Control-Max-Age", "3600")
        self.end_headers()
        return
    
    def do_HEAD(self):
        # HEAD запросы обрабатываем как GET, но без тела ответа
        if self.path.endswith(".php") or ".php?" in self.path or self.path == "/":
            return self._handle_php()
        return super().do_HEAD()

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
        # Передаем все HTTP заголовки в переменные окружения для PHP
        http_headers = {}
        for header_name, header_value in self.headers.items():
            # Преобразуем имя заголовка в формат HTTP_* для PHP
            env_name = "HTTP_" + header_name.upper().replace("-", "_")
            http_headers[env_name] = header_value
        
        env.update(
            {
                "GATEWAY_INTERFACE": "CGI/1.1",
                "REQUEST_METHOD": self.command,
                "QUERY_STRING": parsed.query or "",
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
                "REDIRECT_STATUS": "200",  # Нужно для некоторых PHP конфигураций
                "PHP_SELF": parsed.path,
                "HTTP_HOST": f"{self.server.server_address[0]}:{self.server.server_address[1]}",
                "SERVER_SOFTWARE": "Python/HTTP",
                "HTTP_COOKIE": self.headers.get("Cookie", ""),  # Передаем Cookie в PHP
            }
        )
        # Добавляем все HTTP заголовки
        env.update(http_headers)

        try:
            proc = subprocess.Popen(
                [PHP_CGI, "-q"],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=env,
                cwd=str(WEB_ROOT),
            )
        except Exception as e:
            self.send_error(500, f"Ошибка запуска PHP: {e}")
            return
        stdout, stderr = proc.communicate(body)

        if proc.returncode != 0:
            # Логируем ошибку в stderr для systemd journal
            error_msg = stderr.decode(errors='ignore') if stderr else "Нет сообщения об ошибке"
            stdout_preview = stdout[:500].decode(errors='ignore') if stdout else "Нет вывода"
            sys.stderr.write(f"PHP ошибка (код {proc.returncode}): {error_msg}\n")
            sys.stderr.write(f"PHP путь: {PHP_CGI}\n")
            sys.stderr.write(f"Скрипт: {script_path}\n")
            sys.stderr.write(f"Существует: {os.path.exists(script_path)}\n")
            sys.stderr.write(f"STDOUT (первые 500 символов): {stdout_preview}\n")
            
            self.send_response(500)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.end_headers()
            self.wfile.write(
                f"Ошибка PHP ({proc.returncode}):\n{error_msg}\n\nSTDOUT:\n{stdout_preview}".encode("utf-8", "ignore")
            )
            return

        if not stdout:
            sys.stderr.write(f"ПРЕДУПРЕЖДЕНИЕ: PHP вернул пустой вывод для {script_path}\n")
            self.send_error(500, "PHP вернул пустой вывод")
            return

        # Ищем разделитель заголовков (может быть \r\n\r\n или \n\n)
        if b"\r\n\r\n" in stdout:
            header_blob, _, payload = stdout.partition(b"\r\n\r\n")
        elif b"\n\n" in stdout:
            header_blob, _, payload = stdout.partition(b"\n\n")
        else:
            # Если нет разделителя, возможно весь вывод - это заголовки или ошибка
            if os.environ.get("DEBUG"):
                sys.stderr.write(f"ПРЕДУПРЕЖДЕНИЕ: Не найден разделитель заголовков в выводе PHP для {script_path}\n")
                sys.stderr.write(f"Размер вывода: {len(stdout)} байт\n")
                sys.stderr.write(f"Первые 500 символов вывода: {stdout[:500].decode(errors='ignore')}\n")
            # Пробуем найти Content-Type в выводе
            if b"Content-Type:" in stdout[:500]:
                # Возможно заголовки без разделителя - ищем первый перенос строки после заголовков
                header_end = stdout.find(b"\n", stdout.find(b"Content-Type:"))
                if header_end > 0:
                    header_blob = stdout[:header_end+1]
                    payload = stdout[header_end+1:]
                else:
                    header_blob = stdout
                    payload = b""
            else:
                # Возможно весь вывод - это тело ответа без заголовков
                header_blob = b"Content-Type: text/html; charset=utf-8\r\n"
                payload = stdout
        
        try:
            header_lines = header_blob.decode("iso-8859-1").split("\r\n")
        except:
            header_lines = header_blob.decode("utf-8", errors='ignore').split("\n")
        
        # Логируем только ошибки (DEBUG отключен для экономии места)
        if os.environ.get("DEBUG"):
            sys.stderr.write(f"DEBUG: Размер payload: {len(payload)} байт\n")
            if len(payload) > 0:
                payload_preview = payload[:500].decode(errors='ignore')
                sys.stderr.write(f"DEBUG: Первые 500 символов payload: {payload_preview[:500]}\n")
                if not payload_preview.strip().startswith('<!DOCTYPE') and not payload_preview.strip().startswith('<html'):
                    sys.stderr.write(f"ПРЕДУПРЕЖДЕНИЕ: Тело ответа не начинается с HTML для {script_path}\n")
            else:
                sys.stderr.write(f"DEBUG: Payload пустой!\n")

        status_code = 200
        response_headers = []
        location_header = None
        set_cookie_headers = []
        for line in header_lines:
            if not line.strip():
                continue
            if line.lower().startswith("status:"):
                parts = line.split(" ", 1)
                if len(parts) == 2 and parts[1].strip().isdigit():
                    status_code = int(parts[1].strip())
                continue
            if ":" in line:
                key, value = line.split(":", 1)
                key = key.strip()
                value = value.strip()
                response_headers.append((key, value))
                if key.lower() == "location":
                    location_header = value
                elif key.lower() == "set-cookie":
                    set_cookie_headers.append(value)
                    if os.environ.get("DEBUG"):
                        sys.stderr.write(f"DEBUG: Set-Cookie заголовок: {value[:100]}\n")
        
        # Если есть Location заголовок - это редирект, нужно установить правильный статус код
        if location_header:
            # PHP обычно отправляет редирект со статусом 200, но нужно 302
            if status_code == 200:
                status_code = 302  # Found - временный редирект
            sys.stderr.write(f"Редирект на: {location_header} (статус: {status_code})\n")
        elif len(payload) == 0 and status_code == 200:
            # Если тело пустое, но статус 200 - это проблема
            if os.environ.get("DEBUG"):
                sys.stderr.write(f"ПРЕДУПРЕЖДЕНИЕ: Пустое тело ответа при статусе 200 для {script_path}\n")
                sys.stderr.write(f"Заголовки: {response_headers}\n")
                sys.stderr.write(f"Размер header_blob: {len(header_blob)} байт\n")
                sys.stderr.write(f"Первые 200 символов header_blob: {header_blob[:200].decode(errors='ignore')}\n")

        self.send_response(status_code)
        # Важно: Set-Cookie заголовки должны быть отправлены первыми
        for key, value in response_headers:
            if key.lower() == "set-cookie":
                self.send_header(key, value)
        # Остальные заголовки
        for key, value in response_headers:
            if key.lower() != "set-cookie":
                self.send_header(key, value)
        self.end_headers()
        
        # Если тело пустое, но это не редирект - отправляем минимальный HTML для диагностики
        if len(payload) == 0 and status_code == 200 and not location_header:
            sys.stderr.write(f"ОШИБКА: Пустое тело ответа при статусе 200 для {script_path}\n")
            payload = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Error</title></head><body><h1>Empty response</h1><p>Script returned no content. Check server logs.</p></body></html>".encode('utf-8')
        
        # Для редиректа тело может быть пустым - это нормально
        if payload:
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

    # Проверяем что можем перейти в директорию
    if not WEB_ROOT.exists():
        sys.stderr.write(f"ОШИБКА: Директория {WEB_ROOT} не существует\n")
        sys.exit(1)
    
    try:
        os.chdir(WEB_ROOT)
    except Exception as e:
        sys.stderr.write(f"ОШИБКА: Не удалось перейти в директорию {WEB_ROOT}: {e}\n")
        sys.exit(1)
    
    # Проверяем что можем создать сервер
    try:
        server = ThreadingHTTPServer((args.host, args.port), PHPRequestHandler)
    except OSError as e:
        sys.stderr.write(f"ОШИБКА: Не удалось запустить сервер на {args.host}:{args.port}: {e}\n")
        sys.stderr.write("Возможные причины: порт занят или нет прав доступа\n")
        sys.exit(1)
    except Exception as e:
        sys.stderr.write(f"ОШИБКА: Неожиданная ошибка при запуске сервера: {e}\n")
        sys.exit(1)

    print(f"Python веб-сервер запущен: http://{args.host}:{args.port}")
    print(f"Документ-рут: {WEB_ROOT}")
    print(f"php-cgi: {PHP_CGI}")
    sys.stdout.flush()  # Принудительно выводим сообщения для systemd
    
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nОстановка сервера...")
    except Exception as e:
        sys.stderr.write(f"ОШИБКА во время работы сервера: {e}\n")
        sys.exit(1)
    finally:
        server.server_close()


if __name__ == "__main__":
    main()

