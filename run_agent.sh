#!/bin/bash
# Запуск агента на ноде
cd "$(dirname "$0")"
source .venv/bin/activate
cd agent
python3 main.py

