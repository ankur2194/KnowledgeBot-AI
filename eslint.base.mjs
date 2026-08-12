/**
 * Repo-wide ESLint constants — plain data, ZERO imports.
 *
 * This file must never `import` anything, including `eslint`, `typescript-eslint` or
 * `eslint-plugin-security`: the root package.json declares no dependencies, and adding one
 * writes a fifth pnpm-lock.yaml (see .npmrc). Each workspace owns its own
 * `eslint.config.mjs`, installs its own plugins, and spreads `kbRules` into a flat config.
 * A shared `eslint-config-kb` PACKAGE is not the alternative — there are exactly two
 * workspace packages and a third is a finding (CLAUDE.md).
 *
 * Everything below is a core ESLint rule, so it works with any parser a workspace picks.
 */

/** Paths no workspace ever lints. `generated/` is committed build output (packages/design-tokens). */
export const ignores = [
  '**/node_modules/**',
  '**/dist/**',
  '**/.next/**',
  '**/out/**',
  '**/coverage/**',
  '**/generated/**',
  '**/playwright-report/**',
  '**/test-results/**',
  '**/.turbo/**',
];

/**
 * Exported separately from `kbRules` because flat config REPLACES a rule's options rather than
 * merging them: a per-directory override that re-declares `no-restricted-syntax` silently drops
 * every selector below unless it spreads this array back in.
 */
export const kbRestrictedSyntax = [
  {
    // nextjs-app-router NN4: a Server Action is a mutation path that skips Laravel's rate
    // limiter, quota accounting and audit log. `rg "'use server'" apps/web` must stay empty.
    selector: "ExpressionStatement[directive='use server']",
    message: 'No Server Actions in this repo. Every mutation is a browser fetch to Laravel.',
  },
  {
    // One KbError, one casing. `error.errorClass` against a snake_cased instance reads
    // undefined, CLIENT_RETRYABLE.has(undefined) is false, and every retryable class becomes
    // a permanent failure with no error anywhere (nextjs-app-router, tanstack-query-table).
    selector: "Identifier[name='errorClass']",
    message: 'The envelope field is `error_class`. camelCase silently disables every retry.',
  },
  {
    selector: "Identifier[name='retryAfter']",
    message: 'The field is `retry_after` (seconds, off the Retry-After response header).',
  },
  {
    // kb-security-baseline: model output and source text render through
    // markdown-it(html:false) -> DOMPurify -> replaceChildren, never as HTML.
    selector: "JSXAttribute[name.name='dangerouslySetInnerHTML']",
    message: 'Untrusted content is never HTML. Use the sanitizer pipeline.',
  },
  {
    // Both are keyed by arguments plus closed-over values, never by the ambient request, and
    // the organization lives in the session cookie (nextjs-app-router caching table).
    selector: "CallExpression[callee.name='unstable_cache']",
    message: 'No argument-keyed server cache: the org is not in the key.',
  },
  {
    selector: "ExpressionStatement[directive='use cache']",
    message: 'No `use cache`: its key is arguments + closure, and the org is in neither.',
  },
  {
    // kb-architecture-map NN1 / ADR-013: browsers and the Next server talk only to Laravel.
    selector: 'Literal[value=/(^|\\/\\/)(ai-api|ai-service)(:|\\/|$)/]',
    message: 'apps/web has no route to FastAPI. Every request goes to Laravel.',
  },
];

/** Spread into a flat config's `rules`. */
export const kbRules = {
  'no-restricted-globals': [
    'error',
    {
      // GET-only, carries neither our POST body nor a header, and its automatic Last-Event-ID
      // reconnect is forbidden because token streams are not resumable
      // (kb-internal-api-contracts). Every surface streams with fetch() + getReader().
      name: 'EventSource',
      message: 'Stream with fetch() + response.body.getReader(), never EventSource.',
    },
  ],
  'no-restricted-syntax': ['error', ...kbRestrictedSyntax],
  'no-restricted-imports': [
    'error',
    {
      paths: [
        {
          // Only apps/web/src/lib/api/server.ts may import it, and that file re-enables this
          // rule off in its own override block (nextjs-app-router DoD).
          name: 'server-only',
          message: 'lib/api/server.ts is the only module that may be server-only.',
        },
      ],
      patterns: [
        {
          // Reaching past the exports map bypasses the "types" condition and the subpath split
          // that keeps zod out of the widget's brotli budget (ADR-028).
          group: ['@kb/contracts/src/*', '@kb/contracts/dist/*', '@kb/design-tokens/src/*'],
          message: 'Import the package entry point, never its internals.',
        },
      ],
    },
  ],
};
