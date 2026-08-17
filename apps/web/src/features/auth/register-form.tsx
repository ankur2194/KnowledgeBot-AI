'use client';

import type { SessionResource } from '@kb/contracts';
import {
  registerFormDefaults,
  registerSchema,
  type RegisterIn,
  type RegisterOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Skeleton } from '@/components/ui/skeleton';
import { asText } from '@/lib/forms/as-text';

import { actionErrorCopy } from './action-error';
import { applyAuthError } from './auth-error';
import {
  CONSOLE_ROUTE,
  invitationPreviewQueryOptions,
  INVALID_INVITATION_COPY,
  isInvalidInvitationError,
  registerWithInvitation,
} from './invitation';
import { InvitationPreviewSummary } from './invitation-preview';
import { REGISTER_KNOWN_PATHS } from './known-paths';
import { browserNavigation } from './session';
import { useCooldown } from './use-cooldown';
import { useStripTokenFromUrl } from './use-strip-token-from-url';

/**
 * Invitation-gated registration. THERE IS NO OPEN SIGN-UP: the only way an account comes into
 * existence on this platform is a token somebody with `members.manage` minted.
 *
 * ── IT RECEIVES ONE PRIMITIVE ────────────────────────────────────────────────────────────────────
 * `token: string`, sanitized-by-being-a-string, from the server page's `searchParams`. Anything a
 * server component hands a client component is serialized into the RSC payload embedded in the HTML —
 * the whole object, not the fields the UI reads — so a resource must never cross that boundary.
 *
 * ── THE FORM HAS NO `email` FIELD, AND THAT IS A SECURITY PROPERTY ────────────────────────────────
 * The invitation PINS the address; the server reads it off the invitation row. A submitted `email`
 * would make the address client-supplied and would let an invitee register under somebody else's.
 * `registerSchema` is `{token, name, password, password_confirmation}` and the drift suite's path-set
 * equality is what keeps `RegisterRequest` from growing one either. The address is DISPLAYED, from the
 * preview, and never submitted.
 *
 * ── THE PREVIEW IS THE GATE ON RENDERING THE FORM AT ALL ─────────────────────────────────────────
 * A password field on a page whose token is already dead is a form that can only fail. So the preview
 * runs first, and its refusal replaces the form rather than sitting above it. All five invalid states
 * — unknown, expired, accepted, revoked, and a pending invitation into a suspended organization —
 * arrive as ONE indistinguishable answer, which is why there is one sentence for them.
 *
 * ── NO SERVER ACTION, HERE OR ANYWHERE ───────────────────────────────────────────────────────────
 * An action bypasses Laravel's rate limiter, its quota accounting and its audit log. This POST is
 * `throttle:invitation`-limited and writes an audit row; a second mutation path around it would be a
 * second business backend by accident.
 */
export function RegisterForm({ token }: { readonly token: string }) {
  // The token is in the address bar for exactly one load. See the hook — an email can only carry a URL,
  // and `next.config.ts`'s `Referrer-Policy: same-origin` is what covers the window before this runs.
  useStripTokenFromUrl();

  const preview = useQuery(invitationPreviewQueryOptions(token));

  // THREE GENERICS, input then output. `password`/`token` are `z.string()` chains but the schema is a
  // `.superRefine`d strictObject, and one generic pins input and output together and then fails to
  // typecheck against zodResolver.
  const form = useForm<RegisterIn, unknown, RegisterOut>({
    resolver: zodResolver(registerSchema),
    // NEVER `reset(preview)`. `registerFormDefaults` takes a structural `{token}` pick and can reach
    // exactly one field: the preview response carries `organization_name`, `email`, `role` and
    // `expires_at`, and `reset()` keeps every key it is handed, `getValues()` returns them, and submit
    // posts them back — including the `email` this schema exists to not have.
    defaultValues: registerFormDefaults({ token }),
    mode: 'onTouched',
  });

  const cooldown = useCooldown();

  const register = useMutation<SessionResource, Error, RegisterOut>({
    // The RESOLVER'S OUTPUT, handed over by handleSubmit — parsed, trimmed and stripped. Never
    // `form.getValues()`, which is raw form state that has been through no schema at all.
    //
    // No `Idempotency-Key`: registration is not replayable, and the double-click guard is
    // `disabled={register.isPending}` below. A second POST with the same token is a 422 on `token`,
    // because the first one marked the invitation accepted.
    mutationFn: registerWithInvitation,
    onSuccess: () => {
      // `replace`, not `assign`: the history entry being overwritten is the one that arrived holding
      // `?token=…`. `useStripTokenFromUrl` already rewrote that entry's URL, so this is the second of
      // two layers — Back from the console lands on a token-less `/register` rather than putting a
      // consumed capability back in the address bar.
      //
      // A FULL DOCUMENT navigation and never `router.push`: the session cookie was just minted, the
      // Client Router Cache is keyed by path and holds pre-registration payloads, and `/` lives under a
      // different root layout from `(auth)`. Nothing invalidates or resets a cache here on purpose —
      // the navigation destroys the whole JS heap, QueryClient included, in one step.
      browserNavigation.replace(CONSOLE_ROUTE);
    },
    onError: (error) => {
      // The SHARED handler, not a local branch table. `token` is deliberately absent from
      // REGISTER_KNOWN_PATHS, so the server's byte-identical "This invitation is no longer valid."
      // lands in `root.serverError` where it is read, instead of on a hidden input that displays
      // nowhere. `email` is absent too — it is not a field on this form at all — so the one deliberate
      // disclosure ("An account already exists for this address. Sign in to accept the invitation.")
      // lands in the same banner, next to the sign-in link below.
      applyAuthError(form, REGISTER_KNOWN_PATHS, error, {
        onRateLimit: (seconds) => cooldown.start(seconds),
      });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;

  if (token.length === 0) {
    // No request was made: `invitationPreviewQueryOptions` is disabled on an empty token, so this
    // visitor did not spend the token-keyed limiter's budget learning what the URL already said.
    return <InvitationRefused>This invitation link is incomplete.</InvitationRefused>;
  }

  if (preview.isPending) {
    return (
      <div aria-busy="true" aria-live="polite" className="space-y-3">
        <span className="sr-only">Checking your invitation…</span>
        <Skeleton className="h-40" />
        <Skeleton className="h-10" />
      </div>
    );
  }

  if (preview.error !== null) {
    return (
      <InvitationRefused>
        {/* Branching on `error_class`, never on the status: the preview's deny is a 404 that
            bootstrap/app.php maps to `authorization`, and treating that class as "bad token" is safe on
            this endpoint only because it is a guest route with no policy. Everything else — a 429 from
            the token-keyed limiter, a 503, an unparsed envelope — is a genuine fault and must not be
            reported as a dead invitation. */}
        {isInvalidInvitationError(preview.error)
          ? INVALID_INVITATION_COPY
          : actionErrorCopy(preview.error)}
      </InvitationRefused>
    );
  }

  return (
    <div className="space-y-6">
      <InvitationPreviewSummary preview={preview.data} />

      <Form {...form}>
        <form
          // POST, never the browser's default GET — see login-form.tsx for the full account. This form
          // carries a password AND an invitation token, so the native fallback disclosed both.
          // Asserted for every form by tests/unit/form-method.test.ts.
          method="post"
          onSubmit={form.handleSubmit((values) => {
            register.mutate(values);
          })}
          // The browser's own validation bubbles would pre-empt the schema's messages and cannot be
          // styled or read consistently by a screen reader.
          noValidate
          className="space-y-4"
        >
          {/* shadcn's FormMessage renders `formState.errors[name]`, and `root.serverError` is not under
              a field name — so the banner is rendered explicitly. <Alert/> already carries
              role="alert". */}
          {rootError === undefined ? null : (
            <Alert variant="destructive">
              <AlertDescription>{rootError}</AlertDescription>
            </Alert>
          )}

          {/* REGISTERED so it submits, HIDDEN so there is nothing to type, and absent from
              REGISTER_KNOWN_PATHS so a 422 keyed on it reaches the banner above. `registerSchema`
              declares the path because the drift suite asserts set equality against the dumped
              manifest; "paths the FormRequest validates" and "paths this form renders" are two
              different sets, and this is the field that proves it. */}
          <input type="hidden" {...form.register('token')} />

          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Your name</FormLabel>
                <FormControl>
                  <Input {...field} value={asText(field.value)} autoComplete="name" />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="password"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Password</FormLabel>
                <FormControl>
                  <Input
                    {...field}
                    value={asText(field.value)}
                    type="password"
                    autoComplete="new-password"
                  />
                </FormControl>
                {/* Mirrors `newPasswordField()` constraint for constraint, which mirrors the server's
                    explicit string rules. Showing the policy before the attempt is the whole point: a
                    form that mirrors only `min:12` accepts "aaaaaaaaaaaa" and lets the server refuse a
                    rule the user was never told about. */}
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
                <FormLabel>Confirm password</FormLabel>
                <FormControl>
                  <Input
                    {...field}
                    value={asText(field.value)}
                    type="password"
                    autoComplete="new-password"
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          <Button type="submit" disabled={register.isPending || cooling} className="w-full">
            {register.isPending ? 'Creating your account…' : 'Create account'}
          </Button>

          {/* polite, not assertive: it updates once a second and must not interrupt whatever the user
              is doing. `retry_after` is seconds off the `Retry-After` RESPONSE HEADER, and a 429 that
              omits it degrades this to no cooldown at all rather than to a guessed one. */}
          <p aria-live="polite" className="text-muted-foreground text-sm">
            {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
          </p>
        </form>
      </Form>

      {/* The remedy for the one deliberate disclosure, stated permanently rather than conditionally on
          a server string. `?next=` cannot carry the way back: `safeNext` rejects every public `(auth)`
          path, `/invitations/accept` included, because a single-use credential relayed through a
          `Location` header is a token-laundering surface. Opening the mail again is the honest
          instruction. */}
      <p className="text-muted-foreground text-sm">
        Already have an account? <SignInLink />, then open the invitation link from your email again.
      </p>
    </div>
  );
}

/**
 * A PLAIN ANCHOR, not `next/link`, and argued rather than defaulted. Same shape of argument
 * `verify-email-notice.tsx` makes for its own anchor, with one reason specific to this screen. (No
 * `eslint-disable` is needed: measured, `@next/next/no-html-link-for-pages` does not fire for this href,
 * and adding a directive it does not use is itself a warning. If it ever starts firing, this paragraph is
 * the justification to put above the directive.)
 *
 *  1. THE HEAP THIS PAGE HOLDS CONTAINS A CAPABILITY. A soft navigation preserves the document: the
 *     `token` prop, the `['invitation-preview', token]` cache entry, and this form's state all survive
 *     into `/login`. A full document load discards every one of them. `useStripTokenFromUrl` has already
 *     taken it out of the address bar; this takes it out of memory.
 *  2. `<Link>` PREFETCHES, which EXECUTES the target route's layouts and pages on the Next server for a
 *     page the user may never open — from a screen whose whole purpose is that they may not need it.
 *  3. What the rule protects is the client-side navigation, and the thing it buys here (keeping the
 *     document alive) is exactly what reason 1 does not want.
 */
function SignInLink() {
  return (
    <a href="/login" className="underline">
      Sign in
    </a>
  );
}

/**
 * The refusal panel, shared by every state in which there is no form to render.
 *
 * `role="alert"` comes from `<Alert/>`. It replaces the form rather than sitting above it: a password
 * field on a page whose token is dead is a control that can only fail, and leaving it there invites the
 * guest to type a password into it twice before reading.
 */
function InvitationRefused({ children }: { readonly children: ReactNode }) {
  return (
    <div className="space-y-4">
      <Alert variant="destructive">
        <AlertTitle>This invitation cannot be used</AlertTitle>
        <AlertDescription>{children}</AlertDescription>
      </Alert>
      <p className="text-muted-foreground text-sm">
        Already have an account? <SignInLink />.
      </p>
    </div>
  );
}

