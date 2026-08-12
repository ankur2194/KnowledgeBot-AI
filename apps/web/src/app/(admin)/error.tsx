'use client';

import { KbError } from '@kb/contracts';
import { useEffect } from 'react';

import { endUserCopy } from '@/lib/forms/apply-server-errors';

/**
 * The admin error boundary. It branches on `error_class`, NEVER on an HTTP status: one class
 * renders two statuses by surface (`authorization` is 403 on admin and 404 on public), a 422 can
 * be a validation failure or an idempotency conflict, and status-driven logic retries a
 * `tenant_quota` 403 forever while reading an `authorization` 404 as "absent, so create it".
 *
 * It NEVER renders the envelope's `message`. That field is operator-facing — it can carry an
 * internal hostname, raw text from an upstream provider, or an identifier that has no business in
 * a tenant's UI. What the user sees is a class-mapped sentence plus the `request_id`, which is the
 * one identifier support can grep across both services.
 */
export default function AdminError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // Log the operator-facing detail; never render it. Browser telemetry carries no user content.
    console.error('[kb] admin boundary', {
      error_class: error instanceof KbError ? error.error_class : null,
      request_id: error instanceof KbError ? error.request_id : null,
      digest: error.digest,
    });
  }, [error]);

  // A non-KbError has no envelope, so error_class is null — unknown, and unknown is permanently
  // non-retryable. Never invent a class name to fill the slot.
  const copy =
    error instanceof KbError
      ? endUserCopy(error)
      : endUserCopy({ error_class: null, request_id: null });

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
