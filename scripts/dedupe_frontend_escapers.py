#!/usr/bin/env python3
"""
Одноразовая миграция: убрать дубли esc/escapeHtml/escHtmlAttr/authEscape
и top-level const API_BASE из страничных скриптов, заменив их на
frontend/js/common.js.

Правила удаления:
  * удаляются только объявления на верхнем уровне (колонка 0),
  * объявления внутри IIFE/функций (с отступом) НЕ трогаются — это
    намеренная инкапсуляция модулей (net-gear.js, jobs.js),
  * тело функции съедается целиком: до строки, следующей за '};' или '}'.

Запуск: python3 scripts/dedupe_frontend_escapers.py [--check]
  --check только показывает, что будет удалено, ничего не меняя.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS_DIR = ROOT / "frontend" / "js"

ARROW = re.compile(r"^const (esc|escapeHtml|escHtmlAttr|authEscape)\s*=")
FUNC = re.compile(r"^function (esc|escapeHtml|escHtmlAttr|authEscape)\s*\(")
API_BASE = re.compile(r"^const API_BASE\s*=")

SKIP = {"common.js"}


def code_part(line: str) -> str:
    """Строка без хвостового //-комментария (в наших объявлениях '//' в строках нет)."""
    return line.split("//", 1)[0].rstrip()


def block_end(lines: list[str], start: int) -> int:
    """Индекс (0-based) первой строки после тела объявления."""
    head = code_part(lines[start])
    # Однострочное объявление: `const x = ...;` или однострочная функция.
    if head.endswith(";") or head.endswith("}"):
        return start + 1
    # Объявление открывает блок: `function f() {` — считаем парные скобки.
    if head.endswith("{"):
        depth = head.count("{") - head.count("}")
        i = start + 1
        while i < len(lines) and depth > 0:
            cur = code_part(lines[i])
            depth += cur.count("{") - cur.count("}")
            i += 1
        return i
    # Многострочное стрелочное выражение:
    #   const esc = (v) => String(v ?? '')
    #       .replace(...)
    #       .replace(...);
    i = start + 1
    while i < len(lines):
        cur = code_part(lines[i])
        if cur.endswith(";") and cur.count("{") == cur.count("}"):
            return i + 1
        i += 1
    return len(lines)


def process(path: Path, check: bool) -> tuple[int, list[str]]:
    lines = path.read_text(encoding="utf-8").splitlines(keepends=True)
    out: list[str] = []
    removed: list[str] = []
    i = 0
    while i < len(lines):
        line = lines[i]
        m = ARROW.match(line) or FUNC.match(line) or API_BASE.match(line)
        if not m:
            out.append(line)
            i += 1
            continue
        name = m.group(1) if m.lastindex else "API_BASE"
        end = block_end(lines, i)
        removed.append(f"{name} (строки {i + 1}–{end})")
        i = end
    if removed and not check:
        text = "".join(out)
        # Схлопываем пустые строки подряд, возникшие после удаления.
        text = re.sub(r"\n{3,}", "\n\n", text)
        path.write_text(text, encoding="utf-8")
    return len(removed), removed


def main() -> int:
    check = "--check" in sys.argv
    total = 0
    for path in sorted(JS_DIR.glob("*.js")):
        if path.name in SKIP:
            continue
        count, removed = process(path, check)
        if count:
            total += count
            print(f"{path.name}: удалить {count} — {', '.join(removed)}")
    print(f"\nВсего объявлений: {total}" + ("  (--check, файлы не менялись)" if check else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
