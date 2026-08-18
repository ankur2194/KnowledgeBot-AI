import { KbError } from '@kb/contracts';
import { AlertTriangleIcon, LockIcon, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { endUserCopy } from '@/lib/forms/apply-server-errors';
import { cn } from '@/lib/utils';

/**
 * THE FOUR STATES, and they ship WITH the success state rather than after it
 * (kb-ui-patterns → references/states.md).
 *
 * Building the success state first and "adding the others later" reliably produces a screen where
 * three of the four are a spinner, a blank box and a red string — which is to say three of the four
 * are unfinished. There are really six, because two of them split:
 *
 *   Loading    first load (skeleton)  ·  refetch (never blank what is still correct)
 *   Empty      FIRST-RUN              ·  FILTERED          <- the split everybody skips
 *   Error      retryable              ·  terminal
 *   Forbidden  —
 *
 * `EmptyState` and `FilteredEmptyState` are two components on purpose. Offering "Upload your first
 * document" to someone whose search matched nothing is the exact bug the split exists to prevent,
 * and one component with a boolean prop gets called with the wrong value.
 */

function StateShell({
  glyph: Glyph,
  title,
  body,
  action,
  className,
}: {
  readonly glyph?: LucideIcon;
  readonly title: string;
  readonly body: ReactNode;
  readonly action?: ReactNode;
  readonly className?: string;
}) {
  return (
    <div className={cn('flex flex-col items-center gap-3 px-gutter-sm py-12 text-center', className)}>
      {Glyph ? (
        // A 24px glyph in a --tone-slate-surface circle. Tone is DECORATIVE here and carries no
        // meaning — it is a wash behind an icon, not a signal.
        <span
          aria-hidden
          className="flex size-12 items-center justify-center rounded-full bg-tone-slate-surface text-tone-slate-foreground"
        >
          <Glyph className="size-6" strokeWidth={1.5} />
        </span>
      ) : null}
      <h3 className="text-h3">{title}</h3>
      <p className="max-w-prose text-base text-muted-foreground">{body}</p>
      {action ? <div className="pt-1">{action}</div> : null}
    </div>
  );
}

/**
 * FIRST-RUN EMPTY — the collection has never had anything in it. An onboarding moment, so it gets
 * the icon and the PRIMARY action.
 */
export function EmptyState({
  glyph,
  title,
  body,
  action,
}: {
  readonly glyph: LucideIcon;
  readonly title: string;
  readonly body: ReactNode;
  readonly action?: ReactNode;
}) {
  return <StateShell glyph={glyph} title={title} body={body} action={action} />;
}

/**
 * FILTERED EMPTY — things exist; the current filter matched none of them. A correction moment, so
 * there is no illustration and the action is "Clear filters", NEVER the create action.
 *
 * `describeFilter` restates what was asked for, because a user who cannot see their own query
 * cannot tell a too-narrow filter from a broken screen — and neither can support, when the customer
 * reports it.
 */
export function FilteredEmptyState({
  describeFilter,
  onClear,
}: {
  readonly describeFilter: ReactNode;
  readonly onClear?: () => void;
}) {
  return (
    <StateShell
      title="No matches"
      body={describeFilter}
      action={
        onClear ? (
          <Button variant="outline" onClick={onClear}>
            Clear filters
          </Button>
        ) : null
      }
    />
  );
}

/**
 * ERROR. The sentence comes from `error_class`; the envelope's `message` reaches no rendered string,
 * because it is operator-facing and can carry an internal hostname or raw upstream provider text.
 *
 * `error_class: null` means no envelope parsed, which means UNKNOWN, and unknown is permanently
 * non-retryable — so there is no "Try again" on it. Offering a retry that cannot help is worse than
 * offering nothing.
 */
export function ErrorState({
  error,
  title,
  onRetry,
  className,
}: {
  readonly error: unknown;
  /**
   * What failed, in the user's terms — "Members could not be loaded". Worth passing whenever the
   * failure is scoped to one surface on a page that otherwise works: the class-mapped sentence says
   * WHY, and without a title nothing says WHAT, which is the half a reader needs to know whether the
   * rest of the screen is still trustworthy.
   */
  readonly title?: string;
  readonly onRetry?: () => void;
  readonly className?: string;
}) {
  const kb = error instanceof KbError ? error : null;
  // `request_id: null` on purpose: `endUserCopy` appends "(ref …)" inline, and <RequestId> below
  // renders the same string in --font-mono with a select-all affordance. Printing it twice is worse
  // than either — states.md asks for the monospaced, copyable one.
  const copy = endUserCopy({ error_class: kb?.error_class ?? null, request_id: null });
  // Gated on the envelope's `retryable`, not on whether the copy sounds temporary.
  const retryable = kb?.retryable === true;

  return (
    <div
      role="alert"
      className={cn(
        'flex flex-col items-start gap-3 rounded-lg border-l-[3px] border-l-destructive bg-destructive-soft p-card-pad-sm text-destructive-soft-foreground',
        className,
      )}
    >
      <div className="flex items-start gap-2">
        <AlertTriangleIcon aria-hidden className="mt-0.5 size-4 shrink-0" />
        <div className="flex flex-col gap-0.5">
          {title ? <p className="text-base font-medium">{title}</p> : null}
          <p className="text-base">{copy}</p>
        </div>
      </div>
      {kb?.request_id ? <RequestId value={kb.request_id} /> : null}
      {retryable && onRetry ? (
        <Button variant="outline" size="sm" onClick={onRetry}>
          Try again
        </Button>
      ) : null}
    </div>
  );
}

/**
 * The one string that makes a support conversation short. `--font-mono`, `--text-caption`, and
 * selectable — it is a handle, not a concept, which is why it is the deliberate exception to
 * "never surface internal vocabulary".
 */
export function RequestId({ value }: { readonly value: string }) {
  return (
    <p className="text-caption">
      <span className="text-muted-foreground">Reference </span>
      <code className="font-mono select-all">{value}</code>
    </p>
  );
}

/**
 * FORBIDDEN, on an ADMIN surface: the user is authenticated, in the right organization, and lacks
 * the role. Naming the role and who can grant it turns a dead end into an action.
 *
 * There is deliberately no public counterpart. A public surface answers 404 and reveals NOTHING —
 * rendering a 404 with a permissions explanation undoes the deny split that
 * `laravel-rbac-policies` exists to maintain, because it confirms the resource exists.
 */
export function ForbiddenState({
  requiredRole,
  organizationName,
}: {
  readonly requiredRole: string;
  readonly organizationName?: string;
}) {
  return (
    <StateShell
      glyph={LockIcon}
      title="You don't have access to this"
      body={
        <>
          You need the {requiredRole} role to manage this.{' '}
          {organizationName ? `Ask an owner of ${organizationName} to change your role.` : 'Ask an owner to change your role.'}
        </>
      }
    />
  );
}

/**
 * FIRST-LOAD SKELETON. It mirrors the loaded layout box for box, or the page reflows when the data
 * arrives and it reads as a rendering bug.
 *
 * The line widths VARY (100% / 80% / 60%) because three identical bars read as a broken table.
 * The container carries `aria-busy`; the blocks are `aria-hidden` (see <Skeleton>).
 *
 * REFETCH IS NOT A SKELETON. Data already on screen and still correct stays on screen — show a
 * subtle progress affordance instead and never blank out content the user is reading.
 */
export function SkeletonLines({ lines = 3, className }: { readonly lines?: number; readonly className?: string }) {
  const widths = ['w-full', 'w-4/5', 'w-3/5'];

  return (
    <div aria-busy="true" className={cn('flex flex-col gap-2', className)}>
      {Array.from({ length: lines }, (_, i) => (
        <Skeleton key={i} className={cn('h-4', widths[i % widths.length])} />
      ))}
    </div>
  );
}

/**
 * DEGRADED — something worked, but not fully. An answer produced without reranking because the
 * provider could not rerank; a source indexed with OCR coverage below the floor.
 *
 * Said quietly and IN PLACE, never as a modal. Silence here is the documented failure mode: a real
 * degradation reaches the index and nothing warns (docs/23).
 */
export function DegradedNote({ children, className }: { readonly children: ReactNode; readonly className?: string }) {
  return (
    <p
      className={cn(
        'flex items-start gap-2 rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground',
        className,
      )}
    >
      <AlertTriangleIcon aria-hidden className="mt-0.5 size-3.5 shrink-0" />
      {children}
    </p>
  );
}
