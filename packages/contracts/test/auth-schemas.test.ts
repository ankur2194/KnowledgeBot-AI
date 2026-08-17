import { describe, expect, it } from 'vitest';
import type { z } from 'zod';

import {
  forgotPasswordFormDefaults,
  forgotPasswordSchema,
  loginFormDefaults,
  loginSchema,
  registerFormDefaults,
  registerSchema,
  resetPasswordFormDefaults,
  resetPasswordSchema,
} from '../src/forms/auth.js';
import { isOwnershipPath } from '../src/forms/ownership.js';

/**
 * The four auth schemas, asserted on the things a rule manifest cannot express and the drift
 * harness therefore cannot probe:
 *
 *   - the ABSENCE of two fields (`email` on register, `remember` on login), each a decision with a
 *     security or a set-equality consequence;
 *   - the deliberate absence of `.trim()` on the three password fields, which is the one house-style
 *     exception in this package and reads as an oversight to anyone who has not been told;
 *   - the cross-field confirmation check, and the PATH it lands on.
 *
 * The value-by-value agreement with Laravel belongs to test/form-drift.test.ts and lands with
 * `packages/contracts/rules/{LoginRequest,ForgotPasswordRequest,ResetPasswordRequest,RegisterRequest}.json`.
 * Nothing here duplicates it.
 */

/** A password that satisfies the whole mirrored policy: >=12 chars, lower, upper, digit. */
const STRONG = 'Correct-Horse-9';

const validReset = () => ({
  token: 'a'.repeat(64),
  email: 'user@example.com',
  password: STRONG,
  password_confirmation: STRONG,
});

const validRegister = () => ({
  token: 'a'.repeat(64),
  name: 'Ada Lovelace',
  password: STRONG,
  password_confirmation: STRONG,
});

/** Every leaf path a schema declares, dotted. Same walk as form-drift.test.ts:302-309. */
function schemaPaths(schema: z.ZodType, prefix = ''): string[] {
  const shape = (schema as unknown as { shape?: Record<string, z.ZodType> }).shape;
  if (!shape) return prefix === '' ? [] : [prefix];

  return Object.entries(shape).flatMap(([key, child]) =>
    schemaPaths(child, prefix === '' ? key : `${prefix}.${key}`),
  );
}

const ALL_SCHEMAS = {
  loginSchema,
  forgotPasswordSchema,
  resetPasswordSchema,
  registerSchema,
} as const;

describe('the fields these schemas deliberately do NOT have', () => {
  it('registerSchema has no `email` path — the invitation pins the address', () => {
    // A submitted `email` would make the address client-supplied and would let an invitee register
    // under someone else's. The server must not validate one either, or the drift suite's path-set
    // equality forces it back in here.
    expect(schemaPaths(registerSchema)).toEqual([
      'token',
      'name',
      'password',
      'password_confirmation',
    ]);
    expect(schemaPaths(registerSchema)).not.toContain('email');
  });

  it('registerSchema REJECTS an email rather than stripping it, because it is strictObject', () => {
    // z.object() would strip the key silently and the form would look correct while the escalation
    // attempt went unrecorded — and FormData submissions bypass the parse entirely.
    expect(registerSchema.safeParse({ ...validRegister(), email: 'someone@else.test' }).success).toBe(
      false,
    );
  });

  it('loginSchema has no `remember` path — LoginRequest does not validate one', () => {
    // A Zod path with no matching rule fails form-drift's set equality. "Remember me" is a server
    // change first: a rule, a dumped manifest, then a field.
    expect(schemaPaths(loginSchema)).toEqual(['email', 'password']);
    expect(loginSchema.safeParse({ email: 'a@b.co', password: 'x', remember: true }).success).toBe(
      false,
    );
  });

  it('no auth schema declares an ownership column', () => {
    for (const schema of Object.values(ALL_SCHEMAS)) {
      expect(schemaPaths(schema).filter(isOwnershipPath)).toEqual([]);
    }
  });

  it('no auth schema declares a credential-shaped field other than the password pair', () => {
    for (const [name, schema] of Object.entries(ALL_SCHEMAS)) {
      const suspicious = schemaPaths(schema).filter((path) =>
        /credential|api_key|secret/i.test(path),
      );
      expect(suspicious, name).toEqual([]);
    }
  });
});

describe('passwords are NOT trimmed, and every other string is', () => {
  /**
   * THE HOUSE-STYLE EXCEPTION, asserted rather than commented. Laravel's global `TrimStrings`
   * middleware excludes `password`, `password_confirmation` and `current_password`, so the server
   * hashes the credential VERBATIM. A trimming client would register "hunter2 " (stored untrimmed)
   * and then submit "hunter2" at login — a permanent lockout with correct-looking input, no error
   * anywhere, and nothing to grep.
   */
  it('a leading and trailing space survives login `password`', () => {
    const parsed = loginSchema.parse({ email: 'user@example.com', password: ' x ' });
    expect(parsed.password).toBe(' x ');
  });

  it('a leading and trailing space survives the new-password pair on both schemas', () => {
    const padded = ` ${STRONG} `;
    const reset = resetPasswordSchema.parse({
      ...validReset(),
      password: padded,
      password_confirmation: padded,
    });
    expect(reset.password).toBe(padded);
    expect(reset.password_confirmation).toBe(padded);

    const register = registerSchema.parse({
      ...validRegister(),
      password: padded,
      password_confirmation: padded,
    });
    expect(register.password).toBe(padded);
  });

  it('trims every other string field, because TrimStrings runs before max:', () => {
    // Trim BEFORE the length check: with the order reversed, "  " + 253 characters is 253 to
    // Laravel and 255 to the browser, and the form rejects an address the server accepts.
    expect(loginSchema.parse({ email: '  user@example.com  ', password: 'x' }).email).toBe(
      'user@example.com',
    );
    expect(forgotPasswordSchema.parse({ email: '\tuser@example.com\n' }).email).toBe(
      'user@example.com',
    );

    const reset = resetPasswordSchema.parse({ ...validReset(), token: `  ${'a'.repeat(64)}  ` });
    expect(reset.token).toBe('a'.repeat(64));

    const register = registerSchema.parse({ ...validRegister(), name: '  Ada Lovelace  ' });
    expect(register.name).toBe('Ada Lovelace');
  });

  it('accepts an email at the max:254 boundary once trimmed', () => {
    const local = 'a'.repeat(254 - '@example.com'.length);
    const address = `${local}@example.com`;
    expect(address).toHaveLength(254);
    expect(forgotPasswordSchema.safeParse({ email: `  ${address}  ` }).success).toBe(true);
    expect(forgotPasswordSchema.safeParse({ email: `x${address}` }).success).toBe(false);
  });
});

describe('the confirmation check', () => {
  it('raises on `password_confirmation`, not on `password`', () => {
    // react-hook-form focuses the field the error is keyed to, and the field the user must fix is
    // the second one. Keyed to `password` it would focus the field that was probably right.
    for (const [name, schema, value] of [
      ['resetPasswordSchema', resetPasswordSchema, { ...validReset(), password_confirmation: 'Nope-12345678' }],
      ['registerSchema', registerSchema, { ...validRegister(), password_confirmation: 'Nope-12345678' }],
    ] as const) {
      const result = schema.safeParse(value);
      expect(result.success, name).toBe(false);
      const paths = result.success ? [] : result.error.issues.map((issue) => issue.path.join('.'));
      expect(paths, name).toContain('password_confirmation');
      expect(paths, name).not.toContain('password');
    }
  });

  it('accepts a matching pair', () => {
    expect(resetPasswordSchema.safeParse(validReset()).success).toBe(true);
    expect(registerSchema.safeParse(validRegister()).success).toBe(true);
  });
});

describe('the mirrored password policy — all four constraints, not just the length', () => {
  const cases: ReadonlyArray<readonly [string, string]> = [
    ['too short (11)', 'Abcdefghij1'],
    ['no lower-case', 'ABCDEFGHIJ12'],
    ['no upper-case', 'abcdefghij12'],
    ['no digit', 'Abcdefghijkl'],
  ];

  it.each(cases)('rejects a password that is %s', (_label, password) => {
    // Mirroring only `min:12` is the version that gets shipped: the form accepts it, the server
    // 422s on a rule the user was never shown, and the message arrives after the round trip.
    expect(
      resetPasswordSchema.safeParse({ ...validReset(), password, password_confirmation: password })
        .success,
    ).toBe(false);
    expect(
      registerSchema.safeParse({ ...validRegister(), password, password_confirmation: password })
        .success,
    ).toBe(false);
  });

  it('accepts the 12-character boundary when all three classes are present', () => {
    const boundary = 'Abcdefghij12';
    expect(boundary).toHaveLength(12);
    expect(
      resetPasswordSchema.safeParse({
        ...validReset(),
        password: boundary,
        password_confirmation: boundary,
      }).success,
    ).toBe(true);
  });

  it('accepts non-ASCII case, because the server rule is \\p{Ll}/\\p{Lu} and not [a-z]', () => {
    // `[a-z]` would reject a passphrase Laravel accepts — a form stricter than the server.
    const unicode = 'Ärgerlich-2026';
    expect(
      registerSchema.safeParse({
        ...validRegister(),
        password: unicode,
        password_confirmation: unicode,
      }).success,
    ).toBe(true);
  });

  it('leaves LOGIN alone: an existing password predates the policy', () => {
    // Mirroring min:12 on login would lock out every account created before it — access removed,
    // and nobody reports it as a validation bug.
    expect(loginSchema.safeParse({ email: 'user@example.com', password: 'x' }).success).toBe(true);
  });

  it('mirrors max:255 on the password and NOT on the confirmation, exactly as the server does', () => {
    const long = `Aa1${'b'.repeat(253)}`;
    expect(long).toHaveLength(256);
    expect(
      registerSchema.safeParse({ ...validRegister(), password: long, password_confirmation: long })
        .success,
    ).toBe(false);
    // `password_confirmation` server rule is `['bail','required','string']` — no max. A max here
    // would be a form stricter than the server on a field whose only real constraint is equality.
    const at255 = `Aa1${'b'.repeat(252)}`;
    expect(at255).toHaveLength(255);
    expect(
      registerSchema.safeParse({
        ...validRegister(),
        password: at255,
        password_confirmation: at255,
      }).success,
    ).toBe(true);
  });
});

describe('the *FormDefaults mappers', () => {
  it('loginFormDefaults takes no source and yields an empty credential', () => {
    expect(loginFormDefaults()).toEqual({ email: '', password: '' });
    // A source argument would be an invitation to seed form state from server data — the
    // `reset(resource)` mistake arriving through the back door.
    expect(loginFormDefaults).toHaveLength(0);
  });

  it('forgotPasswordFormDefaults prefills from a query string, and is fine without one', () => {
    expect(forgotPasswordFormDefaults()).toEqual({ email: '' });
    expect(forgotPasswordFormDefaults({})).toEqual({ email: '' });
    expect(forgotPasswordFormDefaults({ email: 'user@example.com' })).toEqual({
      email: 'user@example.com',
    });
  });

  it('resetPasswordFormDefaults carries the link and NEVER a password', () => {
    const defaults = resetPasswordFormDefaults({ token: 'tok', email: 'user@example.com' });
    expect(defaults).toEqual({
      token: 'tok',
      email: 'user@example.com',
      password: '',
      password_confirmation: '',
    });
  });

  it('registerFormDefaults reaches exactly one field, and it is not the email', () => {
    const defaults = registerFormDefaults({ token: 'tok' });
    expect(defaults).toEqual({
      token: 'tok',
      name: '',
      password: '',
      password_confirmation: '',
    });
    // The invitation PREVIEW resource carries organization_name, email, role and expires_at and no
    // token at all; `reset(preview)` would put every one of those into form state, including the
    // `email` registerSchema exists to not have.
    expect(Object.keys(defaults)).not.toContain('email');
  });

  it('every defaults object parses cleanly once the user has typed a valid value', () => {
    // The property that matters: a defaults object must be a valid INPUT even though it is not yet
    // valid OUTPUT. A defaults object the schema rejects structurally means a form that shows
    // errors before the user has touched it.
    expect(loginSchema.safeParse({ ...loginFormDefaults(), email: 'a@b.co', password: 'x' }).success).toBe(
      true,
    );
    expect(
      registerSchema.safeParse({
        ...registerFormDefaults({ token: 'a'.repeat(64) }),
        name: 'Ada',
        password: STRONG,
        password_confirmation: STRONG,
      }).success,
    ).toBe(true);
  });
});
