import { render } from 'preact';

import { App } from './app.js';
import './styles.css';

/**
 * The iframe application's entry point. Runs on OUR origin, under OUR CSP, inside the document
 * Laravel emits at `<widget-domain>/embed` — which is why this file's compiled name is pinned to
 * `assets/index-<hash>.js` in vite.config.ts and why `build.manifest` is on: Laravel reads
 * `dist/app/.vite/manifest.json` to learn the hashed filename, because `dist/app/index.html` is
 * never served.
 *
 * `preact/debug` is imported for SIDE EFFECTS, so tree-shaking cannot drop it and `NODE_ENV` does
 * not gate it. A bare top-level import ships our warnings into customers' consoles in production.
 * The dynamic import behind `import.meta.env.DEV` is the only correct form, and the build job
 * greps for the bare one.
 */
if (import.meta.env.DEV) await import('preact/debug');

const root = document.getElementById('kb-root');
if (root !== null) render(<App />, root);
