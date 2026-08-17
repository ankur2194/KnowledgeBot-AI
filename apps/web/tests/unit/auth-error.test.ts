import { KbError } from '@kb/contracts';
import { createFormControl } from 'react-hook-form';
import type { FieldErrors, Path, UseFormReturn } from 'react-hook-form';
import { describe, expect, it, vi } from 'vitest';

import { actionErrorCopy } from '@/features/auth/action-error';
import { applyAuthError } from '@/features/auth/auth-error';
import { ERROR_COPY } from '@/lib/forms/apply-server-errors';

/**
 * `applyAuthError` — the ONE `error_class` -> form branch table for six auth screens — and
 * `actionErrorCopy`, its counterpart for the three actions that are a button rather than a form.
 *
 * Neither had a direct spec. Both were reachable only through component specs, which is the worst
 * place for this particular code to live: a browser spec proves the SENTENCE that got rendered, and
 * three of the branches below are about what does NOT get rendered — a banner that must not overwrite
 * per-field messages, an override that must not fire, a handler that must not be called. An absence is
 * exactly what a rendering assertion is worst at, and every one of these is one deleted `return` away
 * from being wrong.
 *
 * `createFormControl` is react-hook-form's own headless entry point — the same control object
 * `useForm` builds, without React — so this runs in the `unit` project (node, no DOM). The same
 * harness `tests/unit/apply-server-errors.test.ts` uses, for the same reason: none of this is about
 * rendering.
 */

interface LoginForm {
  email: string;
  password: string;
}

/** `LOGIN_KNOWN_PATHS`, spelled out: this file is about the branch table, not about the derivation. */
const KNOWN_PATHS = ['email', 'password'];

/** The operator-facing string. If it ever reaches a banner, the assertion below names it. */
const OPERATOR_MESSAGE = 'upstream api-7.internal refused: key sk-live-9f3a expired';

const REQUEST_ID = '01JREQUESTIDAAAAAAAAAAAAAA';

interface Harness {
  form: UseFormReturn<LoginForm, unknown, LoginForm>;
  errors: () => FieldErrors<LoginForm>;
  banner: () => { readonly type?: string; readonly message?: string } | undefined;
  focused: string[];
}

function harness(): Harness {
  const control = createFormControl<LoginForm>({
    defaultValues: { email: '', password: '' },
  });
  const form = control as unknown as UseFormReturn<LoginForm, unknown, LoginForm>;
  const focused: string[] = [];

  for (const name of ['email', 'password']) {
    const field = form.register(name as Path<LoginForm>);
    field.ref({
      name,
      type: 'text',
      value: '',
      focus: () => focused.push(name),
    } as unknown as HTMLInputElement);
  }

  const errors = () => form.control._formState.errors;

  return {
    form,
    errors,
    banner: () =>
      (errors() as { root?: { serverError?: { type?: string; message?: string } } }).root
        ?.serverError,
    focused,
  };
}

/** Every field of the envelope KbError carries, so no spec has to remember the argument order. */
const kbError = (
  error_class: ConstructorParameters<typeof KbError>[0],
  options: {
    readonly retryable?: boolean;
    readonly retry_after?: number | null;
    readonly request_id?: string | null;
    readonly message?: string;
    readonly errors?: Readonly<Record<string, readonly string[]>> | null;
  } = {},
): KbError =>
  new KbError(
    error_class,
    options.retryable ?? false,
    options.retry_after ?? null,
    // `in`, not `??`: an explicit `request_id: null` is a case under test (an envelope with no
    // request id renders no "(ref …)"), and coalescing would silently substitute the default.
    'request_id' in options ? options.request_id : REQUEST_ID,
    options.message ?? OPERATOR_MESSAGE,
    options.errors ?? null,
  );

// ── the branch that RETURNS ──────────────────────────────────────────────────────────────────────

describe('validation returns EARLY, and that is the load-bearing half', () => {
  it('writes the per-field message and leaves the banner slot EMPTY', () => {
    const h = harness();

    applyAuthError(
      h.form,
      KNOWN_PATHS,
      kbError('validation', { errors: { email: ['These credentials do not match our records.'] } }),
    );

    // Laravel-translated validation strings ARE end-user copy and are shown verbatim.
    expect(h.errors().email?.message).toBe('These credentials do not match our records.');
    expect(h.errors().email?.type).toBe('server');
    // THE ASSERTION THE EARLY RETURN EXISTS FOR. Falling through to setBanner() overwrites
    // `root.serverError` with a generic sentence; because RHF's root slot is single, the field write
    // above survives but the user is told "Some details need fixing" on a form that already says what.
    // Delete the `return` in auth-error.ts and this is the spec that fails.
    expect(h.banner()).toBeUndefined();
  });

  it('bad credentials land on a FIELD, never as a session-expiry bounce', () => {
    // Decision D7: a wrong password is 422 `validation` keyed on `email`, never 401. Routed through
    // the `authentication` arm it would render "Your session has ended. Sign in again to continue."
    // to somebody making their first sign-in attempt.
    const h = harness();

    applyAuthError(h.form, KNOWN_PATHS, kbError('validation', { errors: { email: ['Wrong.'] } }));

    expect(h.errors().email?.message).toBe('Wrong.');
    expect(h.banner()?.message).toBeUndefined();
    expect(h.focused).toEqual(['email']);
  });

  it('routes a key this form does not render to the banner, verbatim', () => {
    const h = harness();

    applyAuthError(
      h.form,
      KNOWN_PATHS,
      kbError('validation', { errors: { token: ['This invitation is no longer valid.'] } }),
    );

    // `applyServerErrors`'s orphan path — still inside the early return, so the message is the
    // server's own translated string and NOT ERROR_COPY.validation.
    expect(h.banner()?.message).toBe('This invitation is no longer valid.');
    expect(h.banner()?.message).not.toBe(ERROR_COPY.validation);
    expect(h.errors().email).toBeUndefined();
  });

  it('does NOT return early on a 422 that carries no `errors` map', () => {
    // An idempotency conflict is 422 with an `error_class` and no field map (docs/22). The guard is
    // `error.errors !== null`, not the class alone: dropping the null check calls
    // `Object.entries(null)` inside applyServerErrors and throws a TypeError from the handler whose
    // job was to display the failure.
    const h = harness();

    applyAuthError(h.form, KNOWN_PATHS, kbError('validation', { errors: null }));

    expect(h.banner()?.type).toBe('validation');
    expect(h.banner()?.message).toBe(`${ERROR_COPY.validation} (ref ${REQUEST_ID})`);
    expect(h.errors().email).toBeUndefined();
  });
});

// ── the branch that FALLS THROUGH ────────────────────────────────────────────────────────────────

describe('rate_limit falls THROUGH — the cooldown AND the sentence', () => {
  it('calls onRateLimit with the header seconds and still writes the banner', () => {
    const h = harness();
    const onRateLimit = vi.fn();

    applyAuthError(h.form, KNOWN_PATHS, kbError('rate_limit', { retry_after: 42 }), {
      onRateLimit,
    });

    // A throttled caller needs BOTH: a disabled submit and a sentence saying why. An early return
    // here (symmetry with the validation arm is the tempting mistake) disables the button and
    // explains nothing, which reads as a broken form.
    expect(onRateLimit).toHaveBeenCalledExactlyOnceWith(42);
    expect(h.banner()?.type).toBe('rate_limit');
    expect(h.banner()?.message).toBe(`${ERROR_COPY.rate_limit} (ref ${REQUEST_ID})`);
  });

  it('passes null through rather than inventing a window when Retry-After is absent', () => {
    // `retry_after` comes off the RESPONSE HEADER, not the JSON envelope. A 429 without it degrades
    // to no cooldown; guessing a default means submitting inside the window we were told to wait.
    const h = harness();
    const onRateLimit = vi.fn();

    applyAuthError(h.form, KNOWN_PATHS, kbError('rate_limit', { retry_after: null }), {
      onRateLimit,
    });

    expect(onRateLimit).toHaveBeenCalledExactlyOnceWith(null);
    expect(h.banner()?.type).toBe('rate_limit');
  });

  it('is not called for any other class', () => {
    const onRateLimit = vi.fn();

    for (const error_class of ['validation', 'authorization', 'authentication', 'tenant_quota'] as const) {
      applyAuthError(harness().form, KNOWN_PATHS, kbError(error_class, { errors: null }), {
        onRateLimit,
      });
    }

    expect(onRateLimit).not.toHaveBeenCalled();
  });
});

describe('authorization invalidates the session cache, and authentication deliberately does not', () => {
  it('calls onAuthorization and still writes the banner', () => {
    const h = harness();
    const onAuthorization = vi.fn();

    applyAuthError(h.form, KNOWN_PATHS, kbError('authorization'), { onAuthorization });

    // "You were removed from the organization you just acted on" — the stale membership list has to go.
    expect(onAuthorization).toHaveBeenCalledOnce();
    expect(h.banner()?.type).toBe('authorization');
  });

  it('does NOT call onAuthorization for `authentication`', () => {
    // That one bounces to /login; invalidating a cache the navigation is about to destroy is noise.
    const onAuthorization = vi.fn();

    applyAuthError(harness().form, KNOWN_PATHS, kbError('authentication'), { onAuthorization });

    expect(onAuthorization).not.toHaveBeenCalled();
  });

  it('tolerates both handlers being absent — they are optional and the banner is not', () => {
    const h = harness();

    expect(() =>
      applyAuthError(h.form, KNOWN_PATHS, kbError('rate_limit', { retry_after: 9 })),
    ).not.toThrow();
    expect(h.banner()?.type).toBe('rate_limit');
  });
});

// ── the override ─────────────────────────────────────────────────────────────────────────────────

describe('copyFor replaces the sentence for one screen only', () => {
  it('uses the override when it returns a string', () => {
    // The measured reason it exists: POST /auth/email/verify answers an unusable confirmation link
    // with `authorization`, the taxonomy-correct class — but ERROR_COPY.authorization reads "You do
    // not have access to this.", which tells somebody who clicked a link in their own inbox that they
    // lack a permission, and contradicts the explanation the page renders below the banner.
    const h = harness();
    const copy = 'This confirmation link is no longer usable. Request a new one below.';

    applyAuthError(h.form, KNOWN_PATHS, kbError('authorization'), { copyFor: () => copy });

    expect(h.banner()?.message).toBe(copy);
    expect(h.banner()?.type).toBe('authorization');
  });

  it('falls back to the class-mapped sentence when the override returns undefined', () => {
    // `?? endUserCopy(error)` and not `||`: an override that returns the empty string is a screen
    // asking for a blank banner, which is a different bug from asking for the default. Coalescing on
    // `||` would hide it. (This asserts the undefined path; the empty-string path is asserted next.)
    const h = harness();

    applyAuthError(h.form, KNOWN_PATHS, kbError('tenant_quota'), { copyFor: () => undefined });

    expect(h.banner()?.message).toBe(`${ERROR_COPY.tenant_quota} (ref ${REQUEST_ID})`);
  });

  it('honours an override that returns the empty string rather than substituting the default', () => {
    const h = harness();

    applyAuthError(h.form, KNOWN_PATHS, kbError('tenant_quota'), { copyFor: () => '' });

    // Nullish coalescing, so '' wins. Documented rather than endorsed: a screen that wants no banner
    // should not have one, and the day this behaviour is wrong the fix is at the call site.
    expect(h.banner()?.message).toBe('');
  });

  it('is NEVER consulted on the validation path, because that path returned', () => {
    const h = harness();
    const copyFor = vi.fn(() => 'should not appear');

    applyAuthError(
      h.form,
      KNOWN_PATHS,
      kbError('validation', { errors: { email: ['Wrong.'] } }),
      { copyFor },
    );

    expect(copyFor).not.toHaveBeenCalled();
    expect(h.errors().email?.message).toBe('Wrong.');
  });

  it('receives the error, so an override can branch on what the server actually said', () => {
    const h = harness();
    const seen: Array<string | null> = [];

    applyAuthError(h.form, KNOWN_PATHS, kbError('retrieval'), {
      copyFor: (error) => {
        seen.push(error.error_class);
        return undefined;
      },
    });

    expect(seen).toEqual(['retrieval']);
  });
});

// ── the no-envelope arm, and the rule both functions obey ────────────────────────────────────────

describe('a thrown value that is not a KbError', () => {
  it('is `unknown`, and no class is invented to fill the slot', () => {
    const h = harness();

    applyAuthError(h.form, KNOWN_PATHS, new TypeError('Failed to fetch'));

    expect(h.banner()?.type).toBe('unknown');
    // No `(ref …)`: there is no request_id to show, and inventing one would be a reference support
    // cannot grep. Never `internal_dependency`, which is retryable AND pages.
    expect(h.banner()?.message).toBe(ERROR_COPY.unknown);
    expect(h.banner()?.message).not.toContain('Failed to fetch');
  });

  it('does not leak the thrown error message into the banner', () => {
    const h = harness();

    applyAuthError(h.form, KNOWN_PATHS, new Error(OPERATOR_MESSAGE));

    expect(h.banner()?.message).not.toContain('api-7.internal');
    expect(h.banner()?.message).not.toContain('sk-live-9f3a');
  });
});

/**
 * THE ONE RULE THAT SPANS BOTH FUNCTIONS, asserted over both rather than once each — they are
 * declared as counterparts (`action-error.ts`: "applyAuthError's counterpart for a mutation THAT HAS
 * NO FORM"), and a rule enforced in one copy and not the other is the drift the split invites.
 *
 * `message` is operator-facing: an internal hostname, raw upstream provider text, and — as
 * OPERATOR_MESSAGE spells out — plausibly a credential fragment. Non-negotiable 9 of CLAUDE.md is that
 * no provider credential reaches a client, a log, an API response or an audit detail; a banner is all
 * four in one.
 */
describe('neither renderer ever shows the envelope `message`', () => {
  /**
   * DERIVED FROM `ERROR_COPY` ITSELF, not a hand-picked list, so a class added to the table is
   * exercised here the day it lands rather than the day somebody remembers this file. `[klass, copy]`
   * pairs also mean no dynamic index into the map inside the assertion.
   *
   * `unknown` is excluded because it is the key for `error_class: null` rather than a class name — a
   * `KbError` cannot carry it, and its arm is asserted in the non-KbError suite above.
   */
  const CASES = Object.entries(ERROR_COPY).filter(([klass]) => klass !== 'unknown') as ReadonlyArray<
    readonly [ConstructorParameters<typeof KbError>[0] & string, string]
  >;

  it('covers every class the copy table declares, and there are enough of them to matter', () => {
    // The positive control for the two `it.each` blocks below: an empty or one-element table would run
    // them and prove nothing.
    expect(CASES.length).toBeGreaterThan(15);
  });

  it.each(CASES)(
    'applyAuthError on %s renders the class sentence, not the message',
    (klass, copy) => {
      const h = harness();

      // `errors: null` on every case, including `validation`: with a field map the validation arm
      // returns early by design, which is asserted at the top of this file. Here the question is what
      // the BANNER says, and every class reaches it.
      applyAuthError(h.form, KNOWN_PATHS, kbError(klass, { errors: null }));

      const rendered = h.banner()?.message ?? '';
      expect(rendered).toBe(`${copy} (ref ${REQUEST_ID})`);
      expect(rendered).not.toContain('api-7.internal');
      expect(rendered).not.toContain('sk-live-9f3a');
    },
  );

  it.each(CASES)(
    'actionErrorCopy on %s renders the class sentence, not the message',
    (klass, copy) => {
      const rendered = actionErrorCopy(kbError(klass, { errors: null }));

      expect(rendered).toBe(`${copy} (ref ${REQUEST_ID})`);
      expect(rendered).not.toContain('api-7.internal');
      expect(rendered).not.toContain('sk-live-9f3a');
    },
  );
});

describe('actionErrorCopy — the formless counterpart', () => {
  it('joins every validation message, because there is no field to key them to', () => {
    // `InvitationService` throws ValidationException::withMessages(['invitation' => [NOT_REVOCABLE]])
    // for an invitation that was already accepted. There is no setError target next to a Revoke
    // button, and running it through endUserCopy would show "Some details need fixing before this can
    // be saved." — nonsense beside a button, and it says nothing about why the row did not change.
    expect(
      actionErrorCopy(
        kbError('validation', {
          errors: { invitation: ['This invitation can no longer be revoked.'], token: ['Expired.'] },
        }),
      ),
    ).toBe('This invitation can no longer be revoked. Expired.');
  });

  it('falls back to the class sentence on a 422 whose map is EMPTY', () => {
    // A contract violation rather than a message. Rendering the join would produce a blank alert the
    // user cannot act on and cannot report.
    expect(actionErrorCopy(kbError('validation', { errors: {} }))).toBe(
      `${ERROR_COPY.validation} (ref ${REQUEST_ID})`,
    );
  });

  it('falls back on a 422 whose map is null', () => {
    expect(actionErrorCopy(kbError('validation', { errors: null }))).toBe(
      `${ERROR_COPY.validation} (ref ${REQUEST_ID})`,
    );
  });

  it('treats a non-KbError exactly as applyAuthError does', () => {
    // The shared arm. Two implementations of "no envelope parsed" is two places for `unknown` to
    // become an invented class.
    const h = harness();
    applyAuthError(h.form, KNOWN_PATHS, new TypeError('Failed to fetch'));

    expect(actionErrorCopy(new TypeError('Failed to fetch'))).toBe(h.banner()?.message);
    expect(actionErrorCopy(new TypeError('Failed to fetch'))).toBe(ERROR_COPY.unknown);
  });

  it('shows a request_id when there is one and no "(ref )" when there is not', () => {
    expect(actionErrorCopy(kbError('storage', { request_id: null }))).toBe(ERROR_COPY.storage);
    expect(actionErrorCopy(kbError('storage'))).toBe(`${ERROR_COPY.storage} (ref ${REQUEST_ID})`);
  });
});
