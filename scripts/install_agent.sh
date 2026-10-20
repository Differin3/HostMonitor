#!/bin/bash
# Установка агента HostMonitor на ноде (Linux/FreeBSD). База данных агенту не нужна.
# Поддержка: Debian/Ubuntu, CentOS/RHEL/Fedora/Rocky/Alma, Arch, FreeBSD (TrueNAS Core/Scale)
#
# Использование:
#   install_agent.sh [REPO_URL] [BRANCH] [INSTALL_DIR]
#   install_agent.sh --enroll <КОД> --master <URL> [--node-name <ИМЯ>] --force
#
#   --enroll <КОД>   обменять одноразовый код из панели («Подключить ноду (Enroll)»)
#                    на Ed25519-ключ и создать node.conf БЕЗ токена;
#   --master <URL>   URL панели для enroll (https://panel/ или https://panel/monitoring);
#   --node-name <ИМЯ> имя ноды, если не определять из hostname;
#   --node-id <ID>   принудительный node_id для существующей ноды (перекрывает enroll);
#   --force          пересоздать node.conf, даже если он уже есть;
#   --fix-url        обновить только MASTER_URL в существующем node.conf;
#   -h|--help        показать справку.
set -euo pipefail
install_agent_on_error() {
    local rc=$?
    echo "[install_agent] ОШИБКА: код ${rc}, строка ${1}. Смотрите сообщение выше." >&2
}
trap 'install_agent_on_error ${LINENO}' ERR

# ─── Разбор опций ─────────────────────────────────────────────────────────────
POS_ARGS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --enroll)
            if [[ -z "${2:-}" ]]; then
                echo "[install_agent] ERROR: --enroll требует одноразовый код из панели" >&2
                exit 1
            fi
            ENROLL_TOKEN="$2"; shift 2 ;;
        --master)     MASTER_URL="${2:-}"; shift 2 ;;
        --node-name)  NODE_NAME="${2:-}"; shift 2 ;;
        --node-id)    NODE_ID="${2:-}"; shift 2 ;;
        --force)      FORCE=true; shift ;;
        --fix-url)    FIX_URL=true; shift ;;
        -h|--help)
            sed -n '2,14p' "$0"
            exit 0 ;;
        *) POS_ARGS+=("$1"); shift ;;
    esac
done

echo "[install_agent] Старт установки агента HostMonitor"

REPO_URL="${POS_ARGS[0]:-https://github.com/Differin3/HostMonitor}"
BRANCH="${POS_ARGS[1]:-main}"
INSTALL_DIR="${POS_ARGS[2]:-/opt/monitoring}"

# Случайный SNMP community вместо "public": соседние устройства в сети не
# должны опрашиваться по стандартному community чужими.
# Источник ограничен head -c: `tr </dev/urandom | head -c` даёт SIGPIPE и под
# set -o pipefail молча завершает установку ещё до первого вывода.
SNMP_COMMUNITY_VALUE="$(head -c 24 /dev/urandom | od -An -v -tx1 | tr -d ' \n' | cut -c1-16)"
SNMP_COMMUNITY_VALUE="${SNMP_COMMUNITY_VALUE:-hostmonitor$(date +%s | tail -c 5)}"

# ─── Детекция платформы (ОС + специализированная система) ─────────────────────
IS_TRUENAS=false
IS_PROXMOX=false
IS_SYNOLOGY=false
IS_FREEBSD=false
IS_QNAP=false

detect_specialized_system() {
    # TrueNAS
    if [[ -f /etc/truenas || -f /data/truenas-version || -f /etc/platform ]]; then
        IS_TRUENAS=true
        echo "[install_agent] Обнаружен TrueNAS"
    fi
    if command -v midclt &>/dev/null; then
        IS_TRUENAS=true
        echo "[install_agent] Обнаружен TrueNAS (midclt найден)"
    fi

    # Proxmox
    if command -v pveversion &>/dev/null || [[ -d /etc/pve ]]; then
        IS_PROXMOX=true
        echo "[install_agent] Обнаружен Proxmox"
    fi

    # Synology DSM
    if [[ -f /etc/synoinfo.conf || -f /usr/syno/sbin/synouser ]]; then
        IS_SYNOLOGY=true
        echo "[install_agent] Обнаружен Synology DSM"
    fi

    # QNAP
    if [[ -f /etc/config/qpkg.conf || -d /share/CACHEDEV1_DATA ]]; then
        IS_QNAP=true
        echo "[install_agent] Обнаружен QNAP"
    fi

    # FreeBSD (TrueNAS Core и другие)
    if uname -s 2>/dev/null | grep -qi 'freebsd'; then
        IS_FREEBSD=true
        echo "[install_agent] Обнаружен FreeBSD"
    fi
}

# ─── Детекция дистрибутива ────────────────────────────────────────────────────
detect_distro() {
    # FreeBSD
    if $IS_FREEBSD; then
        PKG_UPDATE="sudo pkg update -f"
        PKG_INSTALL="sudo pkg install -y"
        PKG_VENV=""
        PKG_PIPX=""
        PKG_LIBPCAP="libpcap"
        PKG_SCAPY=""
        PKG_EXTRA="net-snmp"
        DISTRO_FAMILY="freebsd"
        INSTALL_DIR="${INSTALL_DIR:-/opt/monitoring}"
        echo "[install_agent] FreeBSD: используем pkg"
        return
    fi

    # Linux
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        _ID="${ID:-unknown}"
        _ID_LIKE="${ID_LIKE:-}"
        _FAMILY="$_ID $_ID_LIKE"
    elif [[ -f /etc/redhat-release ]]; then
        _FAMILY="rhel"
        _ID="rhel"
    else
        _FAMILY="unknown"
        _ID="unknown"
    fi

    # TrueNAS Scale基于 Debian
    if $IS_TRUENAS; then
        _FAMILY="debian $_FAMILY"
        echo "[install_agent] TrueNAS Scale: используем apt (Debian-based)"
    fi

    case "$_FAMILY" in
        *debian*|*ubuntu*)
            PKG_UPDATE="sudo apt-get update -y"
            PKG_INSTALL="sudo DEBIAN_FRONTEND=noninteractive apt-get install -y"
            PKG_VENV="python3-venv"
            PKG_PIPX="pipx"
            PKG_LIBPCAP="libpcap0.8"
            PKG_SCAPY="python3-scapy"
            PKG_EXTRA="snmp"
            DISTRO_FAMILY="debian"
            ;;
        *rhel*|*centos*|*fedora*|*rocky*|*alma*)
            if command -v dnf &>/dev/null; then
                PKG_UPDATE="sudo dnf makecache -y"
                PKG_INSTALL="sudo dnf install -y"
            else
                PKG_UPDATE="sudo yum makecache -y"
                PKG_INSTALL="sudo yum install -y"
            fi
            PKG_VENV=""
            PKG_PIPX=""
            PKG_LIBPCAP="libpcap"
            PKG_SCAPY=""
            PKG_EXTRA="net-snmp-utils"
            DISTRO_FAMILY="rhel"
            ;;
        *arch*|*manjaro*)
            PKG_UPDATE="sudo pacman -Sy --noconfirm"
            PKG_INSTALL="sudo pacman -S --noconfirm"
            PKG_VENV=""
            PKG_PIPX=""
            PKG_LIBPCAP="libpcap"
            PKG_SCAPY="python-scapy"
            PKG_EXTRA="net-snmp"
            DISTRO_FAMILY="arch"
            ;;
        *)
            if $IS_SYNOLOGY; then
                echo "[install_agent] Synology DSM: не поддерживается автоматическая установка."
                echo "[install_agent] Установите Python3 через Synology Package Center."
                echo "[install_agent] Затем запустите скрипт вручную."
                exit 1
            fi
            if $IS_QNAP; then
                echo "[install_agent] QNAP: не поддерживается автоматическая установка."
                echo "[install_agent] Установите Python3 через App Center."
                exit 1
            fi
            echo "[install_agent] ОШИБКА: неподдерживаемый дистрибутив: $_ID"
            echo "[install_agent] Поддерживаются: Debian/Ubuntu, CentOS/RHEL/Fedora/Rocky/Alma, Arch, FreeBSD"
            exit 1
            ;;
    esac
    echo "[install_agent] Дистрибутив: $_ID (семейство: $DISTRO_FAMILY)"
}

# Запуск детекции
detect_specialized_system
detect_distro

if $IS_TRUENAS && ! $IS_FREEBSD; then
    ROOT_READONLY=false
    if ! command -v apt-get &>/dev/null; then
        ROOT_READONLY=true
    # install-dev-tools снимает readonly с /usr и /opt; '/' у TrueNAS остаётся ro.
    elif awk '$2 == "/usr" || $2 == "/opt" { print $4 }' /proc/mounts 2>/dev/null | tr ',' '\n' | grep -qx ro; then
        ROOT_READONLY=true
    fi
    if $ROOT_READONLY; then
        echo ""
        echo "[install_agent] TrueNAS SCALE: корневая ФС только для чтения, apt отключён."
        echo "[install_agent] Включите Developer Mode для нативной установки:"
        echo "  sudo install-dev-tools"
        echo "  # затем повторите команду установки агента"
        echo ""
        echo "Вариант B (рекомендуется): запустить агент в Sandbox/контейнере TrueNAS"
        echo "  (нужны host network/PID и доступ к /proc, /sys, пулам ZFS)."
        echo ""
        exit 1
    fi
    echo "[install_agent] TrueNAS SCALE: developer mode включён (/usr, /opt — rw), продолжаю."
    echo "[install_agent] ПРЕДУПРЕЖДЕНИЕ: после обновления TrueNAS Developer Mode может быть сброшен."
fi

# ─── TrueNAS: проверка установочного пути ─────────────────────────────────────
if $IS_TRUENAS && [[ "$DISTRO_FAMILY" == "freebsd" ]]; then
    # TrueNAS Core: /opt может быть read-only, используем /mnt/pool/monitoring
    if [[ ! -w /opt ]]; then
        echo "[install_agent] /opt не доступен для записи (TrueNAS Core)."
        echo "[install_agent] Рекомендуется установить на ZFS dataset: /mnt/pool/monitoring"
        DEFAULT_INSTALL="/mnt/pool/monitoring"
        read -r -p "Куда установить? [${DEFAULT_INSTALL}]: " INSTALL_DIR
        INSTALL_DIR="${INSTALL_DIR:-$DEFAULT_INSTALL}"
    fi
fi

# ─── Установка системных пакетов ──────────────────────────────────────────────
echo "[install_agent] Установка пакетов..."
$PKG_UPDATE

# Базовые: git, python3, сеть, процессы
$PKG_INSTALL git python3 python3-pip iproute2 procps || true

# smartmontools — ОБЯЗАТЕЛЕН для SMART мониторинга (smartctl)
if ! command -v smartctl &>/dev/null; then
    echo "[install_agent] Устанавливаю smartmontools (smartctl для SMART мониторинга)..."
    case "$DISTRO_FAMILY" in
        debian)   $PKG_INSTALL smartmontools || true ;;
        rhel)     $PKG_INSTALL smartmontools || true ;;
        arch)     $PKG_INSTALL smartmontools || true ;;
        freebsd)  $PKG_INSTALL smartmontools || true ;;
    esac
fi

# net-tools — fallback для netstat (если ss недоступен)
# FreeBSD имеет встроенный netstat, не нужен net-tools
if ! $IS_FREEBSD && ! command -v ss &>/dev/null && ! command -v netstat &>/dev/null; then
    echo "[install_agent] Устанавливаю net-tools (netstat fallback)..."
    case "$DISTRO_FAMILY" in
        debian)   $PKG_INSTALL net-tools || true ;;
        rhel)     $PKG_INSTALL net-tools || true ;;
        arch)     $PKG_INSTALL net-tools || true ;;
    esac
fi

# libpcap — для scapy LLDP sniff
$PKG_INSTALL "$PKG_LIBPCAP" 2>/dev/null || true

# SNMP — fallback snmpwalk
$PKG_INSTALL "$PKG_EXTRA" 2>/dev/null || true

# Системный scapy (опционально; pip scapy тоже ставится)
if [[ -n "$PKG_SCAPY" ]]; then
    $PKG_INSTALL "$PKG_SCAPY" 2>/dev/null || true
fi

# FreeBSD: дополнительные утилиты
if $IS_FREEBSD; then
    echo "[install_agent] Устанавливаю FreeBSD утилиты..."
    $PKG_INSTALL bash || true  # bash для скриптов (FreeBSD sh не поддерживает все конструкции)
    $PKG_INSTALL camcontrol || true  # camcontrol для SMART на SCSI/SAS
fi

# ─── Python venv ──────────────────────────────────────────────────────────────
echo "[install_agent] Проверка Python venv..."
if python3 -c "import venv, ensurepip" &>/dev/null; then
    echo "[install_agent] Модуль venv доступен."
else
    echo "[install_agent] Модуль venv/ensurepip не найден. Пытаемся установить..."
    case "$DISTRO_FAMILY" in
        debian)
            if sudo apt-get install -y python3-venv 2>/dev/null; then
                echo "[install_agent] python3-venv установлен."
            else
                echo "[install_agent] Не удалось установить python3-venv. Используем virtualenv через pipx..."
                sudo apt-get install -y pipx || true
                pipx ensurepath 2>/dev/null || true
                pipx install virtualenv 2>/dev/null || true
                export PATH="$PATH:$HOME/.local/bin"
            fi
            ;;
        rhel|arch)
            echo "[install_agent] venv должен быть встроен в python3. Если нет — установите вручную."
            ;;
    esac
fi

# ─── Клонирование репозитория ────────────────────────────────────────────────
echo "[install_agent] Репозиторий → ${INSTALL_DIR}"
if [[ -d "${INSTALL_DIR}/.git" ]]; then
    # Повторный запуск: репозиторий принадлежит другому пользователю, а git под
    # sudo идёт от root → "detected dubious ownership". Разрешаем этот путь явно.
    sudo git -c safe.directory="${INSTALL_DIR}" -C "${INSTALL_DIR}" fetch --all --prune
    sudo git -c safe.directory="${INSTALL_DIR}" -C "${INSTALL_DIR}" checkout "${BRANCH}"
    sudo git -c safe.directory="${INSTALL_DIR}" -C "${INSTALL_DIR}" pull --ff-only origin "${BRANCH}"
else
    sudo mkdir -p "${INSTALL_DIR}"
    sudo chown "$(whoami)":"$(whoami)" "${INSTALL_DIR}"
    git clone -b "${BRANCH}" --depth 1 "${REPO_URL}" "${INSTALL_DIR}"
fi

cd "${INSTALL_DIR}"

# ─── Python venv: создание ───────────────────────────────────────────────────
echo "[install_agent] Python venv..."
create_venv() {
    if python3 -c "import venv" &>/dev/null; then
        if python3 -m venv .venv --without-pip 2>/dev/null; then
            # Бутстрап pip через ensurepip (проверка целостности встроена)
            .venv/bin/python3 -m ensurepip --upgrade 2>/dev/null || {
                echo "[install_agent] ensurepip не работает. Скачиваем get-pip.py с проверкой..."
                GET_PIP_URL="https://bootstrap.pypa.io/get-pip.py"
                GET_PIP_HASH="sha256:5ae086cd4e236ee5b6d5d88cd6408635d0ad16ac57b70e72f3c2b20485e1d175"
                TMP_GET_PIP=$(mktemp /tmp/get-pip-XXXXXX.py)
                if curl -sSfL -o "$TMP_GET_PIP" "$GET_PIP_URL"; then
                    DOWNLOADED_HASH=$(sha256sum "$TMP_GET_PIP" | awk '{print $1}')
                    if [[ "$DOWNLOADED_HASH" == "$GET_PIP_HASH" ]]; then
                        .venv/bin/python3 "$TMP_GET_PIP"
                    else
                        echo "[install_agent] ПРЕДУПРЕЖДЕНИЕ: get-pip.py изменился, хеш устарел. Используем virtualenv/pipx."
                        echo "[install_agent] Ожидалось: $GET_PIP_HASH"
                        echo "[install_agent] Получено:  $DOWNLOADED_HASH"
                        rm -f "$TMP_GET_PIP"
                        return 1
                    fi
                    rm -f "$TMP_GET_PIP"
                else
                    echo "[install_agent] ОШИБКА: не удалось скачать get-pip.py"
                    return 1
                fi
            }
            return 0
        else
            echo "[install_agent] Создание venv через python3 -m venv не удалось."
            return 1
        fi
    else
        return 1
    fi
}

if ! create_venv; then
    echo "[install_agent] Используем virtualenv через pipx..."
    case "$DISTRO_FAMILY" in
        debian) sudo apt-get install -y pipx || true ;;
        rhel)   sudo dnf install -y pipx 2>/dev/null || sudo yum install -y pipx 2>/dev/null || true ;;
        arch)   sudo pacman -S --noconfirm python-pipx 2>/dev/null || true ;;
    esac
    pipx ensurepath 2>/dev/null || true
    export PATH="$PATH:$HOME/.local/bin"
    if ! command -v virtualenv &>/dev/null; then
        pipx install virtualenv 2>/dev/null || true
    fi
    virtualenv .venv
fi

# ─── Python зависимости ──────────────────────────────────────────────────────
VENV_PYTHON="${INSTALL_DIR}/.venv/bin/python3"
if [[ ! -x "${VENV_PYTHON}" ]]; then
    echo "[install_agent] ОШИБКА: venv не создан (${VENV_PYTHON} отсутствует)."
    exit 1
fi
REQ="${INSTALL_DIR}/agent/requirements.txt"
if [[ ! -f "${REQ}" ]]; then
    REQ="${INSTALL_DIR}/requirements.txt"
fi
if [[ -f "${REQ}" ]]; then
    "${VENV_PYTHON}" -m pip install --upgrade pip
    "${VENV_PYTHON}" -m pip install -r "${REQ}"
    # LLDP passive (опционально; без root/CAP_NET_RAW sniff не заведётся)
    "${VENV_PYTHON}" -m pip install "scapy>=2.5.0" || echo "[install_agent] scapy не установлен — пассивный LLDP будет выключен"
fi

# ─── Пользователь monitoring ─────────────────────────────────────────────────
if ! id "monitoring" &>/dev/null; then
    echo "[install_agent] Создание пользователя monitoring..."
    sudo useradd -r -s /usr/sbin/nologin -d "${INSTALL_DIR}" monitoring 2>/dev/null || \
        sudo useradd -r -s /bin/bash -d "${INSTALL_DIR}" monitoring 2>/dev/null || true
fi

if id "monitoring" &>/dev/null; then
    sudo chown -R monitoring:monitoring "${INSTALL_DIR}"
    getent group docker >/dev/null && sudo usermod -aG docker monitoring || true
    getent group adm >/dev/null && sudo usermod -aG adm monitoring || true
    getent group systemd-journal >/dev/null && sudo usermod -aG systemd-journal monitoring || true
    SUDOERS_FILE="/etc/sudoers.d/monitoring-agent"
    # Двоеточие в monitoring:monitoring для sudoers нужно экранировать — иначе
    # syntax error, который ломает КАЖДЫЙ вызов sudo в системе. Файл пишем через
    # временный и проверяем visudo -c, чтобы не оставить битый sudoers.
    SUDOERS_LINE="monitoring ALL=(ALL) NOPASSWD: /usr/sbin/ufw, /usr/sbin/iptables, /usr/sbin/iptables-save, /usr/bin/chown -R monitoring\\:monitoring ${INSTALL_DIR}/.git"
    printf '%s\n' "${SUDOERS_LINE}" | sudo tee "${SUDOERS_FILE}.tmp" >/dev/null
    if ! command -v visudo >/dev/null 2>&1 || sudo visudo -cf "${SUDOERS_FILE}.tmp" >/dev/null 2>&1; then
        sudo chmod 0440 "${SUDOERS_FILE}.tmp"
        sudo mv "${SUDOERS_FILE}.tmp" "${SUDOERS_FILE}"
    else
        echo "[install_agent] ПРЕДУПРЕЖДЕНИЕ: sudoers не прошёл visudo -c, файл не обновлён."
        sudo rm -f "${SUDOERS_FILE}.tmp"
    fi
    if [[ -f /etc/truenas || -f /data/truenas-version || -f /etc/platform ]]; then
        SERVICE_USER="root"
    else
        SERVICE_USER="monitoring"
    fi
else
    sudo chown -R "$(whoami)":"$(whoami)" "${INSTALL_DIR}" || true
    SERVICE_USER="root"
fi

VENV_PYTHON="${INSTALL_DIR}/.venv/bin/python3"
AGENT_MAIN="${INSTALL_DIR}/agent/main.py"

# ─── Systemd / rc.d сервис ───────────────────────────────────────────────────
if $IS_FREEBSD; then
    # FreeBSD: rc.d скрипт
    RC_SCRIPT="/usr/local/etc/rc.d/monitoring-agent"
    sudo mkdir -p /usr/local/etc/rc.d
    sudo tee "${RC_SCRIPT}" >/dev/null <<'RCEOF'
#!/bin/sh
#
# PROVIDE: monitoring-agent
# REQUIRE: DAEMON NETWORKING
# KEYWORD: shutdown

. /etc/rc.subr

name="monitoring-agent"
rcvar="${name}_enable"
command="${INSTALL_DIR_VENV_PYTHON}"
command_args="${INSTALL_DIR_AGENT_MAIN}"
pidfile="/var/run/${name}.pid"

start_precmd="${name}_prestart"
stop_postcmd="${name}_poststop"

monitoring-agent_prestart() {
    mkdir -p /var/run
    echo $$ > $pidfile
}

monitoring-agent_poststop() {
    rm -f $pidfile
}

load_rc_config $name
run_rc_command "$1"
RCEOF
    sudo chmod 0555 "${RC_SCRIPT}"

    # Подставляем пути
    sudo sed -i '' "s|INSTALL_DIR_VENV_PYTHON|${VENV_PYTHON}|g" "${RC_SCRIPT}"
    sudo sed -i '' "s|INSTALL_DIR_AGENT_MAIN|${AGENT_MAIN}|g" "${RC_SCRIPT}"

    echo "[install_agent] FreeBSD rc.d сервис создан: ${RC_SCRIPT}"
    echo "[install_agent] Для запуска: sudo service monitoring-agent enable && sudo service monitoring-agent start"
else
    # Linux: systemd сервис
    UNIT_DST="/etc/systemd/system/monitoring-agent.service"
    sudo tee "${UNIT_DST}" >/dev/null <<SVCEOF
[Unit]
Description=HostMonitor Agent
After=network.target
# Защита от crash-loop: макс 5 перезапусков за 5 минут
StartLimitBurst=5
StartLimitIntervalSec=300

[Service]
Type=simple
${SERVICE_USER:+User=${SERVICE_USER}}
${SERVICE_USER:+Group=${SERVICE_USER}}
WorkingDirectory=${INSTALL_DIR}/agent
Environment="PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${INSTALL_DIR}/.venv/bin"
ExecStart=${VENV_PYTHON} ${AGENT_MAIN}
AmbientCapabilities=CAP_NET_RAW CAP_NET_ADMIN
CapabilityBoundingSet=CAP_NET_RAW CAP_NET_ADMIN CAP_NET_BIND_SERVICE
NoNewPrivileges=false
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
TimeoutStartSec=30
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
SVCEOF
    sudo systemctl daemon-reload

    # Git: dubious ownership (только для конкретной ноды)
    GIT_DROPIN_DIR="/etc/systemd/system/monitoring-agent.service.d"
    sudo mkdir -p "${GIT_DROPIN_DIR}"
    sudo tee "${GIT_DROPIN_DIR}/git-safe.conf" >/dev/null <<EOF
[Service]
Environment=GIT_CONFIG_COUNT=1
Environment=GIT_CONFIG_KEY_0=safe.directory
Environment=GIT_CONFIG_VALUE_0=${INSTALL_DIR}
Environment=GIT_TERMINAL_PROMPT=0
EOF

  # Разрешённые команды. Флаги разные и намеренно:
  #   ALLOW_AGENT_UPDATES    — self-update агента (штатная кнопка панели,
  #                            безопасна: агент тянет новую версию и
  #                            перезапускает себя). По умолчанию включено,
  #                            иначе панель показывала бы «доступно
  #                            обновление», которое нельзя установить;
  #   ALLOW_DANGEROUS_COMMANDS — reboot, shutdown, kill, restart, firewall.
  #                            Выключается, чтобы требовать явного
  #                            ALLOW_DANGEROUS_COMMANDS=true при установке.
  # Отключить оба: sudo rm ${GIT_DROPIN_DIR}/dangerous-commands.conf
  DANGEROUS="${ALLOW_DANGEROUS_COMMANDS:-false}"
  AGENT_UPDATES="${ALLOW_AGENT_UPDATES:-true}"
  sudo tee "${GIT_DROPIN_DIR}/dangerous-commands.conf" >/dev/null <<EOF
[Service]
Environment=ALLOW_DANGEROUS_COMMANDS=${DANGEROUS}
Environment=ALLOW_AGENT_UPDATES=${AGENT_UPDATES}
EOF
  sudo systemctl daemon-reload
fi

# ─── Конфигурация агента ─────────────────────────────────────────────────────
NODE_CONF="${INSTALL_DIR}/agent/node.conf"

# Enroll: обмен одноразового кода из панели на Ed25519-ключ. Создаёт node.conf
# с NODE_ID/NODE_SECRET_B64/NODE_PUBLIC_KEY_B64 — токен не нужен.
if [[ -n "${ENROLL_TOKEN:-}" ]]; then
    if [[ -f "${NODE_CONF}" && "${FORCE:-}" != "true" ]]; then
        echo "[install_agent] node.conf уже есть (${NODE_CONF}). Для переустановки добавьте --force."
    else
        ENROLL_MASTER="${MASTER_URL:-}"
        if [[ -z "${ENROLL_MASTER}" && -f "${NODE_CONF}" ]]; then
            ENROLL_MASTER="$(grep '^MASTER_URL=' "${NODE_CONF}" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true)"
        fi
        if [[ -z "${ENROLL_MASTER}" ]]; then
            echo "[install_agent] ERROR: для enroll нужен URL панели: --master https://panel/ (или MASTER_URL=...)" >&2
            exit 1
        fi
        sudo mkdir -p "$(dirname "${NODE_CONF}")"
        ENROLL_CMD=(sudo -u "${SERVICE_USER}" "${VENV_PYTHON}" "${INSTALL_DIR}/agent/enroll.py" \
                --master "${ENROLL_MASTER}" --token "${ENROLL_TOKEN}" --config "${NODE_CONF}")
        if [[ -n "${NODE_NAME:-}" ]]; then
            ENROLL_CMD+=(--name "${NODE_NAME}")
        fi
        "${ENROLL_CMD[@]}" || {
            echo "[install_agent] ERROR: enroll не удался — проверьте код (живёт 15 минут)." >&2
            exit 1
        }
        sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${NODE_CONF}"
        sudo chmod 640 "${NODE_CONF}"
        echo "[install_agent] Enroll успешен: нода подключена по Ed25519 (NODE_TOKEN не нужен)."
    fi
fi

# --fix-url: обновляем только MASTER_URL в существующем конфиге
if [[ -n "${FIX_URL:-}" && -f "${NODE_CONF}" && -n "${MASTER_URL:-}" ]]; then
    sudo sed -i "s|^MASTER_URL=.*|MASTER_URL=\"${MASTER_URL}\"|" "${NODE_CONF}"
    echo "[install_agent] MASTER_URL обновлён на ${MASTER_URL}"
fi

# --node-id: фиксируем привязку к существующей ноде (перекрывает код enroll)
if [[ -n "${NODE_ID:-}" && -f "${NODE_CONF}" ]] && ! grep -q '^NODE_ID=' "${NODE_CONF}"; then
    echo "NODE_ID=${NODE_ID}" | sudo tee -a "${NODE_CONF}" >/dev/null
    echo "[install_agent] NODE_ID=${NODE_ID} добавлен в node.conf"
fi

# Если конфиг передан через переменную окружения – копируем
if [[ -n "${NODE_CONF_SRC:-}" && -f "${NODE_CONF_SRC}" ]]; then
    sudo cp "${NODE_CONF_SRC}" "${NODE_CONF}"
    sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${NODE_CONF}"
    sudo chmod 640 "${NODE_CONF}"
fi

# Если конфига нет – предлагаем создать интерактивно
if [[ ! -f "${NODE_CONF}" ]]; then
    echo ""
    echo "=========================================="
    echo "Файл конфигурации агента не найден: ${NODE_CONF}"
    echo "Создадим его сейчас."
    echo "=========================================="
    read -r -p "Введите URL панели (например, http://192.168.1.100:5443): " MASTER_URL
    read -r -p "Введите токен ноды (скопируйте из панели): " NODE_TOKEN
    read -r -p "Введите порт для агента (по умолчанию 2222): " PORT
    PORT="${PORT:-2222}"

    NODE_NAME_DEFAULT="$(hostname -s 2>/dev/null || echo node-1)"
    read -r -p "Имя ноды [${NODE_NAME_DEFAULT}]: " NODE_NAME
    NODE_NAME="${NODE_NAME:-$NODE_NAME_DEFAULT}"
    read -r -p "Интерфейс для LLDP (пусто = авто, напр. eth0): " LLDP_IFACE

    sudo mkdir -p "$(dirname "${NODE_CONF}")"
    sudo tee "${NODE_CONF}" >/dev/null <<EOF
MASTER_URL="${MASTER_URL}"
NODE_TOKEN="${NODE_TOKEN}"
NODE_NAME="${NODE_NAME}"
NODE_PORT="${PORT}"
COLLECT_INTERVAL=60
HEARTBEAT_INTERVAL=15
UPNP_ENABLED=true
UPNP_INTERVAL_CYCLES=2
UPNP_MX=3
UPNP_TIMEOUT=8
UPNP_GENA_PORT=0
SNMP_ENABLED=true
SNMP_COMMUNITY="${SNMP_COMMUNITY_VALUE}"
SNMP_TIMEOUT=0.8
# SNMP_TARGETS="192.168.1.1"
LLDP_PASSIVE=true
LLDP_ACTIVE_POLL_KNOWN=true
EOF
    if [[ -n "${LLDP_IFACE}" ]]; then
        echo "LLDP_LISTEN_INTERFACE=\"${LLDP_IFACE}\"" | sudo tee -a "${NODE_CONF}" >/dev/null
    fi
    # Проверку TLS отключаем только когда панель реально на plain HTTP — иначе
    # оставляем штатную проверку сертификата (иначе токен ноды и метрики
    # идут по сети без защиты от MITM).
    if [[ "${MASTER_URL}" == http://* ]]; then
        echo "TLS_VERIFY=false" | sudo tee -a "${NODE_CONF}" >/dev/null
        echo "[install_agent] Панель на HTTP — TLS_VERIFY=false (для https:// оставьте проверку сертификата)"
    else
        echo "TLS_VERIFY=true" | sudo tee -a "${NODE_CONF}" >/dev/null
    fi
    sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${NODE_CONF}"
    sudo chmod 640 "${NODE_CONF}"
    echo "[install_agent] Конфиг создан: ${NODE_CONF}"
fi

# Дописать LLDP/SNMP в уже существующий конфиг, если ключей ещё нет
if [[ -f "${NODE_CONF}" ]]; then
    ensure_conf_key() {
        local key="$1"
        local line="$2"
        if ! grep -qE "^[[:space:]]*${key}=" "${NODE_CONF}" 2>/dev/null; then
            echo "${line}" | sudo tee -a "${NODE_CONF}" >/dev/null
            echo "[install_agent] Добавлено в node.conf: ${key}"
        fi
    }
    ensure_conf_key "UPNP_ENABLED" 'UPNP_ENABLED=true'
    ensure_conf_key "SNMP_ENABLED" 'SNMP_ENABLED=true'
    ensure_conf_key "SNMP_COMMUNITY" 'SNMP_COMMUNITY="'"${SNMP_COMMUNITY_VALUE}"'"'
    ensure_conf_key "SNMP_TIMEOUT" 'SNMP_TIMEOUT=0.8'
    ensure_conf_key "LLDP_PASSIVE" 'LLDP_PASSIVE=true'
    ensure_conf_key "LLDP_ACTIVE_POLL_KNOWN" 'LLDP_ACTIVE_POLL_KNOWN=true'
fi

# ─── Запуск сервиса ──────────────────────────────────────────────────────────
if [[ -f "${NODE_CONF}" ]]; then
    echo "[install_agent] Найден ${NODE_CONF} — включаю сервис"
    if $IS_FREEBSD; then
        sudo sysrc monitoring-agent_enable=YES
        sudo service monitoring-agent start || true
        sudo service monitoring-agent status || true
    else
        sudo systemctl enable --now monitoring-agent
        sudo systemctl --no-pager --full status monitoring-agent || true
    fi
else
    echo "[install_agent] Сервис установлен, но не запущен: нет ${NODE_CONF}"
    echo "Создайте конфиг вручную или запустите скрипт снова, указав параметры."
fi

echo ""
echo "=========================================="
echo "Агент не использует MySQL — он шлёт JSON на панель."
echo ""
echo "Вариант A (Ed25519, рекомендуется):"
echo "  В панели: Ноды → «Подключить ноду (Enroll)» → сгенерировать код, затем:"
echo "    install_agent.sh --enroll <КОД> --master https://panel/"
echo "  Нода подключится по Ed25519-подписи, NODE_TOKEN не нужен."
echo ""
echo "Вариант B (legacy Bearer-токен):"
echo "1. В панели: Ноды → Создать ноду → скопировать / скачать конфиг"
echo "2. При необходимости отредактируйте: sudo nano ${NODE_CONF}"
if $IS_FREEBSD; then
    echo "3. Перезапустите: sudo service monitoring-agent restart"
    echo "4. Статус: sudo service monitoring-agent status"
else
    echo "3. Перезапустите: sudo systemctl restart monitoring-agent"
    echo "4. Статус: sudo systemctl status monitoring-agent"
fi
echo ""
echo "LLDP: LLDP_PASSIVE=true (нужны scapy + CAP_NET_RAW в unit)."
echo "SNMP: SNMP_COMMUNITY / SNMP_TARGETS для активного опроса соседей."
echo "SMART: smartmontools установлен автоматически (smartctl)."
if $IS_TRUENAS; then
    echo ""
    echo "TrueNAS: SMART работает через camcontrol/smartctl на SCSI/SAS/SATA дисках."
    echo "TrueNAS: Docker мониторинг доступен через standard docker CLI."
fi
if $IS_PROXMOX; then
    echo ""
    echo "Proxmox: ZFS pools мониторятся через zpool list."
    echo "Proxmox: LVM/RAID диски видны через smartctl."
fi
echo "=========================================="
