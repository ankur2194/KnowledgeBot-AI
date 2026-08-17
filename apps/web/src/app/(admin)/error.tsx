'use client';

import { ErrorPanel } from '@/components/error-panel';

/**
 * The admin error boundary. The `error_class`-not-status branching, the "never render the envelope's
 * `message`" rule and the `retryable`-gated Retry affordance all live in <ErrorPanel/>, shared with
 * the `(auth)` boundary — same logic, different root layout, which is why the file exists at all.
 */
export default function AdminError(props: {
  readonly error: Error & { digest?: string };
  readonly reset: () => void;
}) {
  return <ErrorPanel surface="admin" {...props} />;
}
