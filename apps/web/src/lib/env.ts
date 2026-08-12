import { z } from 'zod';

/**
 * Build-time environment validation. This is a Next build concern, not a form concern, which is
 * why it lives here rather than in packages/contracts (nextjs-app-router owns it explicitly).
 *
 * `NEXT_PUBLIC_` inlines a value at build time into every bundle that references it, with no type
 * safety behind the prefix. apps/web needs EXACTLY ONE public value — the Laravel API origin — and
 * anything else with that prefix is either a mistake or a server secret on its way into a client
 * bundle. There is no provider credential, no internal HMAC key and no FastAPI hostname in this
 * package at all (kb-architecture-map NN1, ADR-013).
 */
const ALLOWED_PUBLIC_KEYS = ['NEXT_PUBLIC_API_ORIGIN'] as const;

const publicSchema = z.strictObject({
  // Origin only — scheme + host + optional port, no path. Every Laravel call is built from this.
  NEXT_PUBLIC_API_ORIGIN: z
    .url()
    .refine((value) => /^https?:\/\//.test(value), { error: 'must be an http(s) origin' })
    .refine((value) => !value.endsWith('/'), { error: 'no trailing slash' }),
});

export const publicEnv = publicSchema.parse({
  // Referenced literally so the bundler can inline it. `process.env[key]` is NOT inlined and
  // reads undefined in the browser.
  NEXT_PUBLIC_API_ORIGIN: process.env.NEXT_PUBLIC_API_ORIGIN,
});

/**
 * The one public value. Import this instead of touching `process.env` anywhere else.
 *
 * Spelled in SCREAMING_CASE deliberately: the lowercase `apiOrigin` is reserved for `streamAnswer`'s
 * test-seam option. Two spellings, two meanings — the constant every request is built from, and an
 * override only the SSE fixture server may pass — so a stray lowercase `apiOrigin` anywhere else is
 * a test seam leaking into application code.
 *
 * No CI asserts that, and no lint rule does either. gates.yml's `boundary-greps` job DOES run over
 * apps/web — it bans `'use server'` there — but nothing in it looks at `apiOrigin`. Reviewer check,
 * sharing the grep documented on StreamAnswerOptions in features/chat/stream-answer.ts:
 *
 *   grep -rn 'apiOrigin' apps/web/src | grep -vE ':[0-9]+:[[:space:]]*(\*|//)'
 *
 * which must return exactly two hits, both inside features/chat/stream-answer.ts: the
 * `readonly apiOrigin?: string;` declaration and the `options.apiOrigin ?? API_ORIGIN` that reads
 * it (verified). Anything outside that file is a test seam leaking into application code.
 */
export const API_ORIGIN = publicEnv.NEXT_PUBLIC_API_ORIGIN;

/**
 * Fails the build on any `NEXT_PUBLIC_*` key that is not on the allow-list above.
 *
 * Called from next.config.ts: Next 16 removed `next lint` and `next build` no longer lints, so
 * "ESLint would have caught it" stopped being true the day 16 landed. Runs on the server only —
 * in the browser `process.env` is not a real object, it is a set of inlined string literals.
 */
export function assertPublicEnv(): void {
  if (typeof window !== 'undefined') return;

  const allowed = new Set<string>(ALLOWED_PUBLIC_KEYS);
  const unknown = Object.keys(process.env).filter(
    (key) => key.startsWith('NEXT_PUBLIC_') && !allowed.has(key),
  );

  if (unknown.length > 0) {
    throw new Error(
      `Unknown NEXT_PUBLIC_* environment variable(s): ${unknown.join(', ')}. ` +
        `apps/web publishes exactly one: ${ALLOWED_PUBLIC_KEYS.join(', ')}. ` +
        `A NEXT_PUBLIC_ prefix inlines the value into every client bundle that reads it.`,
    );
  }

  publicSchema.parse({ NEXT_PUBLIC_API_ORIGIN: process.env.NEXT_PUBLIC_API_ORIGIN });
}
