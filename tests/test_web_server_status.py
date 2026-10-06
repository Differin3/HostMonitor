#!/usr/bin/env python3
"""Разбор CGI-заголовков в scripts/python_web_server.py.

Главная регрессия: строка «Status: 405 Method Not Allowed» не распознавалась —
проверялось, что весь хвост после номера состоит из цифр, а php-cgi всегда
печатает reason-фразу. Все ошибки API (401/403/429/500) уходили клиенту как
200: агент считал отказ успешной отправкой, фронтенд не видел !res.ok.
"""

import importlib.util
import os
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "python_web_server.py"

# Модуль при импорте завершается, если php-cgi не найден, — подменяем путь,
# чтобы тест не зависел от окружения.
os.environ.setdefault("PHP_CGI", "/bin/true")

_spec = importlib.util.spec_from_file_location("python_web_server", SCRIPT)
mod = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(mod)


class ParseCgiStatusTest(unittest.TestCase):
    def test_status_with_reason_phrase(self):
        # главная регрессия: именно так php-cgi и печатает
        self.assertEqual(mod._parse_cgi_status("Status: 405 Method Not Allowed"), 405)

    def test_status_without_reason_phrase(self):
        self.assertEqual(mod._parse_cgi_status("Status: 401"), 401)

    def test_rate_limit(self):
        self.assertEqual(mod._parse_cgi_status("Status: 429 Too Many Requests"), 429)

    def test_not_a_status_line(self):
        self.assertIsNone(mod._parse_cgi_status("Content-Type: text/html"))

    def test_empty_value(self):
        self.assertIsNone(mod._parse_cgi_status("Status:"))
        self.assertIsNone(mod._parse_cgi_status("Status:   "))

    def test_non_numeric(self):
        self.assertIsNone(mod._parse_cgi_status("Status: oops"))


class ParseCgiHeadersTest(unittest.TestCase):
    @staticmethod
    def parse(blob):
        return mod._parse_cgi_headers(blob)

    def test_error_status_propagates(self):
        status, headers, location, cookies = self.parse(
            b"Status: 401 Unauthorized\r\nContent-Type: application/json\r\n\r\n"
        )
        self.assertEqual(status, 401)
        self.assertIsNone(location)
        self.assertEqual(cookies, [])
        self.assertIn(("Content-Type", "application/json"), headers)

    def test_no_status_defaults_to_200(self):
        status, *_ = self.parse(b"Content-Type: text/html\r\n\r\n")
        self.assertEqual(status, 200)

    def test_location_forces_302(self):
        status, headers, location, _ = self.parse(b"Location: /login.php\r\n\r\n")
        self.assertEqual(status, 302)
        self.assertEqual(location, "/login.php")
        self.assertIn(("Location", "/login.php"), headers)

    def test_explicit_error_status_wins_over_location(self):
        status, _, location, _ = self.parse(
            b"Status: 401 Unauthorized\r\nLocation: /login.php\r\n"
        )
        self.assertEqual(status, 401)
        self.assertEqual(location, "/login.php")

    def test_set_cookie_kept_in_headers_and_listed(self):
        status, headers, _, cookies = self.parse(
            b"Status: 405 Method Not Allowed\r\n"
            b"Set-Cookie: PHPSESSID=abc; path=/\r\n"
        )
        self.assertEqual(status, 405)
        self.assertEqual(cookies, ["PHPSESSID=abc; path=/"])
        self.assertIn(("Set-Cookie", "PHPSESSID=abc; path=/"), headers)

    def test_lf_only_separators(self):
        status, *_ = self.parse(b"Status: 403 Forbidden\nContent-Type: text/plain\n")
        self.assertEqual(status, 403)

    def test_preserves_header_order(self):
        _, headers, _, _ = self.parse(
            b"Content-Type: a\r\nSet-Cookie: b=1\r\nLocation: /c\r\n"
        )
        self.assertEqual(
            [key for key, _ in headers], ["Content-Type", "Set-Cookie", "Location"]
        )


class CallSiteTest(unittest.TestCase):
    """Оба места, где раньше разбирали заголовки, пользуются общим парсером."""

    def test_both_call_sites_use_helper(self):
        src = SCRIPT.read_text(encoding="utf-8")
        # определение + два вызова (_send_php_headers и основной путь)
        self.assertGreaterEqual(src.count("_parse_cgi_headers("), 3)

    def test_old_digit_only_check_is_gone(self):
        src = SCRIPT.read_text(encoding="utf-8")
        self.assertNotIn("parts[1].strip().isdigit()", src)


if __name__ == "__main__":
    unittest.main()
