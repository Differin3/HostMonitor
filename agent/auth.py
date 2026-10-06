# auth.py - Ed25519 аутентификация агента
# Формат подписи: METHOD\nPATH\nTIMESTAMP\nBODY (UTF-8)
# Заголовки: X-Agent-Node-Id, X-Agent-Timestamp, X-Agent-Signature (base64url/base64)
import base64
import json
import os
import time
from pathlib import Path
from typing import Optional, Tuple

try:
    from cryptography.exceptions import InvalidSignature
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    from cryptography.hazmat.primitives import serialization
except Exception:  # pragma: no cover
    Ed25519PrivateKey = None
    InvalidSignature = Exception
    serialization = None
    Ed25519PublicKey = None

try:
    from .runtime import _log
except ImportError:
    from runtime import _log


def _b64url_to_b64(s: str) -> str:
    s = s.rstrip('=')
    # base64url -> base64
    s2 = s.replace('-', '+').replace('_', '/')
    pad = len(s2) % 4
    if pad == 2:
        s2 += '=='
    elif pad == 3:
        s2 += '='
    elif pad != 0:
        s2 += '=' * ((4 - pad) % 4)
    return s2


class AgentAuth:
    def __init__(self, node_id: int, private_key_b64: str, public_key_b64: str | None = None):
        self.node_id = int(node_id)
        self._sk: Optional[Ed25519PrivateKey] = None
        self._seed_b64 = private_key_b64
        if Ed25519PrivateKey is not None:
            try:
                seed = base64.b64decode(private_key_b64)
                if len(seed) == 32:
                    self._sk = Ed25519PrivateKey.from_private_bytes(seed)
            except Exception as e:
                _log(f"auth: failed to load private key: {e}")
                self._sk = None
        else:
            self._sk = None  # cryptography required

    @property
    def can_sign(self) -> bool:
        return self._sk is not None

    @staticmethod
    def build_message(method: str, path: str, timestamp: int | str, body: bytes | str = b"") -> bytes:
        if isinstance(body, str):
            body_b = body.encode("utf-8", errors="replace")
        elif isinstance(body, bytes):
            body_b = body
        else:
            body_b = b""
        m = f"{method.upper()}\n{path}\n{str(timestamp)}\n"
        return m.encode("utf-8", errors="replace") + body_b

    def sign(self, method: str, path: str, timestamp: int | str, body: bytes | str = b"") -> Tuple[str, int]:
        ts = int(timestamp)
        msg = self.build_message(method, path, ts, body)
        if not self.can_sign:
            raise RuntimeError("agent auth: signing requires cryptography (Ed25519 private key)")
        sig = self._sk.sign(msg)
        return base64.b64encode(sig).decode("ascii"), ts

    def sign_request(self, method: str, url_path: str, body: bytes | str = b"", timestamp: Optional[int] = None) -> dict:
        ts = int(time.time()) if timestamp is None else int(timestamp)
        msg = self.build_message(method, url_path, ts, body)
        sig_b64, _ = self.sign(method, url_path, ts, body)
        return {
            "X-Agent-Node-Id": str(self.node_id),
            "X-Agent-Timestamp": str(ts),
            "X-Agent-Signature": sig_b64,
        }

    @staticmethod
    def verify(pubkey_pem: str, method: str, path: str, timestamp: int, body: bytes | str, sig_b64: str) -> bool:
        if Ed25519PrivateKey is None:
            return False
        try:
            # extract raw 32 bytes from SPKI PEM
            from cryptography.hazmat.primitives import serialization
            pub = serialization.load_pem_public_key(pubkey_pem.encode("utf-8"))
            if not isinstance(pub, Ed25519PublicKey if 'Ed25519PublicKey' in globals() else object):
                pass  # but cryptography gives Ed25519PublicKey
            # easier: try to get raw via public_bytes
            raw = pub.public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)
            pub2 = Ed25519PrivateKey  # no; use class from lib
        except Exception:
            pass
        # proper
        try:
            pubk = serialization.load_pem_public_key(pubkey_pem.encode("utf-8", errors="replace"))
            msg = AgentAuth.build_message(method, path, timestamp, body)
            sig = base64.b64decode(_b64url_to_b64(sig_b64))
            if len(sig) != 64:
                try:
                    sig = base64.b64decode(sig_b64)
                except Exception:
                    pass
            try:
                pubk.verify(sig, msg)
                return True
            except Exception:
                return False
        except Exception:
            return False
