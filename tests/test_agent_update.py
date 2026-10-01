#!/usr/bin/env python3
"""Тесты self-update агента: откат при сбое зависимостей и причины ошибок.

Закрывает регрессии:
  - returncode от ``pip install`` игнорировался, и упавшая установка
    проглатывалась, после чего агент всё равно перезапускался на новом коде;
  - отката не было: после ``git reset --hard`` прежний код уже недоступен,
    и битое обновление уводило юнит в crash-loop;
  - команда, отклонённая гейтом ``ALLOW_DANGEROUS_COMMANDS``, возвращала
    панели статус ``failed`` без причины — в UI было голое «ошибка»;
  - ``check-agent-update`` с ошибкой тоже не объяснял, что именно сломалось.

Запуск:  python tests/test_agent_update.py
Выход:   0 — все проверки прошли, 1 — есть падения.
"""

import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile

REPO = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "agent"))

PASSED = 0
FAILED = 0

PIP_ERR = "ERROR: Could not find a version that satisfies the requirement somepkg==1.0"


def check(ok: bool, name: str, detail: str = "") -> None:
    global PASSED, FAILED
    if ok:
        PASSED += 1
        print(f"  ok   {name}")
    else:
        FAILED += 1
        print(f"  FAIL {name}" + (f" — {detail}" if detail else ""))


def eq(actual, expected, name: str) -> None:
    check(actual == expected, name, f"получено {actual!r}, ожидалось {expected!r}")


def git(cwd, *args):
    """Запускает git с фиксированным ident: без него коммит падает."""
    env = dict(os.environ)
    env.update(
        GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t",
        GIT_COMMITTER_NAME="t", GIT_COMMITTER_EMAIL="t@t",
    )
    return subprocess.run(["git", *args], cwd=str(cwd), capture_output=True,
                          text=True, env=env)


def git_ok(cwd, *args) -> str:
    r = git(cwd, *args)
    if r.returncode != 0:
        raise RuntimeError(f"git {' '.join(args)}: {r.stderr.strip()}")
    return r.stdout.strip()


# ─── Песочница ────────────────────────────────────────────────────────────────


def make_sandbox(base: pathlib.Path) -> pathlib.Path:
    """origin с версией v1 + клон агента на ней, затем публикуем v2."""
    origin = base / "origin.git"
    src = base / "src"
    src.mkdir(parents=True, exist_ok=True)
    git_ok(base, "init", "-q", "--bare", str(origin))
    git_ok(base, "clone", "-q", str(origin), str(src))
    git_ok(src, "checkout", "-q", "-b", "main")
    (src / "agent").mkdir(parents=True, exist_ok=True)
    (src / "agent" / "main.py").write_text("VERSION = 1\n")
    (src / "agent" / "requirements.txt").write_text("")
    git_ok(src, "add", "-A")
    git_ok(src, "commit", "-qm", "v1")
    git_ok(src, "push", "-q", "origin", "main")
    git_ok(origin, "symbolic-ref", "HEAD", "refs/heads/main")

    clone = base / "agent"
    git_ok(base, "clone", "-q", "-b", "main", str(origin), str(clone))

    # v2 на origin: обновление доступно, но его зависимости не ставятся
    git_ok(src, "checkout", "-q", "main")
    (src / "agent" / "main.py").write_text("VERSION = 2\n")
    (src / "agent" / "requirements.txt").write_text("somepkg==1.0\n")
    git_ok(src, "add", "-A")
    git_ok(src, "commit", "-qm", "v2")
    git_ok(src, "push", "-q", "origin", "main")
    return clone


def install_failing_pip(clone: pathlib.Path) -> None:
    """Кладёт в .venv pip, который всегда падает (обход реального pip)."""
    pipdir = clone / ".venv" / "bin"
    pipdir.mkdir(parents=True, exist_ok=True)
    pip = pipdir / "pip"
    pip.write_text(
        "#!/bin/sh\n"
        f"cat <<'EOF' >&2\n{PIP_ERR}\nEOF\n"
        "exit 1\n"
    )
    pip.chmod(0o755)


# ─── Двойники миксинов: сеть и перезапуск отключены, git настоящий ───────────


def make_updater(root: pathlib.Path):
    from updater import UpdaterMixin

    class Updater(UpdaterMixin):
        def install_root(self):
            return pathlib.Path(root)

        def _git_cmd(self, root, *args, timeout=120):
            return git(root, *args)

        def report_agent_update(self, payload):
            pass

    return Updater()


def make_transport(command):
    from transport import TransportMixin

    class Transport(TransportMixin):
        def __init__(self):
            self.master_url = "http://127.0.0.1:1"
            self.node_name = "n1"
            self.headers = {}
            self._exit_after_command = False
            self._command_error = ""
            self.reported = []

        def check_commands(self, quiet=False):
            return command

        def _start_command_heartbeat(self):
            pass

        def _stop_command_heartbeat(self):
            pass

        def report_command_status(self, cmd, status, result=""):
            self.reported.append({"cmd": cmd, "status": status, "result": result})

        def report_agent_update(self, payload):
            self.reported_agent = payload

        def update_agent(self):
            self.update_agent_called = True
            return {"ok": True, "updated": False, "message": "уже актуальная версия"}

    return Transport()


# ─── 1. Сбой pip приводит к откату ───────────────────────────────────────────


def test_pip_failure_rolls_back(base: pathlib.Path) -> None:
    print("updater.py: сбой pip приводит к откату")
    clone = make_sandbox(base)
    install_failing_pip(clone)

    before_commit = git_ok(clone, "rev-parse", "HEAD")
    before_file = (clone / "agent" / "main.py").read_text()

    u = make_updater(clone)
    # pip из песочницы всегда падает; подменяем только его запуск, чтобы
    # updater.py работал с настоящим subprocess.run и capture_output=True.
    import updater as updater_mod
    real_run = subprocess.run
    seen = []

    def fake_run(cmd, *a, **kw):
        if cmd and str(cmd[0]).endswith(("pip", "pip3")):
            seen.append(list(cmd))
            # Именно CompletedProcess с stderr: updater.py читает вывод отсюда
            # и вшивает его в текст ошибки для панели.
            return subprocess.CompletedProcess(list(cmd), 1, "", PIP_ERR)
        return real_run(cmd, *a, **kw)

    updater_mod.subprocess.run = fake_run
    try:
        res = u.update_agent()
    finally:
        updater_mod.subprocess.run = real_run

    check(bool(seen), "pip действительно вызывался", f"вызовы: {seen}")

    check(res.get("ok") is False, "update_agent возвращает ok=False при сбое pip",
          f"ok={res.get('ok')!r} error={res.get('error')!r}")
    check(res.get("updated") is False, "updated=False при сбое pip")
    check("откат" in str(res.get("error", "")).lower(),
          "в тексте ошибки сказано про откат", repr(res.get("error"))[:140])
    check(PIP_ERR[:40] in str(res.get("error", "")),
          "в тексте ошибки сохранён вывод pip", repr(res.get("error"))[:140])
    check(res.get("rolled_back") is True, "rolled_back=True подтверждён",
          f"rolled_back={res.get('rolled_back')!r}")

    eq(git_ok(clone, "rev-parse", "HEAD"), before_commit,
       "HEAD возвращён на коммит до обновления")
    eq((clone / "agent" / "main.py").read_text(), before_file,
       "код на диске возвращён к прежней версии")
    check(not getattr(u, "_exit_after_command", False),
          "агент не помечен к перезапуску после неудачного обновления")


def test_pip_success_updates(base: pathlib.Path) -> None:
    print("\nupdater.py: успешный pip всё-таки обновляет (откат не мешает)")
    clone = make_sandbox(base)
    pipdir = clone / ".venv" / "bin"
    pipdir.mkdir(parents=True, exist_ok=True)
    pip = pipdir / "pip"
    pip.write_text("#!/bin/sh\nexit 0\n")
    pip.chmod(0o755)

    u = make_updater(clone)
    res = u.update_agent()

    check(res.get("ok") is True, "update_agent возвращает ok=True", repr(res.get("error"))[:140])
    check(res.get("updated") is True, "updated=True")
    check("VERSION = 2" in (clone / "agent" / "main.py").read_text(),
          "на диске новая версия кода")
    check(getattr(u, "_exit_after_command", False),
          "агент помечен к перезапуску после успешного обновления")


# ─── 2. Причины ошибок доходят до панели ─────────────────────────────────────


def test_blocked_command_explains_why() -> None:
    print("\ntransport.py: заблокированная команда объясняет причину")
    os.environ.pop("ALLOW_DANGEROUS_COMMANDS", None)
    os.environ["ALLOW_AGENT_UPDATES"] = "false"
    try:
        t = make_transport("update-agent")
        eq(t.execute_command("update-agent"), False,
           "update-agent заблокирован при ALLOW_AGENT_UPDATES=false")
        check(not getattr(t, "update_agent_called", False),
              "заблокированная команда не доходит до update_agent()")
        check(bool(t._command_error), "заполнена _command_error")
        check("ALLOW_AGENT_UPDATES" in t._command_error,
              "причина называет нужную переменную", repr(t._command_error))
        check("безопасност" in t._command_error.lower(),
              "причина объясняет, что это политика безопасности", repr(t._command_error))
    finally:
        os.environ.pop("ALLOW_AGENT_UPDATES", None)


def test_update_agent_allowed_by_default() -> None:
    print("\ntransport.py: update-agent разрешён без опасных команд")
    os.environ.pop("ALLOW_DANGEROUS_COMMANDS", None)
    os.environ.pop("ALLOW_AGENT_UPDATES", None)
    t = make_transport("update-agent")
    eq(t.execute_command("update-agent"), True,
       "update-agent проходит без ALLOW_DANGEROUS_COMMANDS")
    check(getattr(t, "update_agent_called", False),
          "вызов дошёл до update_agent()")


def test_destructive_still_blocked_by_default() -> None:
    print("\ntransport.py: разрушительные команды остаются закрыты")
    os.environ.pop("ALLOW_DANGEROUS_COMMANDS", None)
    t = make_transport("reboot")
    eq(t.execute_command("reboot"), False,
       "reboot заблокирован без ALLOW_DANGEROUS_COMMANDS")
    check("ALLOW_DANGEROUS_COMMANDS" in t._command_error,
          "причина называет нужную переменную", repr(t._command_error))


def test_run_pending_command_reports_reason() -> None:
    print("\ntransport.py: run_pending_command отправляет причину на панель")
    os.environ.pop("ALLOW_DANGEROUS_COMMANDS", None)
    os.environ["ALLOW_AGENT_UPDATES"] = "false"
    try:
        t = make_transport("update-agent")
        t.run_pending_command()
        failed = [r for r in t.reported if r.get("status") == "failed"]
        check(bool(failed), "панели отправлен статус failed",
              json.dumps(t.reported, ensure_ascii=False)[:200])
        if failed:
            eq(failed[0]["cmd"], "update-agent", "в статусе указана исходная команда")
            check("ALLOW_AGENT_UPDATES" in str(failed[0].get("result", "")),
                  "в result ушла причина блокировки", repr(failed[0].get("result")))
    finally:
        os.environ.pop("ALLOW_AGENT_UPDATES", None)


def test_check_update_error_propagates() -> None:
    print("\ntransport.py: ошибка check-agent-update доходит до статуса")
    from transport import TransportMixin

    class T(TransportMixin):
        def check_agent_update(self):
            return {"ok": False, "error": "origin недоступен: could not resolve host"}

        def report_agent_update(self, payload):
            pass

    t = T()
    eq(t.execute_command("check-agent-update"), False,
       "провал проверки возвращает False")
    check("could not resolve host" in getattr(t, "_command_error", ""),
          "причина перенесена из ответа в _command_error",
          repr(getattr(t, "_command_error", "")))


def main() -> int:
    base = pathlib.Path(tempfile.mkdtemp(prefix="hm_agent_update_"))
    try:
        test_pip_failure_rolls_back(base / "a")
        test_pip_success_updates(base / "b")
    finally:
        shutil.rmtree(base, ignore_errors=True)
    test_blocked_command_explains_why()
    test_update_agent_allowed_by_default()
    test_destructive_still_blocked_by_default()
    test_run_pending_command_reports_reason()
    test_check_update_error_propagates()

    print(f"\n  Итог: {PASSED} успешно, {FAILED} провалено")
    return 0 if FAILED == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
