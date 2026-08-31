'use client';

import { KbError, type QuotaMetricUsage, type QuotaResource, type Role } from '@kb/contracts';
import {
  QUOTA_METRICS,
  quotaFieldFor,
  quotaLimitsFormDefaults,
  quotaLimitsSchema,
  type QuotaLimitsIn,
  type QuotaLimitsOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useId, useMemo } from 'react';
import { useForm, useWatch } from 'react-hook-form';

import { ErrorState, ForbiddenState, SkeletonLines } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Form, FormField, FormItem, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Switch } from '@/components/ui/switch';
import { StatusPill } from '@/components/status-pill';
import { applyAuthError } from '@/features/auth/auth-error';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';

import {
  QUOTA_KNOWN_PATHS,
  QUOTA_MANAGE_ROLE,
  canManageQuotas,
  fetchQuotas,
  quotaFill,
  quotaMetricDisplay,
  quotaRaises,
  updateQuotaLimits,
} from './api';

/**
 * `/quotas` — usage against ceilings, and the one form on this console whose refusal rule the server
 * structurally cannot explain.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ─────────────────────────
 * `/quotas` is ONE URL for every organization an administrator belongs to. Not one byte of these
 * figures is produced by the Next server; they arrive from a browser fetch keyed
 * `['org', orgId, 'quotas']`, and an organization switch REPLACES the QueryClient outright.
 *
 * ── FOUR STATES, SHIPPED WITH THE SUCCESS STATE ─────────────────────────────────────────────────
 * Loading (a skeleton at the loaded shape), error, FORBIDDEN — which this screen genuinely needs,
 * because `analytics.view` is withheld from the knowledge manager and the sidebar offers `/quotas`
 * to everybody — and EMPTY, which does not exist here and inventing one would be wrong: the metrics
 * list is TOTAL, so a response always has four rows and "no quotas" is not a state the server can
 * produce.
 *
 * ── THE ASYMMETRY THIS SCREEN EXISTS TO SURFACE ─────────────────────────────────────────────────
 * `QuotaLimitService::apply()` refuses a RAISE from anyone without `users.is_platform_owner`, and
 * LOWERING IS FREE. The service has a sentence written for that moment and IT NEVER REACHES A
 * BROWSER: the refusal is `error_class: 'authorization'`, and `bootstrap/app.php` rewrites every
 * authorization message to the constant `'This action is not permitted.'` so the deny split cannot
 * become an enumeration oracle. So a save that raised would fail with a bare class-mapped sentence
 * and no clue what to do — which is precisely the "letting a save fail with a bare 403" this screen
 * is written not to do.
 *
 * The disclosure is therefore CLIENT-SIDE and BEFORE the submit, from the two things the client has:
 * `SessionUser.is_platform_owner`, and the direction of the edit against the stored row. It is a
 * DISCLOSURE AND NOT A CONTROL — the server decides, under a lock, against values this client may
 * already be stale about — so it explains and disables, and the 403 path stays fully handled.
 */
export function QuotasScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return <SkeletonLines lines={4} />;
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable` are both `<SessionProvider>`'s: it bounces to `/login` for one
    // and renders the class-mapped panel for the other.
    return null;
  }

  if (orgId === null) {
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Quotas belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <QuotasForOrganization
      orgId={orgId}
      viewerRole={membership?.role ?? null}
      organizationName={membership?.name}
      // THE PLATFORM FLAG, OFF THE SESSION THE SERVER ITSELF HANDED US. It is not a role and is not
      // per-organization: `users.is_platform_owner` is a column on the user, set by
      // `kb:bootstrap-organization --platform-owner`, and it is the ONE input to the raise rule that
      // no membership can supply.
      isPlatformOwner={session.user.is_platform_owner}
    />
  );
}

function QuotasForOrganization({
  orgId,
  viewerRole,
  organizationName,
  isPlatformOwner,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
  readonly organizationName?: string;
  readonly isPlatformOwner: boolean;
}) {
  const orgKeyFor = useOrgKey();
  const quotasKey = useMemo(() => orgKeyFor('quotas'), [orgKeyFor]);

  const quotas = useQuery({
    queryKey: quotasKey,
    queryFn: ({ signal }) => fetchQuotas(orgId, signal),
    // NO POLLING. Usage moves when somebody uploads or a bot answers, not on a timer this screen can
    // usefully match, and twenty admin tabs polling it is pure traffic against `throttle:admin`. The
    // 30 s `staleTime` from `lib/query/client.ts` is what this wants.
  });

  const forbidden =
    quotas.error instanceof KbError && quotas.error.error_class === 'authorization';

  if (forbidden && quotas.data === undefined && viewerRole === 'knowledge_manager') {
    // NAMED ONLY FOR THE VIEWER WHO IS ACTUALLY MISSING THE ROLE. `analytics.view` — which gates the
    // READ here — is held by owner, admin and analyst; the knowledge manager is the one role for
    // which an `authorization` failure on this page really is a role gap, and the sidebar offers
    // `/quotas` to everybody. Every other viewer's 403 has three indistinguishable causes on the
    // wire, so they get the class-mapped sentence, which is correct for all three.
    return <ForbiddenState requiredRole="analyst" organizationName={organizationName} />;
  }

  if (quotas.data === undefined) {
    return quotas.error === null ? (
      <SkeletonLines lines={4} />
    ) : (
      <ErrorState
        error={quotas.error}
        title="Quotas could not be loaded"
        onRetry={() => void quotas.refetch()}
      />
    );
  }

  return (
    <div className="flex flex-col gap-8">
      {quotas.error === null ? null : (
        <ErrorState
          title="These figures could not be refreshed"
          error={quotas.error}
          onRetry={() => void quotas.refetch()}
        />
      )}

      <UsageCards quotas={quotas.data} />

      <QuotaLimitsForm
        orgId={orgId}
        quotas={quotas.data}
        quotasKey={quotasKey}
        canManage={canManageQuotas(viewerRole)}
        isPlatformOwner={isPlatformOwner}
        organizationName={organizationName}
      />
    </div>
  );
}

/** Usage against ceiling, one card per metric. READ-ONLY, and it renders for every role that can
 *  read the page — including the analyst, who may see the numbers and change nothing. */
function UsageCards({ quotas }: { readonly quotas: QuotaResource }) {
  return (
    <section aria-labelledby="quota-usage" className="flex flex-col gap-4">
      <h2 id="quota-usage" className="text-h2">
        Usage
      </h2>
      <div className="grid gap-4 sm:grid-cols-2 xl:gap-5">
        {quotas.metrics.map((row) => (
          <UsageCard key={row.metric} row={row} />
        ))}
      </div>
    </section>
  );
}

function UsageCard({ row }: { readonly row: QuotaMetricUsage }) {
  const display = quotaMetricDisplay(row.metric);
  const fill = quotaFill(row);
  const labelId = useId();

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">
          <span className="flex flex-wrap items-center gap-2" id={labelId}>
            {display.label}
            {/* COLOUR IS NEVER THE ONLY CHANNEL: `<StatusPill>` supplies a glyph and the word from a
                closed vocabulary, so an over-quota row survives greyscale and CVD. */}
            {row.exceeded ? <StatusPill status="failed" label="Over limit" /> : null}
          </span>
        </CardTitle>
        <CardDescription>{display.description}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <p className="text-metric tabular-nums">{display.format(row.used)}</p>

        {fill === null ? (
          // UNLIMITED GETS NO METER, and that is not a missing feature. A bar at zero over an
          // unlimited metric says "you have used none of your allowance", which is false in the way
          // that matters — there is no allowance, so there is nothing to be a fraction of.
          <p className="text-base text-muted-foreground">No limit set.</p>
        ) : (
          <>
            <Progress
              value={fill}
              /**
               * `aria-label` AND NOT `aria-labelledby={cardTitleId}`, AND THE TEST THAT FOUND IT IS
               * THE REASON.
               *
               * Pointing it at the card's heading named this bar "Bots" — the same accessible name
               * the ceiling INPUT in the form below already had, so one page carried two controls
               * called "Bots" and a strict locator resolved both. That is a real defect and not a
               * test artefact: a screen-reader user tabbing the form hears the same name twice and
               * cannot tell the meter from the field, and it is exactly the collision
               * `TableSearchField`'s docblock warns about for a generically-named search box.
               *
               * "Bots used" and "Bots limit" say which is which, which is also the more accurate
               * pair of names.
               */
              aria-label={`${display.label} used`}
              // The bar clamps at 100 so an over-quota row does not overflow its track, and the
              // NUMBERS below do not clamp — the exact figure is what an operator acts on.
              aria-valuetext={`${display.format(row.used)} of ${display.format(row.limit ?? 0)}`}
            />
            <p className="text-sm text-muted-foreground tabular-nums">
              {display.format(row.used)} of {display.format(row.limit ?? 0)}
              {row.remaining === null ? '' : ` · ${display.format(row.remaining)} left`}
            </p>
          </>
        )}

        {row.source === 'database' ? null : (
          // A `cache` value means the PRIMARY READ WAS SKIPPED and this number is a LOWER BOUND. It
          // is surfaced rather than rendered as fact, because an operator deciding whether to delete
          // a bot needs to know the figure may be behind.
          <p className="text-caption text-warning-soft-foreground">
            Read from a cache, so this may be behind the real figure.
          </p>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * THE CEILINGS FORM — four fields, a PUT, and one pre-submit disclosure.
 *
 * ── `canManage === false` MEANS RENDER NO FORM AT ALL ───────────────────────────────────────────
 * The same rule the bot editor's panels follow: a disabled input holding a value is a control an
 * operator will keep clicking. The ceilings are already rendered above, read-only, in the usage
 * cards — so the read-only case has a real screen rather than a greyed-out copy of the write one.
 */
function QuotaLimitsForm({
  orgId,
  quotas,
  quotasKey,
  canManage,
  isPlatformOwner,
  organizationName,
}: {
  readonly orgId: string;
  readonly quotas: QuotaResource;
  readonly quotasKey: readonly unknown[];
  readonly canManage: boolean;
  readonly isPlatformOwner: boolean;
  readonly organizationName?: string;
}) {
  const queryClient = useQueryClient();

  // THREE GENERICS, INPUT THEN OUTPUT. A field with a preprocess is a different type on `z.input`
  // and `z.output`, so one generic pins both and fails to typecheck against `zodResolver`.
  const form = useForm<QuotaLimitsIn, unknown, QuotaLimitsOut>({
    resolver: zodResolver(quotaLimitsSchema),
    // THE ONLY PATH FROM SERVER DATA INTO FORM STATE. Never `reset(quotas)` — that object is
    // `{metrics: [...]}`, six fields per row, and RHF keeps every key it is handed.
    defaultValues: quotaLimitsFormDefaults(quotas),
    mode: 'onTouched',
  });

  /**
   * `useWatch` AND NOT `form.watch()`, and the repo already had the reason written down in
   * `bot-identity-panel.tsx`: the two differ in WHERE the subscription lives. `watch()` re-renders
   * the component that owns the form on every keystroke in every field — the cost React Hook Form's
   * uncontrolled design exists to avoid — while `useWatch` subscribes the component that calls it.
   *
   * It is also what the React Compiler lint asks for by name: `watch()` returns a function the
   * compiler cannot memoize safely, so it SKIPS memoizing the whole component, which is a warning
   * about stale UI on a screen whose disclosure has to be live.
   */
  const values = useWatch({ control: form.control });

  /**
   * Which metrics THIS EDIT would raise, recomputed on every change.
   *
   * It runs against the RAW form values rather than the parsed output, because the disclosure has to
   * appear while the user is typing rather than after a successful parse — and a half-typed number
   * is still a direction. `quotaRaises` takes the parsed shape, so the values are coerced here the
   * same way the schema does and an unparseable one is treated as "not a raise", which is the
   * conservative direction: it never hides a warning that belongs, it only delays one.
   */
  const raises = useMemo(() => {
    const proposed = Object.fromEntries(
      QUOTA_METRICS.map((metric) => {
        const raw = values[quotaFieldFor(metric)];
        if (raw === null || raw === undefined || raw === '') return [quotaFieldFor(metric), null];
        const parsed = Number(raw);
        return [quotaFieldFor(metric), Number.isFinite(parsed) ? parsed : null];
      }),
    ) as QuotaLimitsOut;
    return quotaRaises(quotas, proposed);
  }, [quotas, values]);

  const blockedByPlatformRule = raises.length > 0 && !isPlatformOwner;

  const save = useMutation<QuotaResource, Error, QuotaLimitsOut>({
    mutationFn: (limits) => updateQuotaLimits(orgId, limits),
    onSuccess: (written) => {
      // THE SERVER'S OWN 200 BODY, which carries usage RECOMPUTED after the write — a fact this
      // client cannot derive. Writing it is not optimism; it is the server's answer.
      queryClient.setQueryData(quotasKey, written);
      form.reset(quotaLimitsFormDefaults(written));
    },
    onError: (error) => {
      // Branches on `error_class`, never on an HTTP status. A `validation` 422 keys to the four
      // fields by name; a 409 for a suspended organization is `internal_dependency` + `actionable`,
      // whose `message` IS end-user copy and is the one server string this app renders verbatim; and
      // the two 403s — the missing permission, and the raise refusal — fall through to the
      // class-mapped sentence, which is why the disclosure above the button exists at all.
      const conflict = actionableConflictMessage(error);
      if (conflict !== null) {
        form.setError('root.serverError', { type: 'conflict', message: conflict });
        return;
      }
      applyAuthError(form, QUOTA_KNOWN_PATHS, error);
    },
    onSettled: () => {
      // A 422 means the row in front of the operator may ALSO be stale, because the rule that
      // refused them was evaluated against a stored value they cannot see.
      void queryClient.invalidateQueries({ queryKey: quotasKey });
    },
  });

  if (!canManage) {
    return (
      <Alert variant="info">
        <AlertTitle>You can read these limits but not change them</AlertTitle>
        <AlertDescription>
          Changing a ceiling needs the {QUOTA_MANAGE_ROLE} role in{' '}
          {organizationName ?? 'this organization'} — it is the one permission an administrator does
          not hold, because a quota is a billing decision rather than an operational one.
        </AlertDescription>
      </Alert>
    );
  }

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <section aria-labelledby="quota-limits" className="flex flex-col gap-4">
      <h2 id="quota-limits" className="text-h2">
        Limits
      </h2>

      {/*
        THE ASYMMETRY, SAID ONCE AND ALWAYS — not only when it bites. An owner who does not know
        lowering is free will not try it; an owner who discovers the rule from a failed save learns
        it as "quotas are broken".
      */}
      <Alert variant="info">
        <AlertTitle>Lowering a limit takes effect here. Raising one does not.</AlertTitle>
        <AlertDescription>
          A ceiling can be reduced from this page at any time. Raising one — or removing it
          altogether, which is the largest raise there is — is a plan change and needs a platform
          operator.
        </AlertDescription>
      </Alert>

      <Card>
        <CardContent className="pt-6">
          <Form {...form}>
            <form
              method="post"
              noValidate
              onSubmit={form.handleSubmit((limits) => {
                save.mutate(limits);
              })}
              className="flex flex-col gap-6"
            >
              {rootError === undefined ? null : (
                <Alert variant="destructive" role="alert">
                  <AlertTitle>This could not be saved</AlertTitle>
                  <AlertDescription>{rootError}</AlertDescription>
                </Alert>
              )}

              {QUOTA_METRICS.map((metric) => (
                <CeilingField
                  key={metric}
                  metric={metric}
                  form={form}
                  raised={raises.includes(metric)}
                />
              ))}

              {blockedByPlatformRule ? (
                <Alert variant="warning" role="status">
                  <AlertTitle>
                    {raises.length === 1
                      ? 'One of these is a raise'
                      : `${raises.length} of these are raises`}
                  </AlertTitle>
                  <AlertDescription>
                    {raises.map((metric) => quotaMetricDisplay(metric).label).join(', ')} would go up
                    or lose its limit, which needs a platform operator. Lower them instead, or ask
                    support to change the plan.
                  </AlertDescription>
                </Alert>
              ) : null}

              <div className="flex items-center gap-3">
                {/*
                  DISABLED ON `isPending` because a PUT carries no `Idempotency-Key`, so nothing —
                  including a double-click — may replay it.

                  ALSO DISABLED WHEN THE EDIT IS A RAISE THIS ACTOR CANNOT MAKE. That is a
                  disclosure and not a control: the server decides under a lock against values this
                  client may be stale about, and the 403 path stays fully handled above. What it buys
                  is that the button is never a trap.
                */}
                <Button type="submit" disabled={save.isPending || blockedByPlatformRule}>
                  {save.isPending ? 'Saving…' : 'Save limits'}
                </Button>
                {form.formState.isDirty ? (
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => form.reset(quotaLimitsFormDefaults(quotas))}
                  >
                    Discard changes
                  </Button>
                ) : null}
              </div>
            </form>
          </Form>
        </CardContent>
      </Card>
    </section>
  );
}

/**
 * One ceiling: a number, or Unlimited.
 *
 * ── `null` AND `0` ARE OPPOSITE STATES AND BOTH LOOK LIKE AN EMPTY BOX ──────────────────────────
 * `null` is UNLIMITED and `0` is NOTHING IS ALLOWED. In a bare `<input type="number">` both render
 * as an empty field, which is exactly how they get conflated — and the consequence of getting it
 * backwards is either an organization that can do nothing or one with no ceiling at all.
 *
 * So Unlimited is its own CONTROL rather than an emptied input: a switch that writes `null`, with
 * the number field hidden while it is on. Clearing the number field also yields `null` (the schema's
 * preprocess maps `''` to it, matching Laravel's `ConvertEmptyStringsToNull`), so the two agree — but
 * the switch is what makes it a CHOICE rather than something a user discovers by deleting digits.
 */
function CeilingField({
  metric,
  form,
  raised,
}: {
  readonly metric: (typeof QUOTA_METRICS)[number];
  readonly form: ReturnType<typeof useForm<QuotaLimitsIn, unknown, QuotaLimitsOut>>;
  readonly raised: boolean;
}) {
  const display = quotaMetricDisplay(metric);
  const path = quotaFieldFor(metric);
  const inputId = useId();
  const switchId = useId();
  /**
   * The switch's own visible label is the word "Unlimited", and four of those on one page is four
   * identical accessible names — the same collision the meter above had.
   *
   * `aria-labelledby` COMPOSES two ids rather than replacing the label with an `aria-label`: the
   * visible `<label>` stays bound (which `kb-ui-accessibility` requires and which an `aria-label`
   * would silently override), and the announced name becomes "Bots limit Unlimited" — unique, and
   * still the words on screen.
   */
  const fieldLabelId = useId();
  const switchLabelId = useId();

  return (
    <FormField
      control={form.control}
      name={path}
      render={({ field }) => {
        /**
         * `field.value` IS `unknown`, AND THAT IS THE SCHEMA BEING HONEST.
         *
         * `z.preprocess` accepts anything on the input side — which is the whole point, since a
         * number input hands back a STRING and a cleared one hands back `''` — so `z.input` of this
         * field is `unknown` and React would refuse it as a `value`. Narrowing here rather than
         * casting keeps the one case that matters visible: `null` is UNLIMITED and takes the switch
         * branch, and everything else is text on its way to `z.coerce.number()`.
         */
        const raw: unknown = field.value;
        const unlimited = raw === null || raw === undefined;
        const text = typeof raw === 'number' || typeof raw === 'string' ? raw : '';

        return (
          <FormItem className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              {/* A VISIBLE LABEL BOUND TO THE CONTROL. A placeholder is not a label: it disappears
                  exactly when the user needs it.
                  "Bots limit" and not "Bots": the usage meter above is "Bots used", and two controls
                  on one page called "Bots" is a name a screen-reader user cannot disambiguate. */}
              <Label id={fieldLabelId} htmlFor={unlimited ? switchId : inputId}>
                {display.label} limit
              </Label>
              <span className="flex items-center gap-2">
                <Label id={switchLabelId} htmlFor={switchId} className="text-sm text-muted-foreground">
                  Unlimited
                </Label>
                <Switch
                  id={switchId}
                  aria-labelledby={`${fieldLabelId} ${switchLabelId}`}
                  checked={unlimited}
                  onCheckedChange={(next) => {
                    // Turning it OFF seeds `0` rather than an empty box, because an empty box is the
                    // state we just left and the user would have to type a digit to see any
                    // difference. `0` is a real, legal ceiling — nothing is allowed — and it is the
                    // conservative direction: it is never a raise.
                    field.onChange(next ? null : 0);
                  }}
                />
              </span>
            </div>

            {unlimited ? null : (
              <Input
                id={inputId}
                type="number"
                inputMode="numeric"
                min={0}
                // `''` and never `0` as the fallback: a null here is the unlimited branch above, so
                // this is only reached with a number or a string — the fallback exists so React
                // never sees `value={null}` during the one render between a switch flip and the
                // field update, and a `0` fallback would put a digit in a box the user emptied.
                value={text}
                onChange={(event) => field.onChange(event.target.value)}
                onBlur={field.onBlur}
                name={field.name}
                ref={field.ref}
                aria-describedby={`${inputId}-unit`}
              />
            )}

            <p id={`${inputId}-unit`} className="text-caption text-muted-foreground">
              {unlimited ? 'No ceiling.' : `Measured in ${display.inputUnit}.`}{' '}
              {raised ? (
                <span className="text-warning-soft-foreground">
                  This is a raise, so it needs a platform operator.
                </span>
              ) : null}
            </p>

            {/* The per-field 422 lands here by name, with `aria-invalid` and `aria-describedby`
                wired by the shadcn `FormMessage` primitive. */}
            <FormMessage />
          </FormItem>
        );
      }}
    />
  );
}
