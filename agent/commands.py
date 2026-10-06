# commands.py - обработка команд агента
import json
from typing import Optional, Dict, Any

try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


class CommandsMixin:
    def check_commands(self):
        """Проверить и выполнить команды с панели (новый канал /api/nodes/command.php)"""
        if not hasattr(self, 'auth') or not getattr(self, 'auth'):
            # fallback: старый канал в transport.py уже есть, но сюда можно добавить новый
            pass

    def report_command(self, command_id: int | None, command: str, status: str, result: str = ""):
        try:
            data = {
                "action": "report",
                "command": command,
                "status": status,
                "result": result[:4000],
            }
            if command_id is not None:
                data["command_id"] = command_id
            url = f"{self.master_url}/api/nodes/command.php"
            resp = _request_with_retry(
                "POST",
                url,
                json=data,
                headers=getattr(self, 'signed_headers', getattr(self, 'headers', {})),
                verify=_get_verify(),
            )
            if resp and resp.status_code in (200, 201):
                _log(f"Command report sent: {command}={status}")
            else:
                _log(f"Command report failed: {command} status={resp.status_code if resp else 'noresp'}")
        except Exception as e:
            _log(f"Command report error: {e}")
