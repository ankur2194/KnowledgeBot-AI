/**
 * `searchParams.token` -> exactly one string, or the empty string.
 *
 * ── WHY THIS LIVES IN `lib/auth/` AND NOT IN `features/auth/`, AND IT IS THE SAME REASON `safe-next.ts`
 *    DOES ──────────────────────────────────────────────────────────────────────────────────────────
 * Its two callers are SERVER COMPONENTS: `(auth)/register/page.tsx` and
 * `(auth)/invitations/accept/page.tsx`, which await `searchParams` on the server and pass one primitive
 * down. Anything they import is compiled into the React Server Component graph, and
 * `src/features/auth/session.ts` declares the `SessionContext` that `<SessionProvider>` consumes — so a
 * server page importing ANY export of that module, even a pure fetch helper, drags `createContext` into
 * an RSC compilation and Turbopack refuses the build outright:
 *
 *   "You're importing a module that depends on `createContext` into a React Server Component module."
 *
 * MEASURED, and it is caught by `pnpm web:build` ONLY — `web:typecheck` and `web:test` both pass while it
 * is broken, because neither of them compiles the RSC graph. That is why this three-line function is its
 * own dependency-free module rather than sitting beside the invitation transport it belongs to
 * thematically. NOTHING IN THIS FILE MAY IMPORT ANYTHING.
 *
 * ── THE RULE ITSELF ─────────────────────────────────────────────────────────────────────────────
 * NOT ONE STRING, NOT A TOKEN. Next hands back a `string[]` for a repeated search parameter, so
 * `?token=a&token=b` arrives as an array; honouring `raw[0]` would silently pick a value out of a URL
 * somebody appended to. It is the same rule `safeNext` applies to `?next=`, for the same reason.
 *
 * THERE IS DELIBERATELY NO SHAPE CHECK. The server's rule is `size:64` on a 32-byte hex capability, and
 * this does not mirror it: a token of the wrong length must reach the server and come back as the
 * indistinguishable "This invitation is no longer valid.", not be refused locally by a length test that
 * tells the holder their token is the wrong SHAPE. `registerSchema` asserts only `min(1)` for exactly this
 * reason and its docblock says so.
 *
 * The empty string is a real, expected input — somebody opened `/register` with no query at all — and it
 * is the value the screens gate their "this link is incomplete" state on. No request is made for it, so
 * nothing is spent against `throttle:invitation`, which is keyed on the token.
 */
export const singleToken = (raw: string | readonly string[] | undefined | null): string =>
  typeof raw === 'string' ? raw : '';
