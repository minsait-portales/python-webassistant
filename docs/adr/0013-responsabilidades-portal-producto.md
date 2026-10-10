# ADR 0013 · Reparto de responsabilidades entre portal y producto

| Ámbito | Portal (Drupal, Liferay, Adobe, SharePoint, WordPress…) | Producto WebAssistant |
| --- | --- | --- |
| Identidad | Autentica a su usuario y pide un token de widget con `POST /v1/token` desde su backend | Emite y valida tokens temporales con scopes; vincula sesiones anónimas |
| Contenido | Publica documentos canónicos, versiones y ACL; conserva originales y sus backups | Extrae, trocea, indexa, busca y cita derivados |
| Acciones | Implementa host actions (carrito, formularios, perfil…) y devuelve su resultado | Autoriza, confirma, hace idempotentes y audita las acciones |
| Presentación | Decide dónde instalar el widget y, si quiere, un contenedor de resultados | Widget accesible, renderers seguros y tema validado |
| Operación | Disponibilidad de su sitio y de sus fuentes | API, worker, datos, observabilidad, backups y recuperación del producto |

Los conectores específicos de cada plataforma quedan fuera del núcleo; los acuerdos por plataforma se
registran como ADR adicionales (CG-17.5) a partir de la [matriz de integración](../integraciones.md).
