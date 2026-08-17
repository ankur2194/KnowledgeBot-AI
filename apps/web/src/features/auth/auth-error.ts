import { KbError } from '@kb/contracts';
import type { FieldValues, UseFormReturn } from 'react-hook-form';

import { applyServerErrors, endUserCopy } from '@/lib/forms/apply-server-errors';

/**
 * The one `error_class` -> form handler for every auth screen.
 *
 * EXTRACTED FROM login-form.tsx RATHER THAN COPIED, because six forms want it: login, register,
 * forgot-password, reset-password, verify-email resend, and the members invite. Six copies of a branch
 * table is six places for one rule to drift, and the rule here is not obvious enough to survive being
 * retyped — the reason `validation` returns EARLY, and the reason `rate_limit` falls THROUGH to the
 * banner, are both easy to get subtly wrong.
 *
 * ── EVERY BRANCH IS ON `error_class`, NEVER ON AN HTTP STATUS ────────────────────────────────────
 *
 * Statuses are many-to-one with classes (422 is `validation`; 500 and 503 are both
 * `internal_dependency`; 403 is `authorization` on the admin surface and 404 on public ones), so a
 * status branch is a branch on the wrong variable. `rhf-zod-forms` NN4.
 *
 * ── NOTHING HERE RENDERS THE ENVELOPE'S `message` ───────────────────────────────────────────────
 *
 * That field is operator-facing and can carry an internal hostname or raw text from an upstream
 * provider. The user sees a class-mapped sentence from ERROR_COPY plus `(ref <request_id>)`, which is
 * what `endUserCopy` builds. Validation MESSAGES are the one exception and are shown verbatim, because
 * Laravel translates those — they are end-user copy by construction.
 */
export interface AuthErrorHandlers {
  /** Called with the `Retry-After` seconds (or null) when the class is `rate_limit`. */
  readonly onRateLimit?: (retryAfterSeconds: number | null) => void;
  /**
   * Called when the class is `authorization`. The only error that should invalidate the cached
   * session: it is the "you were removed from the organization you just acted on" case, and the stale
   * membership list has to go. Deliberately not called for `authentication` — that one bounces to
   * /login, and invalidating a cache the navigation is about to destroy is noise.
   */
  readonly onAuthorization?: () => void;
  /**
   * Replace the banner sentence for this screen only. Return `undefined` to keep the class-mapped
   * default from ERROR_COPY.
   *
   * IT EXISTS FOR ONE MEASURED REASON, and it is not a styling hook. `ERROR_COPY` maps a class to the
   * sentence that is true for the class *in general*, and on one screen that generality is wrong:
   * `POST /auth/email/verify` answers an unusable confirmation link with `authorization`/404 — the
   * taxonomy-correct class, because the capability addresses nothing the caller may act on — but
   * `ERROR_COPY.authorization` reads "You do not have access to this.", which tells someone who clicked
   * a link in their own inbox that they lack a permission. It also contradicts the explanation the page
   * itself renders below the banner.
   *
   * The alternative was a local `error_class` branch on that screen, which is exactly the drift that
   * putting this table in one file prevents. An override is narrower: the branch table stays single,
   * and the deviation is one expression at the call site, next to the copy it replaces.
   *
   * Do NOT use it to soften a class the user genuinely needs to act on, and do not use it to invent a
   * cause the server did not state.
   */
  readonly copyFor?: (error: KbError) => string | undefined;
}

/**
 * Map a thrown error onto a react-hook-form instance.
 *
 * `knownPaths` is "the paths this form RENDERS", which is a different set from "the paths the
 * FormRequest validates" — a hidden input (a reset or invitation `token`) is a schema path but has no
 * focusable ref, so an error keyed on it must land in the banner rather than be written to a field that
 * displays nowhere. See known-paths.ts.
 */
export function applyAuthError<TFieldValues extends FieldValues>(
  form: UseFormReturn<TFieldValues, unknown, FieldValues>,
  knownPaths: readonly string[],
  error: unknown,
  handlers: AuthErrorHandlers = {},
): void {
  const setBanner = (type: string, message: string): void => {
    // `type` is MANDATORY on setError: an untyped write to a parent path replaces the whole child
    // subtree, and the child's error vanishes with nothing logged.
    form.setError('root.serverError', { type, message });
  };

  if (!(error instanceof KbError)) {
    // No envelope => `error_class: null` => unknown, and unknown is permanently non-retryable.
    // NEVER invent a class name to fill the slot; `endUserCopy` has a sentence for null.
    setBanner('unknown', endUserCopy({ error_class: null, request_id: null }));

    return;
  }

  // RETURNS EARLY, and that is the load-bearing half. `applyServerErrors` writes per-field errors and
  // routes unknown keys to a single `root.serverError`; falling through to setBanner() afterwards would
  // overwrite that write with a generic sentence and silently discard every field message.
  //
  // Bad credentials arrive HERE — 422 `validation` with the message on `email` (decision D7), never
  // 401 — so they land on the field like any other validation failure, and emphatically not as a
  // session-expiry bounce: ERROR_COPY.authentication reads "Your session has ended. Sign in again to
  // continue.", which is nonsense on a first sign-in attempt.
  if (error.error_class === 'validation' && error.errors !== null) {
    applyServerErrors(form, knownPaths, error.errors);

    return;
  }

  // FALLS THROUGH to the banner, unlike validation: a throttled caller needs both the disabled submit
  // and a sentence saying why. `retry_after` comes off the Retry-After response HEADER, not the JSON
  // body — a 429 without the header degrades this to null and the cooldown to nothing.
  // This is a cooldown, not a retry: the client never reattempts on the user's behalf, and `retry:` is
  // an ESLint error outside lib/query/client.ts anyway.
  if (error.error_class === 'rate_limit') handlers.onRateLimit?.(error.retry_after);

  if (error.error_class === 'authorization') handlers.onAuthorization?.();

  setBanner(error.error_class ?? 'unknown', handlers.copyFor?.(error) ?? endUserCopy(error));
}
