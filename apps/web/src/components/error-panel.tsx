'use client';

import { KbError } from '@kb/contracts';
import { useEffect } from 'react';

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

  return (
    <section role="alert" aria-labelledby="error-heading" className="space-y-4">
      <h1 id="error-heading" className="text-2xl font-semibold">
        Something went wrong
      </h1>
      <p className="text-sm">{copy}</p>
      {retryable ? (
        <button type="button" onClick={reset} className="rounded-md border px-3 py-2 text-sm">
          Try again
        </button>
      ) : null}
    </section>
  );
}
