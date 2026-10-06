#!/usr/bin/env python3
# enroll.py - обмен одноразового токена на Ed25519-ключ
import argparse
import base64
import json
import os
import sys
from pathlib import Path

try:
    import requests
except Exception:
    print("ERROR: requests not installed", file=sys.stderr)
    sys.exit(1)

try:
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    from cryptography.hazmat.primitives import serialization
except Exception:
    print("ERROR: cryptography not installed", file=sys.stderr)
    sys.exit(1)


def main():
    p = argparse.ArgumentParser(description="Enroll agent: exchange token -> Ed25519 keypair")
    p.add_argument("--master", required=True, help="https://panel.example/ or https://panel.example/monitoring")
    p.add_argument("--token", required=True, help="8-char enrollment token")
    p.add_argument("--name", help="override node name (optional)")
    p.add_argument("--config", default="node.conf", help="write config to this file")
    args = p.parse_args()

    master = args.master.rstrip("/")
    if not master.endswith("/api"):
        # если указали корень мониторинга
        if master.endswith("/monitoring"):
            enroll_url = master[:-len("/monitoring")] + "/api/enroll.php" if False else master + "/api/enroll.php"
            # проще: всегда /api/enroll.php относительно master? лучше: master/api/enroll.php
            pass
    enroll_url = master
    # нормализация
    if enroll_url.endswith("/monitoring"):
        enroll_url = enroll_url + "/api/enroll.php"
    elif enroll_url.endswith("/api"):
        enroll_url = enroll_url + "/enroll.php"
    elif not enroll_url.endswith("/enroll.php"):
        enroll_url = enroll_url + "/api/enroll.php"

    sk = Ed25519PrivateKey.generate()
    seed = sk.private_bytes(serialization.Encoding.Raw, serialization.PrivateFormat.Raw, serialization.NoEncryption())
    pub = sk.public_key()
    spki = pub.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)
    raw = pub.public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)

    payload = {
        "token": args.token,
        "public_key": "-----BEGIN PUBLIC KEY-----\n" + base64.b64encode(spki).decode().strip() + "\n-----END PUBLIC KEY-----\n",
        "name": args.name,
    }
    try:
        r = requests.post(enroll_url, json=payload, timeout=30, verify=False)  # verify managed by panel; allow self-signed
    except Exception as e:
        print(f"ERROR: request failed: {e}", file=sys.stderr)
        sys.exit(1)

    if r.status_code != 200:
        try:
            print(f"ERROR: enroll failed ({r.status_code}): {r.text}", file=sys.stderr)
        except Exception:
            print(f"ERROR: enroll failed ({r.status_code})", file=sys.stderr)
        sys.exit(1)

    try:
        j = r.json()
    except Exception:
        print(f"ERROR: bad json: {r.text}", file=sys.stderr)
        sys.exit(1)
    if not j.get("node_id"):
        print(f"ERROR: unexpected response: {j}", file=sys.stderr)
        sys.exit(1)

    print(f"OK node_id={j['node_id']} fingerprint={j.get('fingerprint')}")
    # write minimal config
    cfg_path = Path(args.config)
    content = []
    content.append(f"MASTER_URL={master}")
    content.append(f"NODE_ID={j['node_id']}")
    if j.get("name"):
        content.append(f"NODE_NAME={j['name']}")
    content.append(f"NODE_SECRET_B64={base64.b64encode(seed).decode()}")
    content.append(f"NODE_PUBLIC_KEY_B64={base64.b64encode(raw).decode()}")
    try:
        cfg_path.write_text("\n".join(content) + "\n", encoding="utf-8")
        print(f"Wrote {cfg_path}")
    except Exception as e:
        print(f"WARN: could not write {cfg_path}: {e}")

    # also write .env style for agent if needed
    return 0


if __name__ == "__main__":
    sys.exit(main())
