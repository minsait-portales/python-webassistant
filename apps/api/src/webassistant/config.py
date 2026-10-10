"""Configuración del servicio validada al arrancar (CG-01.4).

Todos los valores llegan por variables de entorno con prefijo ``WEBASSISTANT_``.
El servicio falla de forma explícita si falta configuración obligatoria o si una
combinación es insegura en un entorno que no sea de desarrollo.
"""

from __future__ import annotations

from enum import StrEnum
from functools import lru_cache
from pathlib import Path
from typing import Annotated, Literal

from pydantic import Field, SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict


class Environment(StrEnum):
    DEVELOPMENT = "development"
    TEST = "test"
    STAGING = "staging"
    PRODUCTION = "production"


class ConfigurationError(RuntimeError):
    """Configuración obligatoria ausente o insegura."""


_INSECURE_SECRETS = {"", "change-me", "changeme", "secret", "dev-secret", "development"}


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="WEBASSISTANT_",
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    environment: Environment = Environment.DEVELOPMENT
    service_name: str = "webassistant-api"
    public_base_url: str = "http://localhost:8000"
    log_level: str = "INFO"
    log_json: bool = True

    # Persistencia relacional (PostgreSQL en piloto y producción).
    database_url: str = "sqlite+aiosqlite:///./data/webassistant.sqlite3"
    database_pool_size: int = 10
    database_max_overflow: int = 10
    database_echo: bool = False
    database_rls: bool = True

    # Caché, locks, rate limit y cola.
    redis_url: str | None = None
    queue_mode: Literal["redis", "inline"] = "inline"
    queue_name: str = "webassistant:jobs"

    # Índice vectorial.
    qdrant_url: str | None = None
    qdrant_api_key: SecretStr | None = None
    qdrant_location: str | None = ":memory:"
    qdrant_collection_prefix: str = "wa_chunks"
    qdrant_timeout_seconds: float = 10.0

    # Identidad y tokens.
    jwt_secret: SecretStr = SecretStr("")
    # Secretos anteriores aceptados solo para verificar durante una rotación.
    jwt_previous_secrets: Annotated[list[SecretStr], NoDecode] = Field(default_factory=list)
    jwt_issuer: str = "webassistant"
    widget_token_ttl_seconds: int = 900
    server_token_ttl_seconds: int = 600
    admin_token_ttl_seconds: int = 3600
    confirmation_token_ttl_seconds: int = 120
    anonymous_session_ttl_days: int = 30
    encryption_key: SecretStr = SecretStr("")

    # Proveedor de modelos.
    default_provider: str = "simulated"
    default_chat_model: str = "gpt-6-luna"
    default_embedding_model: str = "text-embedding-3-small"
    embedding_dimension: int = 1536
    openai_api_key: SecretStr | None = None
    openai_base_url: str | None = None
    openai_organization: str | None = None
    provider_timeout_seconds: float = 60.0
    provider_max_retries: int = 2
    circuit_breaker_failures: int = 5
    circuit_breaker_reset_seconds: float = 30.0

    # Skills.
    skill_paths: Annotated[list[Path], NoDecode] = Field(default_factory=list)
    load_builtin_skills: bool = True
    load_entry_point_skills: bool = True

    # Protección de la API.
    max_request_bytes: int = 1_000_000
    max_ingest_request_bytes: int = 25_000_000
    request_timeout_seconds: float = 120.0
    rate_limit_site_per_minute: int = 600
    rate_limit_subject_per_minute: int = 30
    rate_limit_ip_per_minute: int = 120
    rate_limit_admin_per_minute: int = 300
    trusted_proxies: Annotated[list[str], NoDecode] = Field(default_factory=list)
    cors_cache_seconds: int = 60

    # Ingesta y RAG.
    ingest_max_download_bytes: int = 50_000_000
    ingest_download_timeout_seconds: float = 30.0
    ingest_allowed_download_hosts: Annotated[list[str], NoDecode] = Field(default_factory=list)
    ingest_temp_dir: Path | None = None
    rag_default_limit: int = 8
    rag_default_min_score: float = 0.25

    # Activos estáticos.
    widget_dist_dir: Path | None = None
    admin_dist_dir: Path | None = None
    demo_enabled: bool = False

    # Observabilidad.
    metrics_enabled: bool = True
    metrics_token: SecretStr | None = None
    otel_enabled: bool = False
    otel_exporter_endpoint: str | None = None

    # Datos iniciales.
    bootstrap_admin_email: str | None = None
    bootstrap_admin_password: SecretStr | None = None

    @field_validator(
        "skill_paths",
        "trusted_proxies",
        "ingest_allowed_download_hosts",
        "jwt_previous_secrets",
        mode="before",
    )
    @classmethod
    def _split_list(cls, value: object) -> object:
        if isinstance(value, str):
            return [item.strip() for item in value.split(",") if item.strip()]
        return value

    @property
    def is_production_like(self) -> bool:
        return self.environment in {Environment.STAGING, Environment.PRODUCTION}

    @property
    def is_postgres(self) -> bool:
        return self.database_url.startswith("postgresql")

    @model_validator(mode="after")
    def _validate_security(self) -> Settings:
        problems: list[str] = []
        jwt_secret = self.jwt_secret.get_secret_value()
        if self.is_production_like:
            if jwt_secret.lower() in _INSECURE_SECRETS or len(jwt_secret) < 32:
                problems.append(
                    "WEBASSISTANT_JWT_SECRET debe tener al menos 32 caracteres aleatorios."
                )
            if not self.encryption_key.get_secret_value():
                problems.append("WEBASSISTANT_ENCRYPTION_KEY es obligatoria (clave Fernet).")
            if not self.is_postgres:
                problems.append("WEBASSISTANT_DATABASE_URL debe apuntar a PostgreSQL.")
            if not self.redis_url:
                problems.append("WEBASSISTANT_REDIS_URL es obligatoria.")
            if self.queue_mode != "redis":
                problems.append("WEBASSISTANT_QUEUE_MODE debe ser 'redis'.")
            if not self.qdrant_url:
                problems.append("WEBASSISTANT_QDRANT_URL es obligatoria (Qdrant como servicio).")
            if self.default_provider == "simulated" and self.environment == Environment.PRODUCTION:
                problems.append("El proveedor simulado no puede usarse en producción.")
            if self.demo_enabled and self.environment == Environment.PRODUCTION:
                problems.append("WEBASSISTANT_DEMO_ENABLED no puede activarse en producción.")
        if self.default_provider == "openai" and not self.openai_api_key:
            problems.append("WEBASSISTANT_OPENAI_API_KEY es obligatoria con el proveedor openai.")
        if problems:
            raise ConfigurationError("Configuración inválida:\n- " + "\n- ".join(problems))
        return self

    def effective_jwt_secret(self) -> str:
        """Secreto de firma; en desarrollo se deriva uno estable si falta."""
        secret = self.jwt_secret.get_secret_value()
        if secret:
            return secret
        return "dev-only-insecure-jwt-secret-change-me-0123456789"


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()


def reset_settings_cache() -> None:
    get_settings.cache_clear()
