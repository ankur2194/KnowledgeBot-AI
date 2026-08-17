import type { ClientErrorClass, ErrorClass, StreamLost } from '@kb/contracts';
import type { FieldValues, Path, UseFormReturn } from 'react-hook-form';

/**
 * Laravel's 422 `errors` map onto per-field RHF errors, with anything the form does not render
 * routed to `root.serverError`.
 *
 * Laravel already keys nested and array errors as DOT PATHS — "retrieval.top_k",
 * "starter_questions.2" — which are valid RHF names as-is (RHF rejects bracket syntax and Laravel
 * never emits it). The only translation is folding an index to `*` for the lookup against the
 * generated manifest's key set.
 *
 * Call it from the MUTATION's onError, never before or during validation: with a resolver,
 * handleSubmit assigns `_formState.errors` wholesale and then unsets the whole `root` namespace, so
 * anything written earlier is discarded and the user clicks Save into a silent loop.
 *
 * @param knownPaths the server's own field vocabulary, from packages/contracts/rules/*.json
 * @param errors     Laravel's 422 `errors` map — present ONLY on `error_class: "validation"`
 */
export function applyServerErrors<TFieldValues extends FieldValues, TOutput>(
  form: UseFormReturn<TFieldValues, unknown, TOutput>,
  knownPaths: readonly string[],
  errors: Readonly<Record<string, readonly string[]>>,
): void {
  const known = new Set(knownPaths);
  const orphans: string[] = [];
  let focused = false;

  for (const [key, messages] of Object.entries(errors)) {
    // The ONLY translation. Laravel keys array errors positionally ("starter_questions.2",
    // "models.0.context_window") while the manifest dumped from `rules()` keys them with a wildcard
    // ("models.*.context_window"), so the fold is for the MEMBERSHIP TEST only — the path handed to
    // setError stays the positional one, because that is the field the user is looking at.
    if (!known.has(key.replace(/\.\d+/g, '.*'))) {
      orphans.push(...messages);
      continue;
    }

    // criteriaMode defaults to 'firstError' and RHF renders one message per field either way; the
    // rest of the array would be written and never displayed.
    const message = messages[0];
    if (message === undefined) continue;

    // shouldFocus is granted to the FIRST field that actually has the ref `register` installs.
    // shadcn's Select/Switch and any Controller whose `field.ref` was never attached are mounted
    // but unfocusable, and RHF's focusFieldBy skips them silently — so a `shouldFocus: true` spent
    // on one is a page that never scrolls and an error the user never sees below the fold.
    const focusable = !focused && hasFocusableRef(form, key);

    form.setError(
      key as Path<TFieldValues>,
      // `type` is MANDATORY. An untyped setError on a parent path ("retrieval") replaces the whole
      // child subtree ("retrieval.top_k") and the child's error vanishes with nothing logged.
      { type: 'server', message },
      { shouldFocus: focusable },
    );

    if (focusable) focused = true;
  }

  // EXACTLY ONE root write, after the loop, with every orphan joined. `root.serverError` is a
  // single slot: calling setError on it per orphan means the last write wins and a user with five
  // problems is told about one, tries again, and is told about the same one.
  //
  // Validation MESSAGES are end-user copy — Laravel translates them — which is why they are shown
  // verbatim here while the envelope's `message` never is.
  if (orphans.length > 0) {
    form.setError('root.serverError', { type: 'server', message: orphans.join(' ') });
  }
}

/**
 * Does this path have the DOM ref `register` installs, as opposed to merely being mounted?
 *
 * `control._names.mount` cannot answer it: a `Controller` whose `field.ref` was never attached is
 * in that set and still cannot be focused. RHF's own `focusFieldBy` reads `field._f.ref.focus`, so
 * this reads the same thing. `_fields` is nested by path segment, with array indices as numeric
 * keys, which is why the walk splits on '.' rather than doing a flat lookup.
 */
function hasFocusableRef<TFieldValues extends FieldValues, TOutput>(
  form: UseFormReturn<TFieldValues, unknown, TOutput>,
  path: string,
): boolean {
  let cursor: unknown = form.control._fields;

  for (const segment of path.split('.')) {
    if (typeof cursor !== 'object' || cursor === null) return false;
    cursor = (cursor as Record<string, unknown>)[segment];
  }

  if (typeof cursor !== 'object' || cursor === null) return false;
  const field = (cursor as { _f?: { ref?: unknown } })._f;
  const ref = field?.ref as { focus?: unknown } | undefined;
  return typeof ref?.focus === 'function';
}

/**
 * error_class -> end-user copy. The envelope's `message` is OPERATOR-FACING: it can carry an
 * internal hostname, raw text from an upstream provider, or an identifier that has no business in
 * a tenant's UI, and it is the string a support engineer greps for, not one anybody wrote for a
 * reader. Log it; show this plus the request_id.
 *
 * Validation MESSAGES are different — Laravel translates those, and they are end-user copy. This
 * map is for everything that is not a per-field validation message.
 *
 * ── TOTAL OVER THE TAXONOMY, AND THAT IS WHAT THE KEY TYPE IS FOR ────────────────────────────────
 * `Record<ErrorClass | StreamLost | 'unknown', string>` rather than `Record<string, string>`. The
 * difference is the whole point: this map was keyed by bare `string`, so a 19th class added to
 * `ERROR_CLASSES` in `@kb/contracts` would have compiled here, typechecked, passed review, and then
 * fallen through `endUserCopy`'s `?? 'Something went wrong.'` — a user-visible regression with both
 * halves silent. Now it is a typecheck failure in this file, naming the missing key.
 *
 * `Record` is TOTAL AND CLOSED in both directions: a missing class is an error and so is a key that is
 * not a class, which also catches a typo'd class name that would otherwise sit here matching nothing
 * for ever. The two non-class keys are deliberate and are in the union explicitly — `stream_lost` is a
 * client-local sentinel that nothing serializes, and `unknown` is the no-envelope-parsed case.
 *
 * The coverage TEST (tests/unit/auth-error.test.ts) derives its cases from this table rather than from
 * a hand-written list, so the two checks catch different things: the type catches a class with no copy,
 * the test catches copy that is never exercised.
 */
export const ERROR_COPY: Readonly<Record<ErrorClass | StreamLost | 'unknown', string>> = {
  validation: 'Some details need fixing before this can be saved.',
  authentication: 'Your session has ended. Sign in again to continue.',
  authorization: 'You do not have access to this.',
  tenant_quota: 'This organization has reached its plan limit for this action.',
  rate_limit: 'Too many requests just now. Wait a moment and try again.',
  provider_auth: 'The AI provider rejected this workspace’s credentials.',
  provider_rate_limit: 'The AI provider is rate-limiting us. Try again shortly.',
  provider_billing: 'The AI provider account needs attention before this can run.',
  provider_temporary: 'The AI provider is temporarily unavailable. Try again shortly.',
  provider_permanent_request: 'This bot’s model configuration was rejected by the provider.',
  retrieval: 'Search over your knowledge sources is unavailable right now.',
  parsing: 'This document could not be read.',
  ocr: 'Text could not be extracted from part of this document.',
  crawl: 'This page could not be fetched.',
  vector_indexing: 'Indexing did not complete. It will be retried.',
  storage: 'File storage is unavailable right now.',
  // TWO SUB-CASES, ONE SENTENCE, on purpose. `internal_dependency` renders 503/retryable for a real
  // dependency brownout and 500/not-retryable for an unmapped exception in our own code, and the
  // axis separating them is not on the wire (ADR-029, finding O1) — so this map, which is keyed on
  // the class alone, cannot tell them apart and must not pretend to. What DOES tell them apart is
  // the envelope's `retryable`, which is why every Retry affordance is gated on it (see
  // src/app/(admin)/error.tsx) rather than on this string's "shortly".
  internal_dependency: 'Something on our side is unavailable. Try again shortly.',
  user_cancellation: 'Cancelled.',
  // The client-local sentinel: no terminal event arrived because the connection died. It is not a
  // 19th class and nothing serializes it.
  stream_lost: 'The connection dropped before the answer finished.',
  // `null` means no envelope parsed. Unknown, and unknown is permanently non-retryable — never
  // invent a class name to fill the slot.
  unknown: 'Something went wrong.',
};

/** The whole user-visible string: a class-mapped sentence plus the one identifier support can grep
 *  across both services. */
export function endUserCopy(error: {
  error_class: ClientErrorClass;
  request_id?: string | null;
}): string {
  const copy = ERROR_COPY[error.error_class ?? 'unknown'] ?? 'Something went wrong.';
  return error.request_id ? `${copy} (ref ${error.request_id})` : copy;
}

/*
 * THERE IS NO `hasFieldErrors` HERE, DELIBERATELY. Use `isKbValidationEnvelope` from
 * `@kb/contracts`, which is the same narrowing done correctly and is already exported beside the
 * `KbValidationEnvelope` type it produces.
 *
 * What was here (5B-S4) tested `typeof envelope.errors === 'object'` and nothing else. `typeof null`
 * IS `'object'`, so a `null` errors map narrowed to `Record<string, string[]>` and
 * `Object.entries(null)` threw inside `applyServerErrors` above — a TypeError from a guard whose
 * whole job was to prevent one. It also took a `KbErrorEnvelope` rather than `unknown`, so it could
 * only be called on a value something else had already validated: two guards where the second one
 * was the weaker.
 *
 * The contract says `errors` is never null on a validation envelope, so this was unreachable from a
 * correct server. That is precisely why it survived — and why the duplicate had to go rather than be
 * patched: a second copy of a guard is a second copy of a rule, and the copies drift silently.
 */
