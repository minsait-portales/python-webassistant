# Release (CG-16.4 y CG-16.5)

## Versionado

- **SemVer** del producto (`MAJOR.MINOR.PATCH`, preversiones `-rc.N`). La versión está en
  `pyproject.toml` (Python, formato PEP 440: `1.0.0rc1`) y `package.json` (`1.0.0-rc.1`).
- La API (`/v1`), el widget (`/widget/v1`), los contratos y el manifiesto de skills tienen su propia
  política de compatibilidad ([integración](integracion.md#compatibilidad-y-versionado)).
- Cada versión documenta en el [CHANGELOG](../CHANGELOG.md) sus cambios, migraciones y si admite
  rollback sin restaurar datos.

## Cómo se publica

1. Rama `release/x.y.z` desde `main`; actualiza versiones y CHANGELOG.
2. CI en verde (calidad, pruebas en SQLite y PostgreSQL, E2E en tres navegadores, seguridad, Kubernetes y
   stack Compose con humo, backup/restore y carga breve).
3. Etiqueta `vX.Y.Z`: el workflow `release.yml` construye y publica la imagen en GHCR con **SBOM** y
   **procedencia** (attestation), genera SBOM CycloneDX de dependencias Python y Node y crea la release
   con las notas del CHANGELOG y el contrato OpenAPI.

## Checklist de la release candidata (preproducción / piloto)

| # | Comprobación | Evidencia |
| --- | --- | --- |
| 1 | Artefactos: imagen etiquetada, SBOM, procedencia, OpenAPI y notas | Release de GitHub |
| 2 | Migraciones aplicadas (`webassistant migrate`) y reversibles | Job `migrate`, prueba `test_postgres` |
| 3 | Variables y secretos del entorno completos (el arranque valida los obligatorios) | `compose.prod.yaml` / overlay |
| 4 | Health checks: `/health/live`, `/health/ready`, worker `--check` | `docker compose ps` / sondas |
| 5 | Observabilidad: métricas de API y worker, alertas cargadas, cuadro de mando, trazas | Prometheus, Grafana, Jaeger |
| 6 | Backup externo ejecutado y **restauración ensayada** en entorno limpio | `scripts/backup.sh`, `scripts/restore.sh`, registro de RPO/RTO |
| 7 | Rollback ensayado (versión anterior de imagen y de assistant) | [Runbook](runbooks.md#rollback) |
| 8 | CG-14: axe sin incidencias y checklist WCAG firmado tras la revisión manual | [Accesibilidad](accesibilidad.md) |
| 9 | CG-15: pruebas funcionales, exploratorias y regresión sin bloqueantes; informe de salida | [Plan de pruebas](pruebas/plan-de-pruebas.md) |
| 10 | CG-15.10: carga, SAST, dependencias, DAST y aislamiento | [Informe](pruebas/carga-y-seguridad.md) |
| 11 | Entorno identificado (preproducción/piloto) y sus objetivos de recuperación | [NFR](requisitos/nfr.md) |
| 12 | Demo autónoma operativa con datos semilla (solo fuera de producción) | `/demo/` |

## Condiciones de promoción a producción

La release candidata **no** atribuye alta disponibilidad al servidor del piloto. Para producción se
requiere además:

1. Arquitectura objetivo desplegada (API ≥ 2 réplicas en dominios de fallo distintos, worker separado,
   datos gestionados y privados, alertado independiente) — [despliegue §3](despliegue.md#3-producción-arquitectura-objetivo).
2. NFR de producción acordados y medidos en ese entorno (disponibilidad, RPO/RTO, carga).
3. Prueba de recuperación con la arquitectura y los objetivos de producción.
4. Responsables de operación y guardia asignados; runbooks revisados.
5. Pentest independiente y auditoría externa de accesibilidad si el cliente los exige (fuera del
   alcance del desarrollo).

## Rollback

- **Aplicación**: desplegar la etiqueta anterior. Si la versión nueva aplicó migraciones no compatibles
  hacia atrás (indicado en el CHANGELOG), restaurar la copia previa al despliegue.
- **Configuración funcional**: restaurar la versión anterior del assistant desde la consola.
- **Índice vectorial**: volver a la versión anterior desde Plataforma → Índices vectoriales.
- **Widget**: los bundles anteriores siguen publicados; el loader apunta al bundle de su versión.
