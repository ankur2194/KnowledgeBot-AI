// eslint-config-next 16 IS a flat config array — one per entry point, exported directly.
// The `next.flatConfig.coreWebVitals` shape belonged to the eslintrc-compat wrapper and is gone;
// reaching for it throws `Cannot read properties of undefined (reading 'coreWebVitals')` while the
// config file is being imported, which fails the whole run before a single file is matched. That
// is why this had never linted anything. The subpath is the API now:
//   eslint-config-next            -> react, react-hooks, import, jsx-a11y, @next/next recommended
//   eslint-config-next/core-web-vitals -> the above PLUS @next/next's core-web-vitals rules
import nextCoreWebVitals from 'eslint-config-next/core-web-vitals';
import security from 'eslint-plugin-security';
import tseslint from 'typescript-eslint';

// The root manifest declares zero devDependencies (a fifth pnpm-lock.yaml otherwise), so every
// plugin above is installed HERE and eslint.base.mjs contributes plain data only.
import { ignores, kbRestrictedSyntax, kbRules } from '../../eslint.base.mjs';

/**
 * `next/typescript` is dropped, and this is not cosmetic. That entry contributes NO rules — it only
 * registers the `@typescript-eslint` plugin and the TS parser — but it registers eslint-config-next's
 * OWN bundled typescript-eslint (^8.46) while this workspace pins 8.40.0. Flat config refuses two
 * different objects under one plugin key:
 *
 *   ConfigError: Config "next/typescript": Key "plugins": Cannot redefine plugin "@typescript-eslint".
 *
 * ...and that error is thrown while ESLint walks the file list, so it kills the run rather than
 * degrading it. `tseslint.configs.recommended` below installs the same plugin and parser from the
 * pinned copy, plus the rules next's entry does not carry, so nothing is lost by removing it.
 * Filter by NAME rather than by index: the array's order is Next's to change.
 */
const nextConfigs = nextCoreWebVitals.filter((config) => config.name !== 'next/typescript');

export default tseslint.config(
  { ignores: [...ignores, 'next-env.d.ts', '.next/**'] },
  // Next 16 REMOVED `next lint` and `next build` no longer lints. This config is only reached
  // because `pnpm lint` runs ESLint as its own step — a pipeline that relied on the build to lint
  // now passes while checking nothing.
  //
  // BEFORE tseslint: the `next` entry sets `languageOptions.parser` to eslint-config-next's Babel
  // parser for every js/jsx/mjs/ts/tsx file, and later entries win. Put it after and TypeScript
  // source is parsed by Babel, which is how `@typescript-eslint/*` rules go quiet without failing.
  ...nextConfigs,
  ...tseslint.configs.recommended,
  security.configs.recommended,
  {
    files: ['**/*.{ts,tsx,mjs}'],
    rules: {
      ...kbRules,
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    },
  },
  {
    // lib/api/server.ts is the ONE module allowed to be server-only and to call Laravel from the
    // Next server (nextjs-app-router DoD). Everywhere else the import is banned by kbRules.
    files: ['src/lib/api/server.ts'],
    rules: { 'no-restricted-imports': 'off' },
  },
  {
    // Retry counts are configured in exactly one file. A per-call `retry:` silently re-multiplies
    // attempts against the FastAPI adapter's own ladder — 6 to 12 provider calls per user click.
    files: ['src/**/*.{ts,tsx}'],
    ignores: ['src/lib/query/client.ts'],
    rules: {
      'no-restricted-syntax': [
        'error',
        // Spread, never replace: flat config REPLACES a rule's options, so re-declaring this rule
        // without the base selectors would quietly un-ban the Server Action directive for every
        // file it matches.
        ...kbRestrictedSyntax,
        {
          selector: "Property[key.name='retry']",
          message: 'Retry policy lives only in src/lib/query/client.ts (tanstack-query-table NN4).',
        },
      ],
    },
  },
  {
    // Nothing under (admin) is cached at all: the correct-looking version is one refactor from the
    // leaking one, and a single-org fixture passes either way.
    files: ['src/app/(admin)/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': [
        'error',
        ...kbRestrictedSyntax,
        {
          selector: "Property[key.name='retry']",
          message: 'Retry policy lives only in src/lib/query/client.ts.',
        },
        {
          // force-static does not error when a route reads request state — it makes cookies(),
          // headers() and useSearchParams() return EMPTY values, so the page renders a plausible
          // signed-out shell once and the Full Route Cache serves it to every organization.
          selector: "Literal[value='force-static']",
          message: 'force-dynamic is the only `dynamic` value under (admin).',
        },
        {
          selector: "FunctionDeclaration[id.name='generateStaticParams']",
          message: 'Admin routes are never prerendered: the org is not in the path.',
        },
        {
          selector: "VariableDeclarator[id.name='fetchCache']",
          message:
            'fetchCache re-enables caching for fetches issued after a request-time API — the exact window force-dynamic is closing.',
        },
      ],
    },
  },
  {
    files: ['tests/**/*.{ts,tsx}'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      'security/detect-non-literal-fs-filename': 'off',
    },
  },
);
