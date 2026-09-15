#!/bin/bash
# Запуск главного сервера
cd "$(dirname "$0")"
source .venv/bin/activate
cd master/api
python3 main.py

