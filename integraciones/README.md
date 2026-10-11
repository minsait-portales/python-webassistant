# Integraciones con plataformas

Conectores que integran portales concretos con la capa genérica WebAssistant (API v1, widget y
contratos de [`docs/integracion.md`](../docs/integracion.md)). Cada conector vive en su carpeta, con su
código, pruebas, entorno de desarrollo y documentación; el núcleo de la plataforma no depende de ellos.

| Plataforma | Carpeta | Estado |
| --- | --- | --- |
| Drupal 11 | [`drupal/`](drupal/README.md) | Módulo `webassistant_connector` con extensiones Documents y Commerce |
| Liferay, Adobe Experience Manager, SharePoint, WordPress | — | Propuesta en la [matriz de integración](../docs/integraciones.md) |

Todos los conectores siguen las mismas reglas:

- **Secretos solo en servidor.** El navegador nunca recibe el secreto del cliente API; el portal emite
  tokens de widget temporales con `POST /v1/token`.
- **El portal es la fuente de verdad** de contenidos, usuarios, permisos y acciones: sincroniza documentos
  canónicos de forma asíncrona y vuelve a comprobar permisos en cada host action.
- **Los originales no salen del portal**: la plataforma los descarga con URLs temporales firmadas y solo
  conserva el texto extraído.
- **Pruebas de extremo a extremo** contra la plataforma real levantada con Docker Compose.
