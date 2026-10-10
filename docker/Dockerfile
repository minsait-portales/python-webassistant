# syntax=docker/dockerfile:1.7
#
# Imagen única de WebAssistant (CG-13.1): API, worker, CLI y activos estáticos compilados
# (loader.js + widget con SRI, consola de administración y web de demostración).
#
#   docker build -f docker/Dockerfile -t webassistant .
#
# Proxy corporativo con inspección TLS: pasa su CA como secreto de build (no queda en la imagen):
#   docker build --secret id=extra_ca,src=/ruta/ca.crt -f docker/Dockerfile .
#   EXTRA_CA_CERTS=/ruta/ca.crt docker compose build

ARG PYTHON_IMAGE=python:3.12-slim
ARG NODE_IMAGE=node:22-alpine

# --- 1. Widget, consola y SDK TypeScript ------------------------------------------------------
FROM ${NODE_IMAGE} AS web
WORKDIR /src
COPY package.json package-lock.json .npmrc ./
COPY packages/sdk-js/package.json packages/sdk-js/
COPY apps/widget/package.json apps/widget/
COPY apps/admin/package.json apps/admin/
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=cache,target=/root/.npm \
    if [ -s /run/secrets/extra_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/extra_ca; fi; \
    npm ci --no-audit --no-fund --ignore-scripts && npm rebuild esbuild
COPY packages/sdk-js packages/sdk-js
COPY apps/widget apps/widget
COPY apps/admin apps/admin
RUN npm run build -w @webassistant/widget && npm run build -w @webassistant/admin

# --- 2. Entorno Python con uv (dependencias bloqueadas en uv.lock) ----------------------------
FROM ${PYTHON_IMAGE} AS python-build
ARG UV_VERSION=0.11.32
ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/app/.venv \
    PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /src
RUN --mount=type=secret,id=extra_ca,required=false \
    if [ -s /run/secrets/extra_ca ]; then cat /etc/ssl/certs/ca-certificates.crt /run/secrets/extra_ca > /tmp/ca.pem; export PIP_CERT=/tmp/ca.pem; fi; \
    pip install --no-cache-dir "uv==${UV_VERSION}" && rm -f /tmp/ca.pem
COPY pyproject.toml uv.lock README.md ./
COPY apps/api/pyproject.toml apps/api/README.md apps/api/
COPY apps/worker/pyproject.toml apps/worker/README.md apps/worker/
COPY packages/skill-sdk/pyproject.toml packages/skill-sdk/README.md packages/skill-sdk/
# Capa de dependencias de terceros (se reutiliza mientras no cambie uv.lock).
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=cache,target=/root/.cache/uv \
    if [ -s /run/secrets/extra_ca ]; then cat /etc/ssl/certs/ca-certificates.crt /run/secrets/extra_ca > /tmp/ca.pem; export SSL_CERT_FILE=/tmp/ca.pem; fi; \
    uv sync --frozen --no-dev --no-install-workspace --package webassistant-worker && rm -f /tmp/ca.pem
COPY packages/contracts packages/contracts
COPY packages/skill-sdk packages/skill-sdk
COPY apps/api apps/api
COPY apps/worker apps/worker
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=cache,target=/root/.cache/uv \
    if [ -s /run/secrets/extra_ca ]; then cat /etc/ssl/certs/ca-certificates.crt /run/secrets/extra_ca > /tmp/ca.pem; export SSL_CERT_FILE=/tmp/ca.pem; fi; \
    uv sync --frozen --no-dev --no-editable --package webassistant-worker && rm -f /tmp/ca.pem

# --- 3. Imagen final, sin herramientas de build y con usuario sin privilegios -----------------
FROM ${PYTHON_IMAGE} AS runtime
ARG VERSION=1.0.0-rc.1
LABEL org.opencontainers.image.title="WebAssistant" \
      org.opencontainers.image.description="Capa genérica WebAssistant: API, worker, widget y consola" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.vendor="Minsait"
ENV PATH=/app/.venv/bin:$PATH \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    WEBASSISTANT_LOG_JSON=true \
    WEBASSISTANT_WIDGET_DIST_DIR=/app/static/widget \
    WEBASSISTANT_ADMIN_DIST_DIR=/app/static/admin
RUN groupadd --system --gid 10001 webassistant \
    && useradd --system --uid 10001 --gid webassistant --home-dir /app --shell /usr/sbin/nologin webassistant
WORKDIR /app
COPY --from=python-build --chown=root:root /app/.venv /app/.venv
COPY --chown=root:root apps/api/alembic.ini /app/alembic.ini
COPY --chown=root:root apps/api/migrations /app/migrations
COPY --from=web --chown=root:root /src/apps/widget/dist /app/static/widget
COPY --from=web --chown=root:root /src/apps/admin/dist /app/static/admin
COPY --chown=root:root demo /app/static/demo
USER webassistant
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD ["python", "-c", "import sys, urllib.request; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health/live', timeout=4).status == 200 else 1)"]
CMD ["uvicorn", "--factory", "webassistant.api.app:app_factory", "--host", "0.0.0.0", "--port", "8000", "--proxy-headers", "--forwarded-allow-ips", "*", "--timeout-graceful-shutdown", "30"]
