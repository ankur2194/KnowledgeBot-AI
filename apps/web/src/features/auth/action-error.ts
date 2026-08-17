import { KbError } from '@kb/contracts';

import { endUserCopy } from '@/lib/forms/apply-server-errors';

/**
 * `applyAuthError`'s counterpart for a mutation THAT HAS NO FORM.
 *
 * ── WHY IT EXISTS, AND WHY IT IS NOT A SECOND BRANCH TABLE ────────────────────────────────────────
 * `applyAuthError` maps an error onto a react-hook-form instance, and half of its value is routing a
 * 422 key to the field that owns it. Three of this batch's actions have no fields at all: accepting an
 * invitation, revoking one, and resending one are a button each. There is no `setError` target, no
 * `root.serverError` slot, and no `formState` to read a message back out of.
 *
 * And they DO return 422s that a user has to be able to read. `App\Services\Auth\InvitationService`
 * throws `ValidationException::withMessages(['invitation' => [self::NOT_REVOCABLE]])` for an
 * invitation that has already been accepted, and the same shape for a resend that has nothing left to
 * deliver; `AcceptInvitationController` collapses invalid token, address mismatch and "already a
 * member" into one 422 on `token`. Those strings are Laravel-translated end-user copy — the SAME
 * property that lets `applyServerErrors` render its orphan messages verbatim — and running them
 * through `endUserCopy` instead would show `ERROR_COPY.validation`: "Some details need fixing before
 * this can be saved.", which is nonsense next to a Revoke button and tells the administrator nothing
 * about why the row did not change.
 *
 * So this function is exactly one arm wider than `endUserCopy` and NOT a re-implementation of the
 * class table: every non-validation class delegates, so a change to `ERROR_COPY` reaches here for
 * free and there is nothing to keep in sync.
 *
 * ── THE RULES IT STILL OBEYS ─────────────────────────────────────────────────────────────────────
 *  - Branches on `error_class`, never on an HTTP status.
 *  - Never renders the envelope's `message`. That field is operator-facing: it can carry an internal
 *    hostname or raw text from an upstream provider, and it is the string a support engineer greps for
 *    rather than one anybody wrote for a reader. Validation MESSAGES are a different field and are
 *    end-user copy by construction.
 *  - `error_class: null` means no envelope parsed: unknown, permanently non-retryable, and it gets
 *    `endUserCopy`'s sentence for null. No class is ever invented to fill the slot.
 *  - The `request_id` still reaches the screen, because it is the one identifier support can grep
 *    across both services — but only on the paths where `endUserCopy` puts it there. A validation
 *    message is about the row in front of the user and needs no reference number.
 */
export function actionErrorCopy(error: unknown): string {
  if (!(error instanceof KbError)) {
    return endUserCopy({ error_class: null, request_id: null });
  }

  if (error.error_class === 'validation' && error.errors !== null) {
    const messages = Object.values(error.errors).flatMap((field) => [...field]);
    // A 422 with an EMPTY map is a server contract violation rather than a message; fall through to the
    // class sentence instead of rendering a blank alert the user cannot act on.
    if (messages.length > 0) return messages.join(' ');
  }

  return endUserCopy(error);
}
