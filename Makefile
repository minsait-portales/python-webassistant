# Atajos de desarrollo y operación de WebAssistant. `make help` lista los objetivos.
.DEFAULT_GOAL := help
COMPOSE ?= docker compose
PROD = $(COMPOSE) -f compose.yaml -f compose.prod.yaml

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-18s %s\n", $$1, $$2}'

install: ## Instala dependencias Python (uv) y Node (npm)
	uv sync --all-packages
	npm ci

build: ## Compila SDK, widget y consola
	npm run build

up: ## Levanta la plataforma completa con Docker Compose (demo en http://localhost:8000/demo/)
	$(COMPOSE) up -d --build

up-observability: ## Igual que up, con Prometheus (9090), Grafana (3000) y Jaeger (16686)
	$(COMPOSE) --profile observability up -d --build

up-prod: ## Producción con compose.prod.yaml (requiere .env con secretos)
	$(PROD) up -d --build

down: ## Para los contenedores (conserva los datos)
	$(COMPOSE) --profile observability down

reset: ## Para los contenedores y BORRA los volúmenes de datos
	$(COMPOSE) --profile observability down -v

logs: ## Sigue los logs de API y worker
	$(COMPOSE) logs -f api worker

ps: ## Estado de los servicios
	$(COMPOSE) ps

shell: ## Consola en el contenedor de la API (CLI webassistant)
	$(COMPOSE) exec api bash

dev: ## API local sin Docker (SQLite, Qdrant en memoria, demo) en http://localhost:8000
	WEBASSISTANT_DEMO_ENABLED=true uv run --project apps/api uvicorn --factory webassistant.api.app:app_factory --reload --port 8000

lint: ## Ruff, mypy, ESLint y Prettier
	uv run ruff check . && uv run ruff format --check . && uv run mypy apps/api/src apps/worker/src packages/skill-sdk/src
	npx eslint . && npx prettier --check "**/*.{ts,mjs,json,css,js}" --ignore-path .prettierignore

test: ## Pruebas unitarias e integración (Python y TypeScript)
	uv run pytest
	npm test

e2e: ## Pruebas E2E con Playwright + axe (widget y consola)
	npm run build -w @webassistant/widget && npm run build -w @webassistant/admin
	npm run e2e

openapi: ## Regenera el contrato OpenAPI y los tipos del SDK
	uv run --project apps/api webassistant export-openapi
	npm run generate:sdk

demo-assets: ## Regenera catálogo, guías, imágenes y PDFs de la web de demo
	python3 scripts/generate_demo_site.py

backup: ## Copia de seguridad de PostgreSQL y Qdrant en ./backups
	./scripts/backup.sh

.PHONY: help install build up up-observability up-prod down reset logs ps shell dev lint test e2e openapi demo-assets backup
