/** Build de la consola: bundle JS + CSS con hash en dist/assets e index.html (servida en /admin/). */
import { createHash } from 'node:crypto';
import { copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { build } from 'esbuild';

const root = path.dirname(fileURLToPath(import.meta.url));
const dist = path.join(root, 'dist');

await rm(dist, { recursive: true, force: true });
await mkdir(path.join(dist, 'assets'), { recursive: true });

const result = await build({
  entryPoints: [path.join(root, 'src', 'main.ts')],
  bundle: true,
  format: 'iife',
  target: ['es2020', 'chrome92', 'edge92', 'firefox90', 'safari15'],
  minify: true,
  legalComments: 'none',
  write: false,
  outdir: dist,
  logLevel: 'warning',
  alias: { '@webassistant/sdk': path.resolve(root, '../../packages/sdk-js/src/index.ts') },
});

const names = {};
for (const file of result.outputFiles) {
  const ext = path.extname(file.path);
  const hash = createHash('sha256').update(file.contents).digest('hex').slice(0, 12);
  const name = `assets/app.${hash}${ext}`;
  names[ext] = name;
  await writeFile(path.join(dist, name), file.contents);
}

// La CSP de /admin no permite estilos inline salvo 'unsafe-inline' en style-src; el aviso de carga es mínimo.
const template = await readFile(path.join(root, 'index.html'), 'utf8');
await writeFile(
  path.join(dist, 'index.html'),
  template.replace('%CSS%', names['.css']).replace('%JS%', names['.js']),
);
await copyFile(path.join(root, 'public', 'logo.png'), path.join(dist, 'logo.png'));
console.log(`admin: ${names['.js']} ${names['.css']}`);
