'use client';

import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * A 429's `Retry-After`, rendered as a countdown that disables submit — AND IT IS NOT A RETRY.
 *
 * The distinction is enforced, not stylistic: a `retry` property is an ESLint error outside
 * src/lib/query/client.ts, and mutations are `retry: false` globally there because a POST is not
 * replayable. So the client does not reattempt a throttled login on the user's behalf; it tells them
 * how long to wait and refuses to send until then. The user decides.
 *
 * `retry_after` arrives in SECONDS off the `Retry-After` RESPONSE HEADER — it is not in the JSON
 * envelope (packages/contracts/src/errors.ts:33-34). A 429 that omits the header degrades silently to
 * no cooldown at all, which is why `start(null)` is a no-op rather than an invented default: guessing
 * a window means submitting inside the one we were told to wait, which keeps the limiter rejecting.
 *
 * This path is EXPECTED rather than exotic. Login throttles per account and per IP together, and it
 * is the single most rate-limited endpoint in the product.
 */
export interface Cooldown {
  /** Whole seconds left, `0` when there is no cooldown. Gate `disabled` on `> 0`. */
  readonly remaining: number;
  /** Seconds from a `KbError.retry_after`. `null` or a non-positive value starts nothing. */
  readonly start: (seconds: number | null) => void;
}

/** One second, spelled once. */
const TICK_MS = 1_000;

/** A limiter window nobody should be locked out by, however the header was computed. */
const MAX_COOLDOWN_SECONDS = 3_600;

export function useCooldown(): Cooldown {
  const [remaining, setRemaining] = useState(0);
  // The deadline, not the count, is the source of truth: an interval that only decrements drifts
  // whenever the tab is throttled or backgrounded, and a backgrounded tab is the normal case for
  // "wait a minute and try again".
  const deadline = useRef<number | null>(null);

  useEffect(() => {
    if (remaining <= 0) return;

    const id = setInterval(() => {
      const at = deadline.current;
      if (at === null) {
        setRemaining(0);
        return;
      }
      setRemaining(Math.max(0, Math.ceil((at - Date.now()) / TICK_MS)));
    }, TICK_MS);

    // Cleared on unmount as well as on expiry: a form that unmounts mid-cooldown must not leave a
    // timer writing into a dead component.
    return () => {
      clearInterval(id);
    };
  }, [remaining]);

  const start = useCallback((seconds: number | null) => {
    if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) return;
    const capped = Math.min(Math.ceil(seconds), MAX_COOLDOWN_SECONDS);
    deadline.current = Date.now() + capped * TICK_MS;
    setRemaining(capped);
  }, []);

  return { remaining, start };
}
