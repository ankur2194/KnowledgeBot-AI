'use client';

import { KbError } from '@kb/contracts';
import { AlertTriangleIcon } from 'lucide-react';
import { useEffect } from 'react';

import { RequestId } from '@/components/states';
import { Button } from '@/components/ui/button';
import { endUserCopy } from '@/lib/forms/apply-server-errors';

/**
 * The body of every error boundary in this app, in ONE place.
 *
 * There are three root layouts and each route group needs its own `error.tsx` — a shared boundary
 * would have to pick a layout, and picking either means the other surface renders the wrong <html>.
 * But the LOGIC is identical, and a second near-identical boundary is exactly where the two rules
 * below drift apart. So each `error.tsx` is a thin wrapper and this file holds the behaviour.
 *
 * It branches on `error_class`, NEVER on an HTTP status: one class renders two statuses by surface
 * (`authorization` is 403 on admin and 404 on public), a 422 can be a validation failure or an
 * idempotency conflict, and status-driven logic retries a `tenant_quota` 403 forever while reading an
 * `authorization` 404 as "absent, so create it".
 *
 * It NEVER renders the envelope's `message`. That field is operator-facing — it can carry an
 * internal hostname, raw text from an upstream provider, or an identifier that has no business in
 * a tenant's UI. What the user sees is a class-mapped sentence plus the `request_id`, which is the
 * one identifier support can grep across both services.
 */
export function ErrorPanel({
  surface,
  error,
  reset,
}: {
  /** Names the boundary in the log line only. It is never rendered. */
  readonly surface: 'admin' | 'auth';
  readonly error: Error & { digest?: string };
  readonly reset: () => void;
}) {
  useEffect(() => {
    // Log the operator-facing detail; never render it. Browser telemetry carries no user content.
    console.error('[kb] error boundary', {
      surface,
      error_class: error instanceof KbError ? error.error_class : null,
      request_id: error instanceof KbError ? error.request_id : null,
      digest: error.digest,
    });
  }, [error, surface]);

  // A non-KbError has no envelope, so error_class is null — unknown, and unknown is permanently
  // non-retryable. Never invent a class name to fill the slot.
  const copy =
    error instanceof KbError
      ? endUserCopy(error)
      : endUserCopy({ error_class: null, request_id: null });

  // Gated on the envelope's `retryable`, NOT on whether the copy says "shortly":
  // `internal_dependency` is one sentence covering a retryable dependency brownout and a
  // non-retryable unmapped exception, and the axis separating them is not in the copy.
  const retryable = error instanceof KbError && error.retryable;

  // The one identifier support can grep across both services. It appears on ERRORS only, which is
  // the whole reason it is the deliberate exception to "never surface internal vocabulary".
  const requestId = error instanceof KbError ? error.request_id : null;

  return (
    <section
      role="alert"
      aria-labelledby="error-heading"
      // Scaled to the blast radius: a whole page that failed is a centred block, not an inline
      // banner (references/states.md). It is a card because content never floats on the canvas.
      className="mx-auto flex max-w-md flex-col items-start gap-3 rounded-2xl bg-card p-card-pad-lg shadow-md"
    >
      <span
        aria-hidden
        className="flex size-12 items-center justify-center rounded-full bg-destructive-soft text-destructive-soft-foreground"
      >
        <AlertTriangleIcon className="size-6" strokeWidth={1.5} />
      </span>
      <h1 id="error-heading" className="text-h2">
        This page could not be loaded
      </h1>
      <p className="text-base text-muted-foreground">{copy}</p>
      {requestId === null ? null : <RequestId value={requestId} />}
      {retryable ? (
        // A real <Button>, so it inherits the pressed state and the global focus ring. The raw
        // <button> this replaced had neither — its only focus indicator was the UA default.
        <Button variant="outline" onClick={reset}>
          Try again
        </Button>
      ) : null}
    </section>
  );
}
