import security from 'eslint-plugin-security';
import tseslint from 'typescript-eslint';

// Each workspace owns its own flat config and installs its own plugins: the root manifest
// declares zero devDependencies, because one would write a fifth pnpm-lock.yaml (.npmrc).
// eslint.base.mjs is importable from here precisely because it imports nothing itself.
import { ignores, kbRules } from '../../eslint.base.mjs';

export default tseslint.config(
  { ignores: [...ignores] },
  ...tseslint.configs.recommended,
  security.configs.recommended,
  {
    files: ['**/*.ts'],
    rules: {
      ...kbRules,
      // The envelope's field names are the wire's, verbatim. A lint rule that "fixes" them to
      // camelCase turns every retryable class into a permanent failure (nextjs-app-router).
      '@typescript-eslint/naming-convention': 'off',
      '@typescript-eslint/consistent-type-imports': 'error',
    },
  },
  {
    // Tests may reach for shapes the runtime code refuses to model.
    files: ['test/**/*.ts'],
    rules: { '@typescript-eslint/no-explicit-any': 'off' },
  },
);
