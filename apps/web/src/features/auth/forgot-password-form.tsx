'use client';

import {
  forgotPasswordFormDefaults,
  forgotPasswordSchema,
  type ForgotPasswordIn,
  type ForgotPasswordOut,
} from '@kb/contracts/forms';
import type { AcknowledgementResource } from '@kb/contracts';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { asText } from '@/lib/forms/as-text';

import { applyAuthError } from './auth-error';
import { FORGOT_PASSWORD_KNOWN_PATHS } from './known-paths';
import { useCooldown } from './use-cooldown';

/**
 * Request a password-reset link.
 *
 * ── THE CONFIRMATION IS THE SECURITY PROPERTY OF THIS SCREEN ─────────────────────────────────────
 *
 * `POST /api/v1/auth/forgot-password` answers **200 with a byte-identical body for every outcome** —
 * link sent, address unknown, and broker-throttled alike. That is deliberate and it is load-bearing:
 * returning 429 on the throttled case while returning 200 on the unknown one is ITSELF an enumeration
 * oracle, because probing the same address twice and getting a throttle proves the first probe created
 * a reset token, which proves the account exists.
 *
 * So the client must not undo it. `CONFIRMATION` below:
 *
 *  - is rendered on success and ONLY on success, with no branch of any kind beneath it, because the
 *    client is handed nothing to branch on;
 *  - is phrased CONDITIONALLY ("If that address belongs to an account…"), so it asserts nothing about
 *    whether the account exists;
 *  - DOES NOT INTERPOLATE THE SUBMITTED ADDRESS, and that is not a copy preference. It is what makes
 *    "the rendered confirmation is byte-identical for a known and an unknown address" a real,
 *    mechanically checkable assertion instead of a tautology — the spec captures the rendered string
 *    for both and compares them, so the day somebody personalises this sentence the test goes red.
 *
 * ── SUCCESS IS AN INLINE ALERT, NOT A NAVIGATION ─────────────────────────────────────────────────
 *
 * There is nowhere to navigate TO: the next step happens in the user's mailbox, and a redirect to a
 * "check your email" route would need the address (or a flag) in a URL to say anything at all — which
 * is exactly the leak the byte-identical body is protecting. So the form stays mounted and the
 * confirmation appears beside it, and the email field stays editable so a typo is one correction away
 * rather than a fresh page load.
 *
 * `role="status"` + `aria-live="polite"`: polite because nothing is wrong and the user is not to be
 * interrupted mid-keystroke, and `status` rather than `alert` so this element and the ERROR banner are
 * distinguishable by role — `<Alert>` hard-codes `role="alert"`, and two elements answering
 * `getByRole('alert')` would make every error assertion in the spec ambiguous.
 */
const CONFIRMATION =
  'If that address belongs to an account, a password reset link is on its way. Check your inbox and your spam folder.';

/**
 * `POST /api/v1/auth/forgot-password` — spelled once.
 *
 * It belongs beside `LOGIN_PATH`/`LOGOUT_PATH` in the endpoint table in session.ts, and it is HERE
 * only because three agents are editing this feature directory concurrently in this batch and that
 * file's edit budget is one property. Consolidate it into that table when the contention ends; do not
 * meanwhile add a second spelling at a call site.
 */
const FORGOT_PASSWORD_PATH = '/api/v1/auth/forgot-password';

/*
 * The paths this form RENDERS come from `FORGOT_PASSWORD_KNOWN_PATHS` in `@/features/auth/known-paths`,
 * which reads `ForgotPasswordRequest`'s own dumped `rules()`. This file used to derive them locally as
 * `Object.keys(forgotPasswordFormDefaults())` — provably equal to the manifest today, and divergent in
 * exactly one real case (a server field added `sometimes|nullable`). That case, and why the manifest is
 * the better source, is written out where the constant now lives.
 */

export function ForgotPasswordForm({ email }: { readonly email: string }) {
  // THREE GENERICS, input then output. `emailField` is a `z.preprocess`, so its INPUT type is
  // `unknown` while its output is a string; one generic pins both and fails to typecheck against
  // zodResolver.
  const form = useForm<ForgotPasswordIn, unknown, ForgotPasswordOut>({
    resolver: zodResolver(forgotPasswordSchema),
    // A QUERY-STRING prefill, not a resource — `/forgot-password?email=…` is where a failed login
    // sends the user. `forgotPasswordFormDefaults` takes its own narrow `{email?}` shape precisely so
    // a server resource cannot be handed to it by mistake.
    defaultValues: forgotPasswordFormDefaults({ email }),
    mode: 'onTouched',
  });

  const cooldown = useCooldown();

  const request = useMutation<AcknowledgementResource, Error, ForgotPasswordOut>({
    mutationFn: async (values) =>
      // 200 `{data: {acknowledged: true}}`. The envelope is unwrapped ONCE, at the fetch boundary, by
      // `browserFetchData` — never by reaching into `.data` here. The body is not branched on and
      // cannot be: it says the same thing for every outcome.
      //
      // `sessionCredential()`, not the login form's unconditional `refreshCsrfToken()`: this screen is
      // reached from `/login`, so the document has already talked to Laravel and holds the cookie, and
      // the lazy helper pays one `GET /sanctum/csrf-cookie` on the cold path anyway.
      browserFetchData<AcknowledgementResource>({
        path: FORGOT_PASSWORD_PATH,
        method: 'POST',
        // The RESOLVER'S OUTPUT, handed over by handleSubmit — trimmed, parsed and stripped. Never
        // `form.getValues()`, which is raw form state that has been through no schema at all.
        body: values,
        credential: await sessionCredential(),
      }),
    onError: (error) => {
      // The shared handler, not a private branch table: one rule cannot drift across six forms. A 429
      // from Laravel's own throttle middleware (distinct from the broker throttle, which is folded
      // into the identical 200) yields the cooldown; everything else lands on the banner as a
      // class-mapped sentence plus `(ref …)`, never the envelope's operator-facing `message`.
      applyAuthError(form, FORGOT_PASSWORD_KNOWN_PATHS, error, {
        onRateLimit: (seconds) => cooldown.start(seconds),
      });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET — see login-form.tsx for the full account. No password
        // here, but the native fallback still put an address in the URL bar and two logs. Asserted for
        // every form by tests/unit/form-method.test.ts.
        method="post"
        onSubmit={form.handleSubmit((values) => {
          request.mutate(values);
        })}
        // The browser's own validation bubbles would pre-empt the schema's messages and cannot be
        // styled or read consistently by a screen reader.
        noValidate
        className="space-y-4"
      >
        {/* shadcn's FormMessage renders `formState.errors[name]`, and `root.serverError` is not under a
            field name — so the banner is rendered explicitly. <Alert/> already carries role="alert". */}
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        {/* Gated on the mutation's own state and nothing else. A second submit re-enters `pending`,
            which hides this until the next 200 — so the confirmation never describes a request that is
            still in flight. */}
        {request.isSuccess ? (
          <Alert role="status" aria-live="polite">
            <AlertDescription>{CONFIRMATION}</AlertDescription>
          </Alert>
        ) : null}

        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  value={asText(field.value)}
                  type="email"
                  autoComplete="email"
                  autoCapitalize="none"
                  spellCheck={false}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={request.isPending || cooling} className="w-full">
          {request.isPending ? 'Sending…' : 'Email a reset link'}
        </Button>

        {/* polite, not assertive: it updates once a second and must not interrupt whatever the user is
            doing. */}
        <p aria-live="polite" className="text-muted-foreground text-sm">
          {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
        </p>
      </form>
    </Form>
  );
}

