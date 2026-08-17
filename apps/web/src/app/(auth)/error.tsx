'use client';

import { ErrorPanel } from '@/components/error-panel';

/**
 * The `(auth)` error boundary — an error thrown during RENDER, not one returned by a mutation (those
 * land on the form through `applyServerErrors` or `root.serverError`). Identical logic to the admin
 * boundary, deliberately shared through <ErrorPanel/>; it exists as a separate file only because this
 * group has its own root layout.
 */
export default function AuthError(props: {
  readonly error: Error & { digest?: string };
  readonly reset: () => void;
}) {
  return <ErrorPanel surface="auth" {...props} />;
}
