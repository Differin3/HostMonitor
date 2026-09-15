import base64
import sys

import requests


# ВАЖНО:
# 1. Перед запуском отредактируй LOGIN и APP_PASSWORD ниже на свои значения.
# 2. Пароль должен быть именно паролем приложения типа "Файлы • WebDAV".

LOGIN = "54785TGU647"              # логин из Яндекс ID (рядом с паролем приложения)
APP_PASSWORD = "nevuxcfycwcsrkik"  # пароль приложения (Файлы • WebDAV)

WEBDAV_URL = "https://webdav.yandex.ru/"


def basic_auth_header(login: str, password: str) -> str:
    token = f"{login}:{password}".encode("utf-8")
    return "Basic " + base64.b64encode(token).decode("ascii")


def main() -> None:
    headers = {
        "Authorization": basic_auth_header(LOGIN, APP_PASSWORD),
        "Depth": "0",
    }

    print(f"Проверяем PROPFIND {WEBDAV_URL} с Basic-авторизацией...")
    try:
        resp = requests.request(
            "PROPFIND",
            WEBDAV_URL,
            headers=headers,
            timeout=15,
            # Если на Windows нет актуального корневого хранилища, можно отключить проверку.
            # Для чистого теста Яндекс.Диска выставляем verify=False.
            verify=False,
        )
    except Exception as e:
        print(f"Ошибка сети/SSL: {e}")
        sys.exit(1)

    print(f"HTTP статус: {resp.status_code}")
    print("Заголовки ответа:")
    for k, v in resp.headers.items():
        print(f"  {k}: {v}")

    if resp.status_code in (200, 207):
        print("\nУСПЕХ: авторизация прошла, WebDAV работает.")
    elif resp.status_code == 401:
        print("\n401 Unauthorized: логин/пароль приложения не приняты Яндексом.")
        print("Проверь, что пароль приложения создан для этого аккаунта и не отозван.")
    else:
        print(f"\nНеожиданный код: {resp.status_code}")
        print("Тело ответа (первые 500 символов):")
        print(resp.text[:500])


if __name__ == "__main__":
    main()

