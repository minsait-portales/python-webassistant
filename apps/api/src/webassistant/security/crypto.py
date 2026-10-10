"""Hash de contraseñas y secretos de cliente, y cifrado de payloads auditables (CG-03.6, CG-12.4)."""

from __future__ import annotations

import base64
import hashlib
import hmac
import secrets

from cryptography.fernet import Fernet, InvalidToken

_SCRYPT_N = 2**14
_SCRYPT_R = 8
_SCRYPT_P = 1


def hash_secret(secret: str) -> str:
    salt = secrets.token_bytes(16)
    digest = hashlib.scrypt(
        secret.encode("utf-8"), salt=salt, n=_SCRYPT_N, r=_SCRYPT_R, p=_SCRYPT_P, dklen=32
    )
    return f"scrypt${_SCRYPT_N}${_SCRYPT_R}${_SCRYPT_P}${base64.b64encode(salt).decode()}${base64.b64encode(digest).decode()}"


def verify_secret(secret: str, encoded: str) -> bool:
    try:
        algorithm, n, r, p, salt_b64, digest_b64 = encoded.split("$")
    except ValueError:
        return False
    if algorithm != "scrypt":
        return False
    salt = base64.b64decode(salt_b64)
    expected = base64.b64decode(digest_b64)
    candidate = hashlib.scrypt(
        secret.encode("utf-8"), salt=salt, n=int(n), r=int(r), p=int(p), dklen=len(expected)
    )
    return hmac.compare_digest(candidate, expected)


def password_problems(password: str) -> list[str]:
    problems = []
    if len(password) < 12:
        problems.append("La contraseña debe tener al menos 12 caracteres.")
    if password.lower() == password or password.upper() == password:
        problems.append("La contraseña debe combinar mayúsculas y minúsculas.")
    if not any(ch.isdigit() for ch in password):
        problems.append("La contraseña debe incluir algún número.")
    return problems


class PayloadCipher:
    """Cifrado simétrico (Fernet) para payloads técnicos de acceso restringido."""

    def __init__(self, key: str | None) -> None:
        if key:
            self._fernet = Fernet(key.encode() if isinstance(key, str) else key)
        else:
            # Desarrollo: clave efímera por proceso; los payloads no sobreviven reinicios.
            self._fernet = Fernet(Fernet.generate_key())
        self.ephemeral = not key

    def encrypt(self, value: str) -> str:
        return self._fernet.encrypt(value.encode("utf-8")).decode("ascii")

    def decrypt(self, token: str) -> str | None:
        try:
            return self._fernet.decrypt(token.encode("ascii")).decode("utf-8")
        except InvalidToken:
            return None


def generate_encryption_key() -> str:
    return Fernet.generate_key().decode("ascii")
