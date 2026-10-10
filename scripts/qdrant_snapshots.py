#!/usr/bin/env python3
"""Snapshots de Qdrant por colección (solo biblioteca estándar).

    python qdrant_snapshots.py backup  <directorio>
    python qdrant_snapshots.py restore <directorio>

Usa WEBASSISTANT_QDRANT_URL y WEBASSISTANT_QDRANT_API_KEY. Se ejecuta dentro de la red de
Compose o Kubernetes (ver scripts/backup.sh). PostgreSQL es la fuente de verdad: si se pierde
Qdrant también puede reconstruirse con la conciliación o la reindexación del worker.
"""

from __future__ import annotations

import json
import os
import sys
import urllib.request
import uuid
from pathlib import Path

BASE = os.environ.get("WEBASSISTANT_QDRANT_URL", "http://qdrant:6333").rstrip("/")
KEY = os.environ.get("WEBASSISTANT_QDRANT_API_KEY", "")


def request(
    method: str, path: str, body: bytes | None = None, headers: dict[str, str] | None = None
):
    req = urllib.request.Request(f"{BASE}{path}", data=body, method=method, headers=headers or {})
    if KEY:
        req.add_header("api-key", KEY)
    return urllib.request.urlopen(req, timeout=600)


def backup(target: Path) -> None:
    target.mkdir(parents=True, exist_ok=True)
    collections = json.load(request("GET", "/collections"))["result"]["collections"]
    for item in collections:
        name = item["name"]
        snapshot = json.load(request("POST", f"/collections/{name}/snapshots?wait=true"))["result"]
        with request("GET", f"/collections/{name}/snapshots/{snapshot['name']}") as response:
            (target / f"{name}.snapshot").write_bytes(response.read())
        request("DELETE", f"/collections/{name}/snapshots/{snapshot['name']}?wait=true").read()
        print(f"qdrant: {name} -> {name}.snapshot")


def restore(source: Path) -> None:
    for file in sorted(source.glob("*.snapshot")):
        name = file.stem
        boundary = uuid.uuid4().hex
        payload = (
            (
                f'--{boundary}\r\nContent-Disposition: form-data; name="snapshot"; filename="{file.name}"\r\n'
                "Content-Type: application/octet-stream\r\n\r\n"
            ).encode()
            + file.read_bytes()
            + f"\r\n--{boundary}--\r\n".encode()
        )
        request(
            "POST",
            f"/collections/{name}/snapshots/upload?wait=true&priority=snapshot",
            payload,
            {"Content-Type": f"multipart/form-data; boundary={boundary}"},
        ).read()
        print(f"qdrant: {file.name} restaurado en {name}")


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] not in {"backup", "restore"}:
        sys.exit(__doc__)
    (backup if sys.argv[1] == "backup" else restore)(Path(sys.argv[2]))
