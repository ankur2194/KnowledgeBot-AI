'use client';

import {
  resetPasswordFormDefaults,
  resetPasswordSchema,
  type ResetPasswordIn,
  type ResetPasswordOut,
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
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { asText } from '@/lib/forms/as-text';

import { applyAuthError } from './auth-error';
import { RESET_PASSWORD_KNOWN_PATHS } from './known-paths';
import { browserNavigation } from './session';
import { useCooldown } from './use-cooldown';
import { useStripTokenFromUrl } from './use-strip-token-from-url';

/**
 * Choose a new password from an emailed link.
 *
 * ── THE TOKEN IS STRIPPED FROM THE URL ON MOUNT, AND BOTH HALVES OF THAT DEFENCE MATTER ──────────
 *
 * A reset token has to travel in a URL because an email can only carry one — that part is unavoidable.
 * What is avoidable is it STAYING there. Left alone, `/reset-password?token=…` sits in the address bar
 * for as long as the tab is open, is written into the browser's history and its on-disk session store,
 * is re-readable by anyone who presses Back after the reset completes, and is offered as a `Referer`
 * to every outbound navigation from this page.
 *
 * Layer 1, on mount: `useStripTokenFromUrl()` removes `token` from the visible URL with
 * `history.replaceState`. It is a REPLACE, so no new history entry is created and the entry that held
 * the token is overwritten. The value this form submits is the prop that arrived before the strip —
 * React state, not the address bar — so removing it changes nothing about the request.
 *
 * THE HOOK IS SHARED WITH THE INVITATION SCREENS AND IS DELIBERATELY NOT RE-IMPLEMENTED HERE. Two
 * copies of one mechanism is two places for it to drift, and the two details that make it correct are
 * exactly the ones a retyped copy loses: it re-serialises from `URL` so a second query parameter and a
 * fragment survive, and it passes `window.history.state` back UNCHANGED because the App Router keeps
 * its own routing state in that object — handing `null` there breaks the subsequent back/forward
 * navigation with nothing logged.
 *
 * Layer 2, elsewhere: `next.config.ts` sets `Referrer-Policy: same-origin` on every non-`/c/` path,
 * which is what keeps the token out of the `Referer` header during the window before this effect runs
 * (and out of it entirely for any request that leaves this origin). SAY SO WHEREVER THAT HEADER LIST IS
 * EDITED: it is not decoration and "tidying" it silently removes half of this.
 *
 * Layer 3, on success: `browserNavigation.replace('/login')`, never `assign`, so the entry that
 * carried the credential leaves the history stack rather than being pushed past.
 *
 * A consequence, and it is the correct one: a REFRESH of this page lands on the server component with
 * no `?token=`, which renders the "incomplete link" panel instead of this form. A reset link is
 * single-use; a form that appeared to still work after the token was consumed would be a lie.
 *
 * ── WHY `router.replace` IS NOT USED FOR THE STRIP ───────────────────────────────────────────────
 *
 * `useRouter().replace('/reset-password')` is a soft navigation: it re-requests the RSC payload for
 * this route, re-renders the server component with no token, and this form unmounts mid-life taking
 * the token in its state with it — including a half-typed password. `history.replaceState` rewrites the
 * entry in place and React never re-renders; Next 15+ supports it explicitly for shallow URL updates.
 */

/**
 * `POST /api/v1/auth/reset-password` — spelled once.
 *
 * It belongs beside `LOGIN_PATH`/`LOGOUT_PATH` in the endpoint table in session.ts, and it is HERE
 * only because three agents are editing this feature directory concurrently in this batch and that
 * file's edit budget is one property. Consolidate it into that table when the contention ends.
 */
const RESET_PASSWORD_PATH = '/api/v1/auth/reset-password';

/**
 * The paths this form RENDERS — `['email', 'password', 'password_confirmation']` — derived, never
 * typed out, and `token` is subtracted.
 *
 * ── THE SUBTRACTION IS THE WHOLE POINT, AND IT IS THE SUBTLEST THING ON THIS SCREEN ─────────────
 *
 * Laravel collapses invalid, expired and already-consumed tokens AND an unknown user into ONE 422
 * keyed on `token` — deliberately indistinguishable, because four distinguishable answers is an
 * enumeration oracle. `token` renders as a HIDDEN input, which has no focusable ref, so routing that
 * key through `setError('token')` would write the only message the user needs into a control that
 * displays nowhere: they submit, the server rejects, nothing changes on screen, and they submit again.
 * With `token` absent from this set, `applyServerErrors` treats it as an orphan and routes it to the
 * single `root.serverError` slot, which is exactly where "this link is no longer valid" belongs.
 *
 * The set comes from `RESET_PASSWORD_KNOWN_PATHS` in `@/features/auth/known-paths`, which reads
 * `ResetPasswordRequest`'s own dumped `rules()` and subtracts `token`. This file used to derive it
 * locally from `resetPasswordFormDefaults({token:'', email:''})` with placeholder arguments for a key
 * walk; that was provably equal to the manifest today and divergent in one real case, documented where
 * the constant now lives.
 */

export function ResetPasswordForm({
  token,
  email,
}: {
  /** From `?token=`. Untrusted, unvalidated here, and re-checked by Laravel — never inspected for
   *  shape: a client-side length or charset check would tell the holder their token is the wrong
   *  KIND, which the server's one indistinguishable answer exists to avoid. */
  readonly token: string;
  /** From `?email=`. Rendered read-only: the broker pairs it with the token, so the only thing an
   *  edit can do is turn a working link into "no longer valid". */
  readonly email: string;
}) {
  // Idempotent, and a no-op wherever the token was never in the URL to begin with (a component spec,
  // or a re-mount after the first strip). `email` deliberately stays: it is not a capability — holding
  // it grants nothing without the token — and narrowing the change to exactly the credential keeps the
  // reason for the change legible.
  useStripTokenFromUrl();

  // THREE GENERICS, input then output. `emailField` is a `z.preprocess`, so its INPUT type is
  // `unknown` while its output is a string; one generic pins both and fails to typecheck against
  // zodResolver.
  const form = useForm<ResetPasswordIn, unknown, ResetPasswordOut>({
    resolver: zodResolver(resetPasswordSchema),
    // The two values that arrived in the LINK, through the narrow `ResetPasswordLink` pick. There is
    // no server resource in this flow at all, and `reset(resource)` has nothing to grab hold of here.
    defaultValues: resetPasswordFormDefaults({ token, email }),
    mode: 'onTouched',
  });

  const cooldown = useCooldown();

  const reset = useMutation<AcknowledgementResource, Error, ResetPasswordOut>({
    mutationFn: async (values) =>
      // 200 `{data: {acknowledged: true}}`, unwrapped ONCE at the fetch boundary by
      // `browserFetchData` — never by reaching into `.data` at a render site.
      browserFetchData<AcknowledgementResource>({
        path: RESET_PASSWORD_PATH,
        method: 'POST',
        // The RESOLVER'S OUTPUT, from handleSubmit: `{token, email, password, password_confirmation}`,
        // parsed and stripped, with the passwords deliberately UNTRIMMED (Laravel's TrimStrings
        // excepts them, so trimming here would silently store a different secret than the one typed).
        // Never `form.getValues()`.
        body: values,
        credential: await sessionCredential(),
      }),
    onSuccess: () => {
      // ── A SUCCESSFUL RESET DOES NOT SIGN THE USER IN, AND MUST NOT ─────────────────────────────
      // Laravel answers an acknowledgement rather than a session. Sanctum's `AuthenticateSession`
      // compares a password hash on every request, so changing the password kills every OTHER session
      // for this user on its next request — and a session minted here would be killed by the very
      // change that created it. So: send them to sign in with the new password.
      //
      // `replace`, NOT `assign`. The URL being left behind carried a single-use credential, so it must
      // leave the history stack rather than have a new entry pushed on top of it — otherwise one Back
      // press puts the token back in the address bar. `window.location.*` is never called directly:
      // `replace` is `[LegacyUnforgeable]` and cannot be spied on, so a spec reaching for it navigates
      // the harness away instead of observing anything.
      browserNavigation.replace('/login');
    },
    onError: (error) => {
      // The shared handler, not a private branch table: one rule cannot drift across six forms. The
      // 422-on-`token` case above lands on the banner through it, because `token` is not in
      // RESET_PASSWORD_KNOWN_PATHS.
      applyAuthError(form, RESET_PASSWORD_KNOWN_PATHS, error, {
        onRateLimit: (seconds) => cooldown.start(seconds),
      });
    },
  });

  /**
   * ONE BANNER, TWO SOURCES, and the second is the client-side half of the hidden-field problem.
   *
   * `root.serverError` carries the SERVER's orphaned keys (the collapsed token 422). A CLIENT-side
   * issue on `token` — the schema's `max(255)`, or an empty value on a link that lost the parameter —
   * is keyed to `token` by the resolver, and there is no `FormMessage` under a hidden input to render
   * it. Surfacing it here is what keeps the "submit into silence" loop closed from both directions.
   */
  const banner =
    form.formState.errors.root?.serverError?.message ?? form.formState.errors.token?.message;
  const cooling = cooldown.remaining > 0;

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET — see login-form.tsx for the full account. On THIS
        // form the native fallback was measured leaking `password`, `password_confirmation` and the
        // reset `token`'s own landing URL into the query string. Asserted for every form by
        // tests/unit/form-method.test.ts.
        method="post"
        onSubmit={form.handleSubmit((values) => {
          reset.mutate(values);
        })}
        noValidate
        className="space-y-4"
      >
        {banner === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{banner}</AlertDescription>
          </Alert>
        )}

        {/* The token travels as a registered hidden input so the submitted payload is visible to a
            reader of this file rather than implied by `defaultValues`. It is never focused and never
            carries a message; see RESET_PASSWORD_KNOWN_PATHS in known-paths.ts. */}
        <input type="hidden" {...form.register('token')} />

        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email</FormLabel>
              <FormControl>
                {/* `readOnly`, NOT `disabled`. RHF strips a disabled field's name from the submitted
                    values, so the key would never reach the server and `ResetPasswordRequest` would
                    422 it as `required` — a rule the user cannot see or satisfy. */}
                <Input
                  {...field}
                  value={asText(field.value)}
                  type="email"
                  readOnly
                  autoComplete="username"
                  autoCapitalize="none"
                  spellCheck={false}
                />
              </FormControl>
              <FormDescription>
                The address this link was sent to. Request a new link to use a different one.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            <FormItem>
              <FormLabel>New password</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  value={asText(field.value)}
                  type="password"
                  autoComplete="new-password"
                />
              </FormControl>
              {/* The policy is stated BEFORE the request, because all four constraints are mirrored
                  from `ResetPasswordRequest` and a rule the user was never shown arriving as a 422 is
                  the failure this text exists to prevent. */}
              <FormDescription>
                At least 12 characters, with an upper-case letter, a lower-case letter and a digit.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="password_confirmation"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Confirm new password</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  value={asText(field.value)}
                  type="password"
                  autoComplete="new-password"
                />
              </FormControl>
              {/* The mismatch issue is raised on THIS field by the schema's `superRefine`, mirroring
                  Laravel's `confirmed` — the field the user must fix is the second one, and RHF focuses
                  the field the error is keyed to. */}
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={reset.isPending || cooling} className="w-full">
          {reset.isPending ? 'Saving…' : 'Set new password'}
        </Button>

        <p aria-live="polite" className="text-muted-foreground text-sm">
          {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
        </p>
      </form>
    </Form>
  );
}

