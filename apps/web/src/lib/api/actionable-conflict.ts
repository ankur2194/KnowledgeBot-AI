import { KbError } from '@kb/contracts';

/**
 * The one server `message` this app renders verbatim, and the reasoning is worth the length because
 * it is a deliberate exception to a rule this app otherwise never breaks.
 *
 * ── WHY IT LIVES IN `lib/api` AND NOT IN A FEATURE ───────────────────────────────────────────────
 * It began in `features/providers/api.ts` as the reader for one 409 on the delete path, and the name
 * it carried there — `deleteConflictMessage` — described that call site rather than this function.
 * It now has five importers across four features (bot publishing, bot origins, embedding designation,
 * provider connections, provider models) and no relationship to deletes at all: it branches on
 * `error_class`, `retryable` and `actionable`, and never on a status or a method. There has only ever
 * been ONE implementation — this is a move and a rename, not the resolution of a fork — but a helper
 * named after a job it stopped doing costs a reader in every file that imports it, and
 * `features/embedding/api.ts` had already grown a section headed "WHY THIS IS NOT
 * `deleteConflictMessage` UNDER A NEW NAME" to answer the question the name provoked.
 *
 * ── WHAT THE SERVER SENDS ────────────────────────────────────────────────────────────────────────
 * Deleting the connection an organization has DESIGNATED for embedding is refused with a 409 whose
 * message names the remedy and the consequence: clear the designation first, read the readiness
 * verdict, then delete. That sentence is the only thing on the wire that tells the operator what to do
 * next — the class-mapped copy for this error is "Something on our side is unavailable. Try again
 * shortly.", which is false twice (nothing is unavailable, and retrying never works). The publish
 * guard's refusals (`BotService::assertPublishable()`: no connection and model, or `rag_first` with
 * `allow_general_answers` still false) arrive in exactly the same envelope, which is why one reader
 * serves both rather than each screen growing a branch table.
 *
 * ── WHY THE CLASS DOES NOT IDENTIFY IT, AND WHY THAT IS DELIBERATE SERVER-SIDE ───────────────────
 * The taxonomy has 18 classes and no 409 row, on purpose: 409 is a RENDERING of `internal_dependency`
 * for an unclassified 4xx our own code raised, and the render closure preserves both the status and the
 * message. `retryable` is false because the origin is `self`. So an actionable 409 and a genuine
 * 500 arrive at this client as the SAME `(error_class, retryable)` pair, and the status is not on
 * `KbError` at all.
 *
 * ── HOW THIS TELLS THEM APART WITHOUT BRANCHING ON A STATUS ─────────────────────────────────────
 * By the envelope's `actionable` flag, which both planes set beside the message and which says
 * exactly one thing: this `message` was written for this condition, rather than being the fixed
 * placeholder chosen to say nothing. It is not a status and nothing here infers one from it.
 *
 * ── WHAT THIS USED TO DO, KEPT BECAUSE THE SHAPE RECURS ────────────────────────────────────────
 * Until the flag existed (finding J2) this compared `message` against a client-side copy of the
 * server's 5xx constant, and treated "anything else" as a deliberate 4xx. That worked, and it was a
 * DENY-BY-EXCLUSION filter whose premise was a property of the whole server tree rather than of the
 * response in hand: the first `abort(400, $detail)` reachable from these screens would have broken
 * it silently, and in the worse direction — a defect whose message happened to differ would have
 * read to an operator as advice. A client inferring a status from a string is the coupling
 * `error_class` exists to remove, so the fix was server-side and the string is gone from this file.
 *
 * The fallback is unchanged and is what keeps the failure mode benign: anything this returns null
 * for falls back to `actionErrorCopy`'s class-mapped sentence, so the worst case is a user reading
 * a bland accurate sentence — never an internal hostname, because a >= 500 envelope is never
 * `actionable`.
 */
export function actionableConflictMessage(error: unknown): string | null {
  if (!(error instanceof KbError)) return null;
  // NOT a status check — `KbError` carries none, deliberately. `internal_dependency` +
  // `retryable: false` is the pair a deliberate 4xx and a defect share; `actionable` is what
  // separates them, and it is the server's own answer rather than this client's inference.
  if (error.error_class !== 'internal_dependency' || error.retryable) return null;
  if (!error.actionable) return null;

  const message = error.message.trim();

  // An `actionable` envelope with an empty message is not a state either plane produces — both
  // derive the flag from a non-empty message — so this is a guard against a body that lied, not a
  // case. Rendering "" would blank the one line telling the operator what to do next.
  return message === '' ? null : message;
}
