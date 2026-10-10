# ADR 0011 · Topología: piloto no HA y objetivo de producción

**Decisión.** El piloto se despliega con Docker Compose en un servidor Linux: gateway HTTPS (único
puerto público), API, worker, PostgreSQL, Redis, Qdrant y observabilidad en contenedores separados con
volúmenes y límites. **No es alta disponibilidad**: el servidor es un punto único de fallo.

Producción separa servicios y entornos: API con al menos dos réplicas en nodos/zonas distintos detrás de
un balanceador TLS, worker independiente, PostgreSQL/Redis/Qdrant gestionados o replicados y privados,
observabilidad y alertado fuera del nodo de aplicación. `deploy/k8s` contiene la referencia en Kustomize.

**Consecuencias.** La aceptación del piloto no acredita los SLO de producción; la promoción exige
verificar NFR y recuperación en la arquitectura objetivo ([release](../release.md)).
