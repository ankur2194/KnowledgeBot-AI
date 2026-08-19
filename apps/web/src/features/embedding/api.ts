import { KbError } from '@kb/contracts';
import type {
  EmbeddingCandidate,
  EmbeddingDesignation,
  EmbeddingReadinessResource,
} from '@kb/contracts';
import type { EmbeddingDesignationOut } from '@kb/contracts/forms';
import designateEmbeddingRules from '@kb/contracts/rules/DesignateEmbeddingConnectionRequest.json';

import { organizationPath } from '@/features/providers/api';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';

/**
 * The embedding-designation transport: TWO endpoints on one path, the closed rejection vocabulary,
 * and the one sentinel this screen needs that A3's did not. REACT-FREE on purpose — nothing here
 * imports a hook, so a spec can call a fetcher directly and a render site cannot accidentally
 * acquire a second copy of the envelope knowledge.
 *
 * ── THE RESOURCE TYPES ARE `@kb/contracts`', NOT THIS FILE'S ─────────────────────────────────────
 * `EmbeddingReadinessResource`, `EmbeddingCandidate` and `EmbeddingRejection` are mirrored in
 * `packages/contracts/src/resources/providers.ts` and compared against the generated OpenAPI
 * document by `test/resource-drift.test.ts`. A3 put them there already, because
 * `POST /provider-connections` returns the same verdict as a second top-level key, so this screen
 * adds nothing to that package and must not: a hand-written copy here would be the chain 6B named —
 * fixture -> hand-written type -> PHP resource, with no assertion at any step.
 *
 * ── `organizationPath` IS IMPORTED RATHER THAN RE-SPELLED, AND IT WAS ABOUT TO BE THE THIRD COPY ─
 * `features/providers/api.ts` and `features/members/api.ts` each declared a private
 * `/api/v1/organizations/{org}` builder. This would have been the third, which is the count
 * `providers/api.ts`'s own `formatTimestamp` note names as the moment a duplicate becomes a move —
 * so the provider copy was EXPORTED (a one-word change to a finished file) and is imported here
 * instead. The members copy is still private and is now the one that should be deleted in favour of
 * this import; that is reported rather than done, because `features/members` is finished and green
 * and a two-line hoist is not worth reopening it.
 *
 * ── THE ORGANIZATION IS IN THE PATH, AND THAT IS NOT A TENANCY VIOLATION ────────────────────────
 * Both routes are mounted under `organizations/{organization}`, and `TenantContext` re-reads the
 * membership row from PostgreSQL on EVERY request. So the segment is a ROUTING HINT, never a scope:
 * Laravel derives the real organization from the session and would ignore a client-supplied one, and
 * a foreign id 404s at BINDING time — before any policy runs. The same `orgId` is separately a CACHE
 * NAMESPACE in the query key, which is a different job (see `useOrgKey()`).
 */

export const embeddingConfigurationPath = (orgId: string): string =>
  `${organizationPath(orgId)}/embedding-configuration`;

/**
 * `GET …/embedding-configuration` -> 200 `{data: EmbeddingReadinessResource}` | 403.
 *
 * ── THIS READ DELIBERATELY HAS NO 409, AND THE OMISSION IS THE FEATURE ──────────────────────────
 * `PUT` refuses a suspended organization with a 409; `GET` does not, because an organization that is
 * blocked must still be able to read WHY it is blocked. A read that 409'd would leave the operator
 * with a banner they cannot see and an upload they cannot explain. So this fetcher has no conflict
 * path at all and every failure it can produce is `authorization` or a genuine fault.
 *
 * NOT CACHED SERVER-SIDE ANYWHERE, in either plane: the verdict is a pure function of the connection
 * set and the capability matrix, and a cached "ready" that outlived a model-row edit would send an
 * ingest run to spend parse and OCR before failing. `browserFetch` sets `cache: 'no-store'`, so the
 * browser's own URL-keyed HTTP cache does not hold it either — and the URL does not name the
 * organization, which is exactly why that matters.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it — and cancelling in-flight reads is step 2 of both logout and the organization switch,
 * which is exactly when a readiness verdict must not resolve.
 */
export const fetchEmbeddingConfiguration = async (
  orgId: string,
  signal: AbortSignal,
): Promise<EmbeddingReadinessResource> =>
  browserFetchData<EmbeddingReadinessResource>({
    path: embeddingConfigurationPath(orgId),
    credential: await sessionCredential(),
    signal,
  });

/**
 * `PUT …/embedding-configuration` -> 200 `{data: EmbeddingReadinessResource}` | 422 | 403 | 409.
 *
 * THE RESPONSE IS THE READINESS *AFTER* THE WRITE, not an acknowledgement — which is why the screen
 * re-reads from it rather than assuming the designation took: the server may accept the pair and
 * still hand back `rejected[]` entries explaining why some OTHER connection is not being used.
 *
 * THE BODY IS EXACTLY TWO KEYS AND BOTH ARE ALWAYS PRESENT. The rules are `present|nullable` with
 * `required_with` in both directions, and the database repeats the pair rule one layer down as
 * `CHECK num_nonnulls(...) <> 1`. Clearing the designation is `{connection_id: null, model: null}` —
 * THE SAME ENDPOINT, not a DELETE and not an omitted key. `EmbeddingDesignationOut` is the schema's
 * output type, so a body that omits one key does not compile.
 *
 * NO `organization_id` IN THE BODY. It is a URL segment resolved by route binding; a body field
 * would be a tenant key a caller can set, which is an authorization bug with a 200 response.
 *
 * PUT rather than PATCH because the designation is REPLACED wholesale: there is no half of it to
 * patch, by construction.
 */
export const designateEmbeddingConnection = async (
  orgId: string,
  body: EmbeddingDesignationOut,
): Promise<EmbeddingReadinessResource> =>
  browserFetchData<EmbeddingReadinessResource>({
    path: embeddingConfigurationPath(orgId),
    method: 'PUT',
    body,
    credential: await sessionCredential(),
  });

/**
 * `DesignateEmbeddingConnectionRequest`'s vocabulary — `['connection_id', 'model']` — from the
 * server's own dumped `rules()`, never typed out.
 *
 * NOTHING IS SUBTRACTED, unlike the two provider constants. Both paths have a rendered message slot
 * on this screen (see `designation-form.tsx`): `connection_id` under the radio group that writes it,
 * and `model` under its own empty `<FormItem>`, which exists for no other reason than to give a 422
 * on the half of the pair with no control of its own somewhere to display. Subtracting `model` would
 * route it to the banner, which is also survivable — but the pair is chosen as a pair, so a message
 * about one half belongs beside the control that set both.
 */
export const EMBEDDING_DESIGNATION_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  designateEmbeddingRules as FormRulesManifest,
);

/**
 * The `(connection, model)` pair as ONE opaque control value, and back again by comparison.
 *
 * ── WHY A COMPOSITE AND NOT THE CONNECTION ID ──────────────────────────────────────────────────
 * ONE CONNECTION CAN CARRY TWO EMBEDDING MODELS, and those are two different vector spaces. A radio
 * group keyed on `connection_id` alone would make the second one unreachable and would silently pick
 * whichever came first — the exact "nothing raises, only ranking degrades" failure ADR-031 is about.
 *
 * ── WHY IT IS NEVER PARSED BACK ────────────────────────────────────────────────────────────────
 * The caller finds the candidate by COMPARING keys (`eligible.find(c => candidateKey(c) === value)`),
 * so no separator can ever be ambiguous. That matters because a vendor model id genuinely contains
 * both `/` and `:` — `meta/llama-4-70b`, `qwen:7b` — and a split-on-first-colon parser would return
 * a model id that is a prefix of the real one, which names a different space and finds nothing.
 * `encodeURIComponent` is belt on top of that: the value also lands in a DOM attribute.
 */
export const candidateKey = (candidate: {
  readonly connection_id: string;
  readonly model: string;
}): string =>
  `${encodeURIComponent(candidate.connection_id)}:${encodeURIComponent(candidate.model)}`;

/**
 * The radio value meaning "no designation — resolve by rule". It contains no `:`, and every
 * `candidateKey` does, so it cannot collide with a real pair however a vendor names its models.
 */
export const NO_DESIGNATION = 'no-designation';

/**
 * `(provider, model)` as the data plane itself spells it — `openai/text-embedding-3-large`.
 *
 * ── THE VENDOR NAME IS NOT PRETTIFIED HERE, AND `providerLabel` IS DELIBERATELY NOT REUSED ──────
 * `EmbeddingCandidate.provider` is a BARE STRING on the wire while `ProviderConnectionResource
 * .provider` is a published enum, and the asymmetry is the server's: this field is RELAYED from the
 * data plane's resolution rule and validated by nobody on the way through, so the OpenAPI document
 * declares no enum for it. Passing it to `providerLabel(provider as ProviderKey)` would be a cast
 * asserting a guarantee no layer makes, and its `switch` would fall off the end returning `undefined`
 * for a vendor Laravel does not store.
 *
 * Verbatim is also the more USEFUL rendering. This pair IS the vector space — `EmbeddingSpace`
 * derives the Qdrant collection name from it — so an operator comparing this screen against a
 * vendor dashboard, a data-plane log line or the `detail` sentence two lines below is comparing
 * exactly these characters. "OpenAI / Text Embedding 3 Large" would be prettier and unmatchable.
 */
export const describeCandidate = (candidate: EmbeddingCandidate): string =>
  `${candidate.provider}/${candidate.model}`;

/**
 * WHAT THE ORGANIZATION STORED vs WHAT THE RESOLVER PRODUCED, as one of four named states.
 *
 * ── THE TWO STATES THIS EXISTS TO SEPARATE ──────────────────────────────────────────────────────
 * `selected === null` used to be one rendering — "nothing is resolved" — and it is two situations
 * with two different next actions:
 *
 *   nothing_designated         nothing was stored and nothing resolved by rule. The operator has
 *                              never chosen; the next action is to choose for the first time.
 *   designated_but_unresolved  a pair IS stored and it no longer resolves — the model row lost its
 *                              embedding flag, or the connection was revoked. The next action is to
 *                              designate a DIFFERENT, working pair, and the stored pair is worth
 *                              naming because the operator is about to go and look at it.
 *
 * Before `EmbeddingReadinessResource.designated` existed, the stored pair reached this screen only
 * inside the free-text `explanation`, so telling the two apart would have meant parsing a paragraph
 * the data plane owns and may reword. That is why this is a field and not a regex.
 *
 * ── WHAT THIS FUNCTION IS NOT ───────────────────────────────────────────────────────────────────
 * NOT a verdict. `ready` is the verdict and `explanation` is the reason, both the server's, both
 * rendered as they arrive. This is a two-null branch over two fields, so a UI can pick which true
 * sentence to show — it computes no eligibility, reads no `rejected[]` entry and never explains WHY
 * a designation stopped resolving. The one file that may answer that is
 * `services/ai-service/app/providers/embedding_selection.py`.
 *
 * ── WHY THE READY SIDE IS SPLIT TOO ─────────────────────────────────────────────────────────────
 * `resolved_by_rule` is a genuinely more fragile state than `designated_and_resolved`: it is exactly
 * the state a SECOND embedding-capable connection turns into the ADR-031 ambiguity, with no edit to
 * this organization's designation and no warning. Saying which one an operator is in costs one
 * sentence and is the difference between "this is pinned" and "this happens to be the only one".
 */
export type DesignationState =
  | 'designated_and_resolved'
  | 'designated_but_unresolved'
  | 'resolved_by_rule'
  | 'nothing_designated';

export const designationState = (
  readiness: Pick<EmbeddingReadinessResource, 'selected' | 'designated'>,
): DesignationState => {
  if (readiness.designated !== null) {
    return readiness.selected === null ? 'designated_but_unresolved' : 'designated_and_resolved';
  }

  return readiness.selected === null ? 'nothing_designated' : 'resolved_by_rule';
};

/**
 * The STORED pair as one string, and the reason it is not `describeCandidate`.
 *
 * `EmbeddingDesignation` HAS NO `provider` — the organization stores a connection id and a model
 * string, and the vendor is a property of the connection, resolved at read time. Passing a
 * designation to `describeCandidate` would compile only through a cast and would render
 * `undefined/text-embedding-3-large`, on the one screen whose whole job is to name the pair exactly.
 * So the model is returned alone and the connection id is rendered beside it as its own field.
 */
export const describeDesignation = (designation: EmbeddingDesignation): string => designation.model;

/**
 * An `EmbeddingIneligibility` member -> the sentence a human reads, or `null` for a member this
 * build has never heard of.
 *
 * ── A `switch`, NEVER AN OBJECT INDEXED BY A WIRE VALUE ─────────────────────────────────────────
 * Same rule as `providerLabel` and `connectionStatusKind`: an object indexed by a value read off the
 * wire is the injection sink `eslint-plugin-security` warns about, and a lookup miss returns
 * `undefined` where a `switch` returns something the caller declared.
 *
 * ── WHY THE `default` ARM EXISTS HERE AND NOT ON THE PROVIDER SWITCHES ─────────────────────────
 * `ProviderKey` is a CLOSED union, so its `switch` is exhaustive and a sixth vendor is a typecheck
 * failure — which is the property that motivates the pattern. `EmbeddingRejection.reason` is typed
 * `string` on purpose (`packages/contracts/src/resources/providers.ts`) because the closed set is the
 * DATA PLANE's `EmbeddingIneligibility` and neither Laravel nor this package validates it, so
 * exhaustiveness is not available and pretending otherwise with a cast would turn a fourth reason
 * into a crash on a screen whose whole job is to explain a refusal.
 *
 * Returning `null` instead lets the caller render the raw `reason` beside the server's `detail`,
 * which is strictly more information than a sentence this build guessed at. THE SENTENCES ARE
 * LABELS FOR THE CLOSED VOCABULARY, NOT PARAPHRASES OF `detail` — `detail` is the data plane's own
 * words quoting the capability-matrix cell and renders verbatim next to whichever of these applies.
 */
export function rejectionReasonSentence(reason: string): string | null {
  switch (reason) {
    case 'vendor_has_no_endpoint':
      // Axis 1: `capabilities.PROVIDER_TASKS` does not record this vendor as supported on the
      // embedding surface. NOT fixable by editing the row — it needs a recorded fixture and a matrix
      // move, so the honest next action is a connection to a different vendor.
      return 'This vendor publishes no embedding endpoint. Editing the model row cannot change that — use a connection to a vendor that does.';
    case 'row_lacks_embedding_flag':
      // Axis 2, and the only one an operator can fix from this console. The flag is never inferred
      // from the model id string, deliberately: a name that looks like an embedding model is not
      // evidence that it is one.
      return 'The model row does not claim the embedding capability. Add the embedding flag to it on that connection’s model catalogue.';
    case 'row_incoherent':
      // Both axes pass and the row is still unusable, because it claims two task families at once.
      // It describes a model that does not exist, and selecting it would validate every request
      // against the wrong schema.
      return 'The model row claims more than one task, so it describes a model that does not exist. Edit it to claim embedding and nothing else.';
    default:
      return null;
  }
}

/**
 * The 422 that is NOT a field error, and the one server `message` this screen renders verbatim.
 *
 * ── WHAT THE SERVER SENDS, AND WHY TWO DIFFERENT THINGS ARRIVE AS `validation` ──────────────────
 * `EmbeddingConfigurationController::update` documents it exactly: "422 is both the FormRequest's
 * half-designation rule and the resolver's refusal, which share an `error_class` of `validation` and
 * differ only in whether `errors` is present."
 *
 *   - THE FORMREQUEST'S RULE is a Laravel `ValidationException`, so `bootstrap/app.php` attaches the
 *     `errors` map and `applyServerErrors` routes each message to the field that owns it.
 *   - THE RESOLVER'S REFUSAL is `KbException::validation($readiness->explanation)` — a
 *     `RuntimeException`, not a `ValidationException` — so NO `errors` key is attached and the whole
 *     of it is a PARAGRAPH in `message`: the data plane's own words about why the named pair cannot
 *     embed, or why two eligible connections that disagree on `(provider, model)` cannot be
 *     tie-broken by convention (ADR-031).
 *
 * ── WHY THE DISCRIMINATOR IS SOUND RATHER THAN A GUESS ABOUT WORDING ───────────────────────────
 * `bootstrap/app.php:446` states the invariant in one line: "`errors` is a SUPERSET present only on
 * `validation` — never null, never {} elsewhere." `KbError.errors` is `null` when the envelope
 * carried no map (`packages/contracts/src/errors.ts`), so `validation` WITH `errors === null` is,
 * by construction, a deliberate refusal our own code raised with a sentence written for a person.
 * There is no string comparison here and no status inference.
 *
 * ── WHY RENDERING IT IS NOT A BREACH OF "NEVER RENDER THE ENVELOPE'S `message`" ────────────────
 * Because it is the SAME STRING as `EmbeddingReadinessResource.explanation`, which this screen
 * already renders verbatim on a 200 and which the ingestion path raises on a failed upload. A
 * paraphrase would be a second copy that drifts, and `ERROR_COPY.validation` — "Some details need
 * fixing before this can be saved." — is worse than useless here: it says nothing about which pair,
 * why, or what to do, and there is no field it could be pointing at.
 *
 * It is end-user-readable by construction rather than by hope: `embedding_selection.py` builds it
 * from connection ids, provider names, model ids and matrix sources only, and the data plane's own
 * tests assert it carries no credential and no tenant content.
 *
 * ── WHY THIS IS NOT `deleteConflictMessage` UNDER A NEW NAME ───────────────────────────────────
 * That sentinel discriminates a deliberate 4xx from a defect INSIDE `internal_dependency`, where the
 * only difference is whether the message equals the fixed >=500 constant. This one discriminates two
 * populations inside `validation`, where the difference is a structural field. Same shape of
 * problem, different axis; folding them into one function would mean a predicate that branches on
 * class first and then does two unrelated things. A3's sentinel is still used on this screen — see
 * `designation-form.tsx`, which passes it as `copyFor` for the 409.
 */
export function resolverRefusalMessage(error: unknown): string | null {
  if (!(error instanceof KbError)) return null;
  // NOT a status check — `KbError` carries none, on purpose.
  if (error.error_class !== 'validation') return null;
  // A field-error map means the FormRequest refused the SHAPE. That is `applyServerErrors`' job and
  // routing it here would put "The model field is required." in a guidance panel with no control.
  if (error.errors !== null) return null;

  const message = error.message.trim();
  // A `validation` envelope with neither a map nor a sentence is a server contract violation rather
  // than guidance; fall through to the class-mapped copy instead of rendering an empty panel.
  return message === '' ? null : message;
}
