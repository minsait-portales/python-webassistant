# ADR 0006 · RAG con documentos canónicos; los originales permanecen en el portal

**Contexto.** Los portales ya gestionan sus ficheros, permisos y backups. Duplicar originales
multiplica riesgos legales y de seguridad.

**Decisión.** El portal publica un **documento canónico** (texto o referencia descargable temporal,
metadatos, ACL, versión). El producto guarda solo derivados (texto extraído, fragmentos, embeddings) y
la URL canónica. Las citas se verifican contra el texto recuperado antes de mostrarse. Cada versión de
embeddings vive en su colección y se activa sin corte.

**Consecuencias.** El índice puede reconstruirse desde el portal; los backups de originales son
responsabilidad del portal y se coordinan como dependencia externa.
