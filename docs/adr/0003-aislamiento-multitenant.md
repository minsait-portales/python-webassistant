# ADR 0003 · Aislamiento multi-tenant

**Contexto.** Varios tenants y sites comparten despliegue. Una fuga de conversaciones, documentos o
acciones entre tenants es el riesgo más grave del producto.

**Decisión.** Tres capas independientes:
1. **PostgreSQL**: `tenant_id` en todas las tablas de tenant con `ENABLE` + `FORCE ROW LEVEL SECURITY`;
   la sesión fija `app.tenant_id` por transacción; la aplicación usa un rol sin `BYPASSRLS`; las
   operaciones de plataforma activan explícitamente `app.bypass_rls`.
2. **Repositorios**: filtro por tenant en todas las consultas y unidad de trabajo ligada al tenant.
3. **Qdrant**: `SearchScope` obligatorio (tenant, site, bases permitidas, ACL, idioma) con índices de
   payload `is_tenant`; no hay búsqueda sin filtro.

Los tokens llevan tenant, site, sujeto, scopes y origen; el site valida el origen (CORS) en cada
petición del widget.

**Alternativas.** Base de datos por tenant (más aislamiento, mucha más operación); colección de Qdrant
por tenant (no escala a muchos tenants pequeños; se puede adoptar para tenants grandes).

**Consecuencias.** Pruebas negativas de aislamiento en SQLite y en PostgreSQL real (RLS) y sondas de
aislamiento en el ensayo de carga (CG-15.10).
