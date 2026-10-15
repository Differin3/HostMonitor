#!/usr/bin/env python3
"""Тесты структурирования SSH-логов агентом.

Закрывает регрессию: строки sshd классифицируются в машинный event_type,
служебные баннеры journalctl («-- Journal begins ...») больше не попадают
в журнал как события, а повторяющиеся попытки схлопываются.

Запуск:  python tests/test_logs.py
Выход:   0 — все проверки прошли, 1 — есть падения.
"""

import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "agent"))

from logs import LogsMixin  # noqa: E402

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


def test_parse_events():
    cases = [
        ("Accepted password for root from 192.168.10.5 port 51234 ssh2", "accepted", "root", "192.168.10.5", True),
        ("Accepted publickey for deploy from 10.0.0.1 port 22 ssh2", "accepted", "deploy", "10.0.0.1", True),
        ("Failed password for root from 45.9.20.1 port 22 ssh2", "failed", "root", "45.9.20.1", False),
        ("Failed password for invalid user admin from 45.9.20.1 port 1 ssh2", "invalid_user", "admin", "45.9.20.1", False),
        ("Invalid user oracle from 10.0.0.9 port 4444", "invalid_user", "oracle", "10.0.0.9", False),
        ("Failed publickey for test from 1.1.1.1 port 5 ssh2", "failed", "test", "1.1.1.1", False),
        ("error: maximum authentication attempts exceeded for root from 1.2.3.4 port 5 ssh2 [preauth]", "max_attempts", "root", "1.2.3.4", False),
        ("pam_unix(sshd:auth): authentication failure; logname= uid=0 euid=0 tty=ssh ruser= rhost=5.6.7.8 user=root", "pam_failure", "root", "5.6.7.8", False),
        ("pam_unix(sshd:session): session opened for user root by (uid=0)", "session", "root", None, None),
        ("Disconnected from authenticating user root 9.9.9.9 port 3333 [preauth]", "disconnect", "root", "9.9.9.9", None),
        ("Disconnected from user root 10.0.0.2 port 44532", "disconnect", "root", "10.0.0.2", None),
        ("Received disconnect from 8.8.8.8 port 2222:11: Bye Bye [preauth]", "disconnect", None, "8.8.8.8", None),
        ("Connection closed by authenticating user root 7.7.7.7 port 1111 [preauth]", "connection_closed", "root", "7.7.7.7", None),
        ("Connection reset by 6.6.6.6 port 9999 [preauth]", "connection_closed", None, "6.6.6.6", None),
        ("Something unknown from 3.3.3.3", "other", None, "3.3.3.3", None),
    ]
    for msg, event_type, user, ip, success in cases:
        e = LogsMixin._parse_ssh_event(msg)
        detail = f"получено event_type={e['event_type']} user={e['username']} ip={e['ip']} success={e['success']}"
        check(
            e["event_type"] == event_type and e["username"] == user and e["ip"] == ip and e["success"] == success,
            f"{event_type}: {msg[:52]}",
            detail,
        )


def test_parse_summary_and_port():
    e = LogsMixin._parse_ssh_event("Accepted password for root from 1.2.3.4 port 1234 ssh2")
    check(e["port"] == 1234, "порт извлекается", repr(e["port"]))
    check("SUCCESS" in e["summary"], "summary успешного входа содержит SUCCESS", e["summary"])
    e = LogsMixin._parse_ssh_event("Failed password for root from 1.2.3.4 port 22 ssh2")
    check("FAIL" in e["summary"], "summary неудачи содержит FAIL", e["summary"])


def test_noise_filter():
    check(LogsMixin._is_ssh_noise("-- Journal begins at Thu 2026-10-01 22:07:33 MSK. --"), "баннер journalctl — мусор")
    check(LogsMixin._is_ssh_noise("-- No entries --"), "No entries — мусор")
    check(LogsMixin._is_ssh_noise("   "), "пустая строка — мусор")
    check(not LogsMixin._is_ssh_noise("2026-10-01T22:07:33+03:00 host sshd[123]: Accepted password for root from 1.2.3.4 port 22 ssh2"), "реальное событие не мусор")


def test_collapse_duplicates():
    base = {
        "type": "auth_ssh",
        "level": "error",
        "process": "sshd",
        "username": "root",
        "ip": "45.9.20.1",
        "event_type": "failed",
        "success": False,
        "raw_message": "x",
    }
    logs = [
        dict(base, message="FAIL user=root ip=45.9.20.1 port=22", timestamp="2026-10-01 10:00:00"),
        dict(base, message="FAIL user=root ip=45.9.20.1 port=22", timestamp="2026-10-01 10:00:01"),
        dict(base, message="FAIL user=root ip=45.9.20.1 port=22", timestamp="2026-10-01 10:00:02"),
        dict(base, message="FAIL user=other ip=45.9.20.1 port=22", timestamp="2026-10-01 10:00:03", username="other"),
    ]
    collapsed = LogsMixin._collapse_ssh_duplicates(logs)
    check(len(collapsed) == 2, "три одинаковые попытки схлопнуты в одну (+другая)", f"получено {len(collapsed)}")
    check(any("×3" in c["message"] for c in collapsed), "счётчик повторов в сообщении")


def test_collapse_keeps_different_event_types():
    a = {"type": "auth_ssh", "username": "root", "ip": "1.2.3.4", "event_type": "failed", "success": False, "message": "f", "timestamp": "t"}
    b = {"type": "auth_ssh", "username": "root", "ip": "1.2.3.4", "event_type": "accepted", "success": True, "message": "a", "timestamp": "t"}
    check(len(LogsMixin._collapse_ssh_duplicates([a, b])) == 2, "разные event_type не схлопываются")


def test_collapse_passthrough_non_ssh():
    other = {"type": "system", "message": "boot"}
    check(LogsMixin._collapse_ssh_duplicates([other]) == [other], "не-SSH записи проходят без изменений")


if __name__ == "__main__":
    print("== parse ssh events ==")
    test_parse_events()
    test_parse_summary_and_port()
    print("== noise filter ==")
    test_noise_filter()
    print("== collapse ==")
    test_collapse_duplicates()
    test_collapse_keeps_different_event_types()
    test_collapse_passthrough_non_ssh()
    print(f"\nПройдено: {PASSED}, провалено: {FAILED}")
    sys.exit(1 if FAILED else 0)
