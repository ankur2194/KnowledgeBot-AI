import security from 'eslint-plugin-security';
import tseslint from 'typescript-eslint';

// The ROOT manifest declares zero dependencies (shared-workspace-lockfile=false: one root devDep
// writes a fifth pnpm-lock.yaml), so eslint / typescript-eslint / eslint-plugin-security are
// installed HERE and eslint.base.mjs contributes plain data with zero imports.
import { ignores, kbRestrictedSyntax, kbRules } from '../../eslint.base.mjs';

/**
 * Flat config REPLACES a rule's options rather than merging them, so every rule below that also
 * exists in `kbRules` is rebuilt from the base entries rather than re-declared. Re-declaring
 * `no-restricted-globals` without spreading would quietly un-ban `EventSource` for every file it
 * matches; re-declaring `no-restricted-syntax` would un-ban the Server Action directive and the
 * envelope camelCase-spelling trap.
 */
const [, ...baseGlobals] = kbRules['no-restricted-globals'];
const [, baseImports] = kbRules['no-restricted-imports'];

/**
 * The two defects this workspace exists to prevent, expressed as selectors so review is not the
 * only thing standing between them and a customer's page.
 */
const bridgeSyntax = [
  {
    // `postMessage(msg, '*')` delivers to whatever document currently occupies the frame,
    // INCLUDING one the host page swapped in after we created it. There is no case in this app
    // where a wildcard target is correct: the loader targets __KB_WIDGET_ORIGIN__, the frame
    // targets its validated `?origin=`, and an OPAQUE origin (which can only be targeted with '*')
    // is a refusal, not a fallback.
    selector: "CallExpression[callee.property.name='postMessage'] > Literal[value='*']",
    message:
      "Never postMessage to '*'. The loader targets __KB_WIDGET_ORIGIN__; the frame targets its validated ?origin=; an opaque origin is refused.",
  },
  {
    selector: "CallExpression[callee.name='postMessage'] > Literal[value='*']",
    message: "Never postMessage to '*'.",
  },
  {
    // `startsWith` admits https://customer.example.attacker.net. `endsWith` admits
    // https://evilcustomer.example. An unanchored regex admits both, and `indexOf` is OWASP's
    // named example of "very insecure". There is no substring form of an origin check that is
    // safe: full-string `===`, or parse with new URL() and compare protocol/hostname/port.
    selector:
      "CallExpression[callee.property.name=/^(startsWith|endsWith|includes|indexOf|match|search)$/][callee.object.property.name='origin']",
    message:
      'Origin checks are full-string === only. startsWith/endsWith/includes/regex each admit a lookalike origin.',
  },
  {
    selector:
      'CallExpression[callee.property.name=/^(startsWith|endsWith|includes|indexOf|match|search)$/][callee.object.name=/([Oo]rigin|HOST)$/]',
    message:
      'Origin checks are full-string === only. startsWith/endsWith/includes/regex each admit a lookalike origin.',
  },
  {
    selector: "BinaryExpression[operator='=='][left.property.name='origin']",
    message: 'Compare origins with === (and never against a value read from iframe.src).',
  },
  {
    // Untrusted content is never HTML anywhere in this repo, and in a widget the sink is a
    // stranger's page. The path is markdown-it(html:false) -> DOMPurify(array config,
    // RETURN_DOM_FRAGMENT) -> replaceChildren.
    selector: "MemberExpression[property.name='innerHTML']",
    message: 'Use textContent, or the sanitizer pipeline in src/render/renderer.ts.',
  },
  {
    selector: "MemberExpression[property.name='outerHTML']",
    message: 'Use textContent, or the sanitizer pipeline in src/render/renderer.ts.',
  },
  {
    // The origins are Vite `define` constants. Reading one off the DOM reads a value the host page
    // rewrote before our code ran.
    selector: "MemberExpression[object.name='iframe'][property.name='src']",
    message:
      'Never derive an origin from iframe.src — the host page can rewrite it. Use __KB_WIDGET_ORIGIN__.',
  },
];

export default tseslint.config(
  { ignores: [...ignores, 'dist/**', 'playwright/**'] },
  ...tseslint.configs.recommended,
  security.configs.recommended,
  {
    files: ['**/*.{ts,tsx,mjs}'],
    rules: {
      ...kbRules,
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      // The loader runs in someone else's console. Warnings there carry our filename.
      'no-console': ['error', { allow: ['warn'] }],

      'no-restricted-syntax': ['error', ...kbRestrictedSyntax, ...bridgeSyntax],

      'no-restricted-globals': [
        'error',
        ...baseGlobals,
        {
          // Barred for a session identifier outright, AND a third-party frame whose domain lands
          // on Mozilla's tracker list gets SecurityError from the property access itself under
          // Firefox Total Cookie Protection. A widget on thousands of sites eventually lands on
          // such a list.
          name: 'localStorage',
          message:
            'Never localStorage: barred for session identifiers, and it throws under Firefox TCP. State lives in module scope in the frame.',
        },
        {
          name: 'indexedDB',
          message: 'Never indexedDB: throws under Firefox Total Cookie Protection in a 3P frame.',
        },
        {
          name: 'BroadcastChannel',
          message: 'Never BroadcastChannel: throws under Firefox TCP, and nothing needs it.',
        },
        {
          name: 'SharedWorker',
          message: 'Never SharedWorker: throws under Firefox TCP in a third-party frame.',
        },
        {
          // Tier 2 of the resumption ladder, and it exists in exactly one wrapped module.
          name: 'sessionStorage',
          message:
            'sessionStorage is tier 2 of the resumption ladder and lives only in src/app/resume.ts, behind a try/catch.',
        },
      ],

      'no-restricted-imports': [
        'error',
        {
          paths: [
            ...baseImports.paths,
            {
              name: 'react',
              message:
                'There is no react -> preact/compat alias here, deliberately. Use preact + preact/hooks.',
            },
            { name: 'react-dom', message: 'Use preact.' },
            {
              name: 'preact/compat',
              message:
                'compat is an aliasing layer that suppresses exactly the build error telling you a React dependency got in.',
            },
            {
              name: 'preact/debug',
              message:
                "preact/debug is imported for side effects, so tree-shaking cannot drop it. Only `if (import.meta.env.DEV) await import('preact/debug')`.",
            },
          ],
          patterns: [
            ...baseImports.patterns,
            {
              // ADR-028: the Zod schemas live behind the ./forms subpath precisely so zod stays out
              // of the 30 kB app-shell brotli budget. Importing it here is how that budget breaks
              // in a PR that touched no widget file.
              group: ['@kb/contracts/forms'],
              message:
                'ADR-028: @kb/contracts/forms pulls zod into the widget bundle. The widget never imports it.',
            },
            {
              group: ['@kb/web', '@kb/web/*', '../../web/*'],
              message:
                "apps/web's component layer is React + Radix and would blow the app budget on its own.",
            },
          ],
        },
      ],
    },
  },
  {
    /**
     * THE LOADER BUNDLE. It runs on the CUSTOMER's origin inside a 5 kB brotli budget, and it must
     * import no runtime code from @kb/contracts: a loader that pulls in the frame parser "to reuse
     * the types" turns a budget into a build failure. `import type` erases and is the correct fix,
     * which is why this uses the typescript-eslint rule with allowTypeImports.
     *
     * `allowTypeImports` IS A PER-ENTRY OPTION, not a top-level one. Hoisted to the options object
     * it fails ESLint 9's schema validation OUTRIGHT — "Unexpected property allowTypeImports" —
     * which aborts the whole `eslint .` run before a single file is linted. So this block enforced
     * nothing, and neither did any other rule in this config: a check that cannot run is worse than
     * no check, because it reads as enforcement.
     *
     * The glob covers `src/bridge/**` as well as `src/loader/**`, and that is the point rather than
     * a tidy-up. The rule is file-glob based while the budget is import-graph based, and
     * src/bridge/protocol.ts is — by its own header comment — the ONE file both bundles contain. A
     * value import of `ERROR_CLASSES` there lands in the 5 kB loader just as surely as one written
     * inside src/loader/, and the previous glob could not see it.
     */
    files: ['src/loader/**/*.ts', 'src/bridge/**/*.ts'],
    rules: {
      'no-restricted-imports': 'off',
      '@typescript-eslint/no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@kb/contracts',
              // Type-only is allowed and is how SdkErrorPayload names the closed 18 `ErrorClass`
              // values without billing the loader a byte.
              allowTypeImports: true,
              message:
                'The loader imports NO runtime code from @kb/contracts (5 kB budget). `import type` erases and is allowed.',
            },
            {
              name: 'preact',
              message:
                'The loader is plain TypeScript + DOM. Preact in the loader triples the only file the customer pays for on every navigation.',
            },
            { name: '@kb/design-tokens', message: 'The launcher lives behind `all: initial`.' },
          ],
        },
      ],
    },
  },
  {
    // The one module allowed to touch sessionStorage, and it wraps every access.
    files: ['src/app/resume.ts'],
    rules: { 'no-restricted-globals': ['error', ...baseGlobals] },
  },
  {
    files: ['tests/**/*.{ts,tsx}', 'tests/**/*.mjs'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      'no-console': 'off',
      'security/detect-non-literal-fs-filename': 'off',
      'security/detect-object-injection': 'off',
      // The harness deliberately posts to '*' and from unexpected origins: that is the attack it
      // reproduces, and a suite that cannot express it proves nothing.
      'no-restricted-syntax': ['error', ...kbRestrictedSyntax],
    },
  },
);
