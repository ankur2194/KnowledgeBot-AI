'use client';

import type { SessionResource } from '@kb/contracts';
import { loginFormDefaults, loginSchema, type LoginIn, type LoginOut } from '@kb/contracts/forms';
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
import { browserFetchData, refreshCsrfToken } from '@/lib/api/browser';
import { asText } from '@/lib/forms/as-text';

import { applyAuthError } from './auth-error';
import { LOGIN_KNOWN_PATHS } from './known-paths';
import { browserNavigation, LOGIN_PATH } from './session';
import { useCooldown } from './use-cooldown';

/**
 * The sign-in form. A `'use client'` component that talks to Laravel directly and receives only
 * PRIMITIVES from its server page — `next` is a sanitized string, never a resource object, because
 * anything a server component passes down is serialized into the RSC payload embedded in the HTML,
 * whole, including the fields the UI never reads.
 *
 * ── STEP 2a IS LOAD-BEARING: `refreshCsrfToken()` UNCONDITIONALLY, NOT `sessionCredential()` ──────
 * Login is the ONE mutation guaranteed to run on a document that may never have held an `XSRF-TOKEN`
 * cookie, so the lazy credential would find nothing and fall through to the same call anyway. What
 * the unconditional refresh buys is DIAGNOSABILITY: it throws
 * `KbError(null, false, null, null, 'XSRF-TOKEN cookie absent after GET /sanctum/csrf-cookie — check
 * SESSION_DOMAIN and sanctum.stateful')` (src/lib/api/browser.ts:138-149) BEFORE the POST, where the
 * form can still render it and the user's typed input still exists. Without it, the realistic
 * misconfiguration — a blocked third-party cookie, a `SANCTUM_STATEFUL_DOMAINS` entry missing its
 * PORT, a `SESSION_DOMAIN` mismatch — arrives as an opaque 419 AFTER the POST, and `browserFetch`'s
 * 419 handling then reloads `/login`, erasing the email and password with no explanation. That is why
 * the pre-check makes the 419-on-login branch near-unreachable rather than merely tidier.
 *
 * ── `onSuccess` DOES `browserNavigation.assign(next)` AND NOTHING ELSE ───────────────────────────
 * NO `invalidateQueries`, NO `useResetQueryClient()`, and this is not an omission — DO NOT "ADD THE
 * MISSING INVALIDATE". A full document navigation destroys the entire JS heap in one step: the
 * QueryClient, every observer, and the Client Router Cache. Adding a second mechanism for a cache
 * that is about to cease existing is noise that reads as a rule, and the next person to see the rule
 * copies it somewhere it matters.
 *
 * `assign`, not `replace`, so Back returns to `/login` rather than skipping past it. And
 * `router.push` is wrong THREE times over: the Router Cache is keyed by path and would replay
 * pre-login RSC payloads, the session cookie was just rotated so every cached payload was captured
 * under a different identity, and `/login` lives under a different root layout from `(admin)`.
 *
 * There is no Server Action here and there never will be: an action bypasses Laravel's rate limiter,
 * its quota accounting and its audit log, and login is the single most rate-limited endpoint in the
 * product.
 */
export function LoginForm({ next }: { readonly next: string }) {
  // THREE GENERICS, input then output. A field carrying `.default()` or a `preprocess` is optional or
  // differently typed on `z.input` and settled on `z.output`, so one generic pins both and fails to
  // typecheck against zodResolver.
  const form = useForm<LoginIn, unknown, LoginOut>({
    resolver: zodResolver(loginSchema),
    // Never `reset(resource)` and never a seeded default: there is no server resource a login form
    // may read, which is why `loginFormDefaults` takes no argument at all.
    defaultValues: loginFormDefaults(),
    mode: 'onTouched',
  });

  const cooldown = useCooldown();

  const login = useMutation<SessionResource, Error, LoginOut>({
    mutationFn: async (values) => {
      const credential = { kind: 'session', xsrf_token: await refreshCsrfToken() } as const;
      // 200 `{data: SessionResource}`. The envelope is unwrapped ONCE, at the fetch boundary, by
      // `browserFetchData` — never by reaching into `.data` here.
      //
      // No `Idempotency-Key`: login is not replayable and must never be retried by anything,
      // including a double-click, which is what `disabled={login.isPending}` below is for.
      return browserFetchData<SessionResource>({
        path: LOGIN_PATH,
        method: 'POST',
        // The RESOLVER'S OUTPUT, handed to us by handleSubmit — parsed, trimmed and stripped. Never
        // `form.getValues()`, which is raw form state that has been through no schema at all.
        body: values,
        credential,
      });
    },
    onSuccess: () => {
      browserNavigation.assign(next);
    },
    onError: (error) => {
      showServerError(error);
    },
  });

  /**
   * Delegates to the shared handler in auth-error.ts, which the other five auth forms also use. The
   * branch table lives there rather than here so one rule cannot drift across six copies; the reasoning
   * for each branch — why `validation` returns early and `rate_limit` falls through — is in that file.
   */
  function showServerError(error: Error): void {
    applyAuthError(form, LOGIN_KNOWN_PATHS, error, {
      onRateLimit: (seconds) => cooldown.start(seconds),
    });
  }

  const rootError = form.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET. `handleSubmit` calls preventDefault, so this
        // attribute is unreachable while the page is interactive — it governs what happens when it is
        // NOT. Anything that leaves the native submit as the only handler (a click before hydration
        // finishes, a chunk that 404s, a throwing module — and a dev-server HMR failure that did
        // exactly this) sends every field as a QUERY STRING: address bar, browser history, the
        // server's request log, Traefik's access log. That is a measured incident, not a hypothesis.
        //
        // What POST does instead, MEASURED rather than assumed — a page route has no POST handler, so
        // it is NOT a 405: the browser's fallback re-renders this page with 200, an empty form, and
        // the submitted values echoed nowhere. The fields travel in the request BODY, so the log line
        // is `POST /login 200` and nothing lands in the URL bar, history, or either access log. The
        // cost is that the fallback is a silent no-op rather than an error, which is the right trade
        // for a path that should be unreachable. Asserted by tests/unit/form-method.test.ts.
        method="post"
        onSubmit={form.handleSubmit((values) => {
          login.mutate(values);
        })}
        // The browser's own validation bubbles would pre-empt the schema's messages and cannot be
        // styled or read by a screen reader consistently.
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

        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email</FormLabel>
              <FormControl>
                {/* `value` is narrowed because `emailField` is a `z.preprocess`, which makes the
                    INPUT type `unknown` while the output is a string. An uncontrolled fallback of ''
                    is what keeps this a controlled input across a reset. */}
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
                  autoComplete="current-password"
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={login.isPending || cooling} className="w-full">
          {login.isPending ? 'Signing in…' : 'Sign in'}
        </Button>

        {/* polite, not assertive: it updates once a second and must not interrupt whatever the user
            is doing. */}
        <p aria-live="polite" className="text-muted-foreground text-sm">
          {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
        </p>
      </form>
    </Form>
  );
}

