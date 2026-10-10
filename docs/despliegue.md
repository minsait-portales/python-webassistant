# Despliegue y operación (CG-19.1)

Esta guía distingue el **piloto no HA** (Docker Compose en un servidor) de la **arquitectura objetivo de
producción** (servicios separados con réplicas). Los ejemplos no contienen secretos reales.

## 1. Artefactos

| Artefacto | Origen | Uso |
| --- | --- | --- |
| Imagen `ghcr.io/minsait-portales/python-webassistant:<versión>` | `docker/Dockerfile` (workflow `release.yml`) | API (`uvicorn`), worker (`webassistant-worker`), migraciones y CLI (`webassistant`) |
| Activos estáticos | Incluidos en la imagen | `/widget/v1/loader.js`, bundles con hash, `/admin/`, `/demo/` |
| Migraciones | `apps/api/migrations` (Alembic) | `webassistant migrate` antes de cada versión |
| Configuración | Variables `WEBASSISTANT_*` ([referencia](configuracion.md)) | Por entorno; secretos en el gestor de secretos |
| Paquete de configuración | `webassistant config-export` / consola | Promoción de assistants, agentes, sites y precios entre entornos |
| SBOM y procedencia | Release de GitHub | Auditoría de la cadena de suministro |

La imagen se ejecuta como usuario sin privilegios (uid 10001), con el sistema de ficheros de solo
lectura y `/tmp` en memoria.

## 2. Piloto no HA con Docker Compose

### 2.1 Requisitos

- Servidor Linux con Docker Engine ≥ 24 y Compose v2, 4 vCPU, 8 GB de RAM y 50 GB de disco para un
  piloto de hasta 50.000 documentos (ver [NFR](requisitos/nfr.md)).
- Dominio con registro DNS al servidor y puertos 80/443 abiertos (certificado automático con ACME).
- Almacenamiento externo cifrado para las copias (S3 compatible, NAS de otra ubicación…).

### 2.2 Instalación

```bash
git clone https://github.com/minsait-portales/python-webassistant.git && cd python-webassistant
cp .env.example .env
# Edita .env: WEBASSISTANT_DOMAIN, ACME_EMAIL, WEBASSISTANT_PUBLIC_BASE_URL=https://<dominio>,
# secretos (JWT, clave Fernet, contraseñas de PostgreSQL y Qdrant), OPENAI_API_KEY,
# administrador inicial y WEBASSISTANT_INGEST_ALLOWED_DOWNLOAD_HOSTS si se ingieren referencias.
python3 -c "import secrets; print(secrets.token_urlsafe(48))"        # WEBASSISTANT_JWT_SECRET
docker compose run --rm --no-deps api webassistant generate-key     # WEBASSISTANT_ENCRYPTION_KEY
docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait
```

`compose.prod.yaml` exige los secretos (el arranque falla si falta alguno), activa el entorno
`production`, desactiva la demo y el proveedor simulado, activa la API key de Qdrant, limita recursos y
publica **solo el gateway HTTPS**: PostgreSQL, Redis, Qdrant, API y worker quedan en la red interna.
Para un entorno de preproducción sin dominio público usa `WEBASSISTANT_TLS=internal` (CA local de Caddy).

Primeros pasos tras arrancar:

1. Entra en `https://<dominio>/admin/` con el administrador inicial y cambia su contraseña.
2. Crea el tenant, el site (orígenes del portal), las bases de conocimiento y el assistant, o importa un
   paquete de configuración (simulación previa).
3. Crea un cliente API para el backend del portal (el secreto se muestra una sola vez).
4. Instala la etiqueta `<script>` en el portal ([integración](integracion.md)).

### 2.3 Observabilidad

```bash
docker compose -f compose.yaml -f compose.prod.yaml --profile observability up -d
```

Prometheus (`127.0.0.1:9090`) con las [alertas](../deploy/observability/alerts.yml), Grafana
(`127.0.0.1:3000`, cuadro *WebAssistant · Visión general*) y Jaeger (`127.0.0.1:16686`, trazas con
`WEBASSISTANT_OTEL_ENABLED=true` y `WEBASSISTANT_OTEL_EXPORTER_ENDPOINT=http://jaeger:4318`). Se publican
solo en localhost: accede por túnel SSH o VPN. En el piloto el alertado comparte servidor con la
aplicación; para producción debe estar en un destino independiente.

### 2.4 Actualización y rollback

```bash
git fetch --tags && git checkout v1.0.1                        # o cambia WEBASSISTANT_IMAGE a la nueva etiqueta
./scripts/backup.sh                                            # copia previa obligatoria
docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait   # migra y sustituye
```

El servicio `migrate` aplica Alembic antes de arrancar API y worker. **Rollback**: vuelve a la etiqueta
anterior; si la versión nueva incluía migraciones no compatibles hacia atrás (se indica en el
[CHANGELOG](../CHANGELOG.md)), restaura además la copia previa. Las versiones de assistants se revierten
desde la consola (restaurar versión) sin desplegar. Los bundles antiguos del widget siguen servidos.

## 3. Producción (arquitectura objetivo)

| Componente | Recomendación | Condición de operación |
| --- | --- | --- |
| Entrada | Balanceador/Ingress TLS gestionado | Health checks, sin buffer para SSE (180 s de lectura), timeout de inactividad < 75 s (keep-alive de la API) |
| API | ≥ 2 réplicas en nodos y zonas distintos (HPA 2–6), PDB | `readiness` en `/health/ready`, despliegue sin indisponibilidad |
| Worker | Despliegue propio (≥ 2 réplicas), 15 min de gracia | Trabajos idempotentes y reintentos; métricas en `:9100` |
| PostgreSQL | Servicio gestionado con réplica y PITR | Rol de aplicación sin `BYPASSRLS`; copias en otra región |
| Qdrant | Clúster o servicio gestionado, privado, con API key | Snapshots externos y conciliación con PostgreSQL |
| Redis | Gestionado con persistencia | `noeviction`; reanudación de trabajos |
| Observabilidad | Prometheus/Grafana/OTel fuera del clúster de aplicación | Alertas con destino independiente |

`deploy/k8s` contiene la referencia en Kustomize (`overlays/staging` y `overlays/production`): API con
`topologySpreadConstraints`, PDB, HPA, worker separado, Job de migraciones (hook `PreSync`), Ingress con
cert-manager, NetworkPolicies y `ServiceMonitor`. Los secretos se generan desde `secrets.env` (no
versionado) o se sustituyen por External Secrets/Sealed Secrets.

```bash
kubectl apply -k deploy/k8s/overlays/production
```

## 4. Copias de seguridad y recuperación

### 4.1 Qué se copia

| Dato | Método | Frecuencia piloto | Responsable |
| --- | --- | --- | --- |
| PostgreSQL (configuración, conversaciones, auditoría, uso, documentos y fragmentos) | `pg_dump -Fc` | Diaria | Operación del producto |
| Qdrant (puntos, vectores y payloads por colección) | Snapshots por colección | Diaria | Operación del producto |
| Secretos (`.env`, gestor de secretos) | Copia cifrada aparte | En cada cambio | Operación del producto |
| Originales PDF/Word y fuentes del portal | Política del portal | — | **Portal** (dependencia externa) |

```bash
./scripts/backup.sh                      # backups/<marca UTC>/postgres.dump, qdrant/*.snapshot, SHA256SUMS
```

Programa la copia con `cron`/systemd y súbela cifrada fuera del servidor (p. ej. `restic` o `rclone`
con cifrado). Una copia en el mismo disco no protege de la pérdida del servidor.

### 4.2 Restauración (orden)

1. Servidor limpio con la misma versión de imagen y la configuración versionada; restaura los secretos.
2. `docker compose up -d postgres redis qdrant` (sin API ni worker).
3. `./scripts/restore.sh backups/<marca>`: verifica sumas, restaura PostgreSQL y después Qdrant, y
   arranca worker y API.
4. Verifica antes de abrir tráfico: `/health/ready`, conteos de documentos y puntos (consola →
   Conocimiento), login de administración, una conversación con citas, una ingesta y una búsqueda.
5. Concilia PostgreSQL ↔ Qdrant desde la consola y, si el portal cambió contenidos desde la copia,
   relanza su sincronización (inventario).

Si Qdrant no puede restaurarse, se reconstruye desde PostgreSQL con la reindexación (Plataforma →
Índices vectoriales) o desde el portal; consume tiempo y API de embeddings.

### 4.3 Objetivos y evidencias

| Entorno | RPO | RTO | Ensayo |
| --- | --- | --- | --- |
| Piloto | 24 h | 4 h | Ensayado: borrado de colección y documentos, `restore.sh`, consulta con citas correcta |
| Producción | ≤ 1 h (PITR + snapshots horarios) | ≤ 1 h | Obligatorio antes de promover la release |

## 5. Operación diaria

- **Logs** JSON por contenedor (`docker compose logs -f api worker`); busca por `run_id`,
  `conversation_id` o `correlation_id` (cabecera `X-Request-Id`).
- **Alertas y respuesta**: [runbooks](runbooks.md).
- **Retención**: el worker la aplica cada noche (03:15 UTC) y la conciliación a las 04:00 UTC.
- **Rotación de secretos**: JWT con `WEBASSISTANT_JWT_PREVIOUS_SECRETS` (sin cerrar sesiones);
  clientes API desde la consola; clave Fernet: los payloads antiguos dejan de poder leerse (caducan por
  retención).
- **Coste**: consola → Uso y costes; alertas de presupuesto en Prometheus.
