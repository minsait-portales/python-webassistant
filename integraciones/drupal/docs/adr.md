# Decisiones del conector Drupal (ADR)

Formato breve: contexto, decisión y consecuencias. Complementan las ADR de la plataforma
([`docs/adr`](../../../docs/adr/README.md)).

## ADR-D01 · Solo Drupal 11.2 o superior

- **Contexto.** `capa-drupal.md` (§15) pedía Drupal 10 y 11. El encargo de este conector es Drupal 11, y
  las APIs que lo hacen mantenible (hooks OOP con `#[Hook]`, `runtime_requirements`, `RequirementSeverity`,
  orden de hooks y autowiring de plugins) solo existen completas desde 11.1/11.2.
- **Decisión.** `core_version_requirement: ^11.2`, PHP 8.3.
- **Consecuencias.** Un portal en Drupal 10 debe actualizar antes de instalar el conector (Drupal 10 deja
  de tener soporte en 2026). El criterio de aceptación DR-15 se adapta a "Drupal 11".

## ADR-D02 · Módulo nuevo que convive con `webassistant_ai`

- **Contexto.** DR-07 proponía refactorizar el módulo legado conservando el id del bloque. El legado mezcla
  IA local, Search API y skills; reutilizar sus ids arrastraría su configuración y sus dependencias.
- **Decisión.** Módulo nuevo `webassistant_connector` con ids propios. La transición se hace con
  `drush webassistant:migrate-legacy` (copia ajustes, crea bloques equivalentes en las mismas regiones y
  desactiva, sin borrar, los antiguos; `--rollback` lo revierte). `enabled: false` desactiva el conector sin
  desinstalarlo.
- **Consecuencias.** Ambos módulos pueden instalarse a la vez durante la transición; el rollback no
  necesita reinstalar nada. Retirar el legado es un paso explícito.

## ADR-D03 · Outbox de estado y cola de "tickets"

- **Contexto.** Nunca debe haber HTTP durante el guardado de entidades, ni pérdidas ni carreras entre
  eventos de la misma entidad (una despublicación procesada antes que una actualización anterior).
- **Decisión.** Una fila por (fuente, entidad, idioma) que se fusiona en la misma transacción del guardado;
  el trabajador decide upsert o delete con el **estado actual** de la entidad; reclamo con *lease* y
  `seq` para que una captura concurrente fuerce un nuevo procesado; la cola solo despierta al procesador.
- **Consecuencias.** Ráfagas de cambios terminan en el último estado correcto; un proceso que muere deja
  su *lease* caducar y otro retoma el trabajo.

## ADR-D04 · Versión basada en el contenido

- **Contexto.** La plataforma re-embebe cuando cambia `version`. Una versión por revisión re-embebía todas
  las traducciones en cada guardado.
- **Decisión.** `version = m{versión de mapping}.{16 hex del sha256 del contenido}` y supresión de envíos
  idénticos con el `payload_hash` guardado en el mapa local.
- **Consecuencias.** Solo cambia lo que cambia; los cambios de ACL o metadatos se aplican en la
  plataforma sin re-embeber.

## ADR-D05 · ACL desde el acceso real de Drupal

- **Contexto.** La ACL de cada documento debe reflejar quién puede ver el contenido en Drupal (DR-04.7) y
  el texto no puede incluir datos más restringidos que esa ACL.
- **Decisión.** Modo `access` por defecto: anónimo → `public`; autenticado sin roles extra →
  `authenticated`; si no, `role:<id>` de cada rol que puede verlo. El texto se renderiza con la cuenta menos
  privilegiada de la ACL, y los campos y referencias sin acceso se descartan. Modos `public`,
  `authenticated` y `field` para casos especiales. Un cambio de permisos recalcula las ACL.
- **Consecuencias.** Los permisos por usuario o grupo que no se expresan con roles (p. ej. el módulo Group)
  necesitan un suscriptor de `AclAlterEvent`; mientras tanto ese contenido no se indexa (falla cerrado).

## ADR-D06 · Límites de la API v1 asumidos

- No hay contexto de página en la API de conversación: el conector no envía el nodo o producto actual
  (DR-06.7 pendiente de la API).
- Las host actions no van firmadas por la plataforma: Drupal las trata como entrada no confiable y vuelve a
  comprobar permisos, acceso, parámetros e idempotencia.
- La plataforma descarga los originales sin *query*: la firma va en la ruta y, por defecto, no caduca; se
  revoca cuando el fichero deja de estar indexado (borrado, despublicado o sustituido).
