#!/bin/bash
# Запуск агента на ноде вручную
# Использование: ./run_agent.sh

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV_PATH="${SCRIPT_DIR}/.venv"
AGENT_DIR="${SCRIPT_DIR}/agent"

# Проверяем наличие виртуального окружения
if [ ! -d "$VENV_PATH" ]; then
    echo "Ошибка: виртуальное окружение не найдено в $VENV_PATH"
    echo "Создайте его: python3 -m venv .venv"
    exit 1
fi

# Активируем виртуальное окружение
source "${VENV_PATH}/bin/activate"

# Переходим в директорию агента
cd "$AGENT_DIR" || exit 1

# Проверяем наличие main.py
if [ ! -f "main.py" ]; then
    echo "Ошибка: main.py не найден в $AGENT_DIR"
    exit 1
fi

# Проверяем наличие node.conf
if [ ! -f "node.conf" ]; then
    echo "Предупреждение: node.conf не найден в $AGENT_DIR"
    echo "Агент попытается найти конфиг в других местах"
fi

echo "Запуск агента из: $AGENT_DIR"
echo "Виртуальное окружение: $VENV_PATH"
echo "---"
python3 main.py

