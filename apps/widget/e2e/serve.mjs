/** Arranca la API con la demo para las pruebas E2E (base de datos temporal y desechable). */
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const port = process.argv[2] || '8765';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const dir = mkdtempSync(path.join(tmpdir(), 'wa-e2e-'));
const child = spawn(
  'uv',
  [
    'run',
    '--project',
    'apps/api',
    'uvicorn',
    '--factory',
    'webassistant.api.app:app_factory',
    '--port',
    port,
  ],
  {
    cwd: root,
    stdio: 'inherit',
    env: {
      ...process.env,
      WEBASSISTANT_ENVIRONMENT: 'test',
      WEBASSISTANT_DEMO_ENABLED: 'true',
      WEBASSISTANT_PUBLIC_BASE_URL: `http://localhost:${port}`,
      WEBASSISTANT_DATABASE_URL: `sqlite+aiosqlite:///${path.join(dir, 'e2e.sqlite3')}`,
      WEBASSISTANT_EMBEDDING_DIMENSION: '384',
      WEBASSISTANT_LOG_LEVEL: 'WARNING',
      WEBASSISTANT_RATE_LIMIT_SUBJECT_PER_MINUTE: '600',
      WEBASSISTANT_RATE_LIMIT_IP_PER_MINUTE: '1200',
    },
  },
);
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));
child.on('exit', (code) => process.exit(code ?? 0));
