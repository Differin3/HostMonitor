#!/usr/bin/env python3
"""Тесты разбора S.M.A.R.T. агентом.

Закрывает регрессию: raw-значение атрибута 194 (Temperature_Celsius) у
многих SATA-дисков упаковано (старшие байты — Min/Max), поэтому
``int(raw.value)`` давал миллиарды, а реальная температура лежала в
``raw.string``. На панели это роняло вставку в SMALLINT (500) и ломало
график температуры.

Запуск:  python tests/test_smart.py
Выход:   0 — все проверки прошли, 1 — есть падения.
"""

import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "agent"))

from smart import SmartMixin, _coerce_int, _clamp_temperature  # noqa: E402

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


_MIXIN = SmartMixin()


def test_parse_temperature():
    # Реальные примеры с Proxmox: WD/Seagate/Samsung/S900.
    cases = [
        ((2828843, "43 (Min/Max 42/43)"), 43),
        ((8589934623, "31 (0 2 0 0 0)"), 31),
        ((188979871773, "29 (Min/Max 20/44)"), 29),
        ((32, "32"), 32),
        ((30, "30"), 30),
        ((None, ""), None),
    ]
    for (raw_value, raw_string), expected in cases:
        got = _MIXIN._parse_temperature(raw_value, raw_string)
        check(got == expected, f"temperature({raw_value!r}, {raw_string!r}) → {expected}", repr(got))


def test_parse_temperature_prefers_string():
    # Строка важнее упакованного числа: 0x11001A → 26 °C, не 1114138.
    got = _MIXIN._parse_temperature(0x11001A, "26 (Min/Max 24/30)")
    check(got == 26, "приоритет raw.string", repr(got))


def test_coerce_int():
    cases = [
        (188979871773, 188979871773),
        ("43 (Min/Max 42/43)", 43),
        ("12", 12),
        (None, None),
        ([1, 2], None),
        ({"a": 1}, None),
        (True, None),
        ("", None),
    ]
    for value, expected in cases:
        got = _coerce_int(value)
        check(got == expected, f"coerce_int({value!r}) → {expected}", repr(got))


def test_clamp_temperature():
    check(_clamp_temperature(43) == 43, "clamp 43")
    check(_clamp_temperature(-40) is None, "clamp слишком низкое")
    check(_clamp_temperature(5000) is None, "clamp слишком высокое")
    check(_clamp_temperature(None) is None, "clamp None")


if __name__ == "__main__":
    print("== parse temperature ==")
    test_parse_temperature()
    test_parse_temperature_prefers_string()
    print("== coerce int ==")
    test_coerce_int()
    print("== clamp ==")
    test_clamp_temperature()
    print(f"\nПройдено: {PASSED}, провалено: {FAILED}")
    sys.exit(1 if FAILED else 0)
