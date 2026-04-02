from __future__ import annotations

from cryptography.fernet import Fernet

from app.settings import settings


def _get_fernet() -> Fernet:
    key = settings.app_encryption_key.strip()
    if not key:
        raise ValueError("APP_ENCRYPTION_KEY is required")
    return Fernet(key.encode("utf-8"))


def encrypt_secret(secret: str) -> str:
    token = _get_fernet().encrypt(secret.encode("utf-8"))
    return token.decode("utf-8")


def decrypt_secret(token: str) -> str:
    secret = _get_fernet().decrypt(token.encode("utf-8"))
    return secret.decode("utf-8")

