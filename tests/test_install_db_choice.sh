#!/usr/bin/env bash
# Регрессия на выбор ветки БД в install.sh.
#
# Раньше режим определялся догадкой «задан DB_HOST → удалённая БД».
# Локальная установка из install_panel.sh передаёт DB_HOST=localhost,
# поэтому база и пользователь не создавались, а скрипт рапортовал
# об успешной установке. Режим теперь передаётся явно через
# DB_INSTALL_LOCAL, и он обязан проверяться ДО эвристики по DB_HOST.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_SH="${ROOT}/install.sh"
PANEL_SH="${ROOT}/scripts/install_panel.sh"

fails=0
pass() { echo "  ok   - $1"; }
fail() { echo "  FAIL - $1"; fails=$((fails + 1)); }

# Вырезаем блок принятия решения: от USE_LOCAL_DB="" до закрывающего fi.
decision_block() {
    awk '/^USE_LOCAL_DB=""$/{f=1} f{print} f&&/^fi$/{exit}' "$INSTALL_SH"
}

BLOCK_FILE="$(mktemp)"
trap 'rm -f "$BLOCK_FILE"' EXIT
decision_block > "$BLOCK_FILE"

# Печатает USE_LOCAL_DB для заданного окружения.
# Код берётся из файла, чтобы stdin остался свободным для read.
resolve() {
    env "$@" HM_BLOCK="$BLOCK_FILE" bash -c \
        'eval "$(cat "$HM_BLOCK")"; printf "%s" "${USE_LOCAL_DB:-}"' 2>/dev/null | tail -1
}

# resolve_stdin <ответ> — то же, но с ответом на stdin.
resolve_stdin() {
    local answer="$1"; shift
    printf '%s\n' "$answer" | env "$@" HM_BLOCK="$BLOCK_FILE" bash -c \
        'eval "$(cat "$HM_BLOCK")"; printf "%s" "${USE_LOCAL_DB:-}"' 2>&1 | tail -1
}

expect() {
    local desc="$1" want="$2"; shift 2
    local got
    got="$(resolve "$@")"
    if [[ "$got" == "$want" ]]; then
        pass "$desc"
    else
        fail "$desc (ожидалось $want, получено ${got:-пусто})"
    fi
}

echo "install: выбор режима БД"

expect "локальная БД из install_panel.sh"        true  DB_INSTALL_LOCAL=1 DB_HOST=localhost DB_PASSWORD=x
expect "локальная БД, хост задан явно"            true  DB_INSTALL_LOCAL=1 DB_HOST=127.0.0.1 DB_PASSWORD=x
expect "удалённая БД из install_panel.sh"         false DB_INSTALL_LOCAL=0 DB_HOST=db.example.com DB_PASSWORD=x

got="$(resolve_stdin 1 DB_PASSWORD= DB_HOST=)"
if [[ "$got" == "true" ]]; then pass "прямой запуск, ответ 1 (локальная)"; else fail "прямой запуск, ответ 1 (ожидалось true, получено ${got:-пусто})"; fi

got="$(resolve_stdin 2 DB_PASSWORD= DB_HOST=)"
if [[ "$got" == "false" ]]; then pass "прямой запуск, ответ 2 (удалённая)"; else fail "прямой запуск, ответ 2 (ожидалось false, получено ${got:-пусто})"; fi

# Отказ на недопустимый ввод: установка не должна продолжаться наугад.
got="$(resolve_stdin 9 DB_PASSWORD= DB_HOST=)"
if [[ "$got" == *"Неверный выбор"* ]]; then
    pass "недопустимый ввод отклоняется"
else
    fail "недопустимый ввод не отклонён (получено: ${got:-пусто})"
fi

# Режим обязан определяться до эвристики по DB_HOST, иначе локальная
# установка снова уедет в ветку удалённой БД.
hm_line=$(grep -n 'DB_INSTALL_LOCAL:-' "$INSTALL_SH" | head -1 | cut -d: -f1)
heur_line=$(grep -n 'DB_PASSWORD:-.*-z.*DB_HOST' "$INSTALL_SH" | head -1 | cut -d: -f1)
if [[ -n "$hm_line" && -n "$heur_line" && "$hm_line" -lt "$heur_line" ]]; then
    pass "DB_INSTALL_LOCAL проверяется раньше эвристики по DB_HOST"
else
    fail "порядок нарушен: DB_INSTALL_LOCAL=${hm_line:-?}, эвристика=${heur_line:-?}"
fi

# Установщик обязан передавать режим дальше и проверять подключение к БД,
# иначе неудачная установка снова отрапортует об успехе.
if grep -q 'DB_INSTALL_LOCAL=\$DB_INSTALL_LOCAL' "$PANEL_SH"; then
    pass "install_panel.sh передаёт DB_INSTALL_LOCAL в install.sh"
else
    fail "install_panel.sh не передаёт DB_INSTALL_LOCAL"
fi

if grep -q 'Проверка подключения к БД' "$PANEL_SH" && \
   grep -q 'нет подключения к БД' "$PANEL_SH"; then
    pass "install_panel.sh проверяет подключение к БД и падает при обрыве"
else
    fail "install_panel.sh не проверяет подключение к БД"
fi

# Пароль не должен оставаться незаданным в локальной ветке.
if grep -q 'openssl rand -hex 16' "$PANEL_SH"; then
    pass "пароль БД генерируется в локальной ветке"
else
    fail "пароль БД не генерируется в локальной ветке"
fi

if [[ $fails -eq 0 ]]; then
    echo "install: все проверки пройдены"
else
    echo "install: провалено проверок: $fails"
    exit 1
fi
