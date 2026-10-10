/**
 * Build del widget (CG-10.8): genera
 *   dist/assets/widget.<hash>.js   bundle inmutable (Shadow DOM + chat)
 *   dist/assets/logo.<hash>.png    logo por defecto
 *   dist/loader.js                 loader estable con el hash y la integridad SRI del bundle
 *   dist/manifest.json             versión, entrada e integridad (para despliegue y rollback)
 *
 * Los bundles anteriores se conservan en dist/assets salvo con `--clean`, para no romper
 * widgets ya abiertos mientras caduca la caché corta de loader.js.
 */
import { createHash } from 'node:crypto';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { build } from 'esbuild';

const root = path.dirname(fileURLToPath(import.meta.url));
const dist = path.join(root, 'dist');
const assets = path.join(dist, 'assets');
const { version } = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'));

const common = {
  bundle: true,
  format: 'iife',
  target: ['es2020', 'chrome92', 'edge92', 'firefox90', 'safari15'],
  minify: true,
  legalComments: 'none',
  write: false,
  logLevel: 'warning',
  alias: { '@webassistant/sdk': path.resolve(root, '../../packages/sdk-js/src/index.ts') },
};

const digest = (algorithm, data, encoding) => createHash(algorithm).update(data).digest(encoding);

if (process.argv.includes('--clean')) await rm(dist, { recursive: true, force: true });
await mkdir(assets, { recursive: true });

const logo = await readFile(path.join(root, 'public', 'logo.png'));
const logoName = `logo.${digest('sha256', logo, 'hex').slice(0, 12)}.png`;
await writeFile(path.join(assets, logoName), logo);

const widget = await build({
  ...common,
  entryPoints: [path.join(root, 'src', 'bundle.ts')],
  define: {
    __WIDGET_VERSION__: JSON.stringify(version),
    __WIDGET_LOGO__: JSON.stringify(`assets/${logoName}`),
  },
});
const code = widget.outputFiles[0].contents;
const entry = `assets/widget.${digest('sha256', code, 'hex').slice(0, 12)}.js`;
const integrity = `sha384-${digest('sha384', code, 'base64')}`;
await writeFile(path.join(dist, entry), code);

const loader = await build({
  ...common,
  entryPoints: [path.join(root, 'src', 'loader.ts')],
  define: {
    __WIDGET_ENTRY__: JSON.stringify(entry),
    __WIDGET_INTEGRITY__: JSON.stringify(integrity),
    __WIDGET_VERSION__: JSON.stringify(version),
  },
});
const loaderCode = loader.outputFiles[0].contents;
await writeFile(path.join(dist, 'loader.js'), loaderCode);

const manifest = {
  name: '@webassistant/widget',
  version,
  entry,
  integrity,
  logo: `assets/${logoName}`,
  loader_integrity: `sha384-${digest('sha384', loaderCode, 'base64')}`,
  sizes: { widget_bytes: code.byteLength, loader_bytes: loaderCode.byteLength },
  built_at: new Date().toISOString(),
};
await writeFile(path.join(dist, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
console.log(
  `widget ${version}: ${entry} (${(code.byteLength / 1024).toFixed(1)} KiB), loader ${(loaderCode.byteLength / 1024).toFixed(1)} KiB`,
);
