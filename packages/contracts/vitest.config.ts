import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Node only. There is nothing here that needs a DOM, and jsdom would replace Node's
    // TextDecoder/ReadableStream globals with weaker ones (vitest-playwright).
    environment: 'node',
    include: ['test/**/*.test.ts'],
    // vitest.workspace.ts was REMOVED in Vitest 4 and a leftover file is silently ignored;
    // this package has a single project, so it declares none.
  },
});
