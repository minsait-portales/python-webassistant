# ADR 0001 · Stack: Python 3.12, FastAPI asíncrona, SQLAlchemy 2 y uv

**Contexto.** El documento pide una capa genérica en Python, desacoplada del CMS, con streaming,
llamadas concurrentes a proveedores de IA y a almacenes (PostgreSQL, Qdrant, Redis).

**Decisión.** Python 3.12/3.13, FastAPI + Uvicorn (ASGI), Pydantic v2 para contratos y
configuración, SQLAlchemy 2 asíncrono con asyncpg y Alembic, cliente asíncrono de Qdrant, arq para la
cola y `uv` como gestor de workspace con lockfile. El frontend es TypeScript sin framework, compilado
con esbuild.

**Alternativas.** Django (más acoplamiento y ORM síncrono por defecto); Node.js para todo (el documento
fija Python y el ecosistema de IA/RAG es más maduro); framework de UI en el widget (más peso y
riesgo de conflictos con la página anfitriona).

**Consecuencias.** E/S asíncrona extremo a extremo; tipado estricto (mypy, TypeScript `strict`); un
único lockfile por ecosistema (`uv.lock`, `package-lock.json`) para builds reproducibles.
