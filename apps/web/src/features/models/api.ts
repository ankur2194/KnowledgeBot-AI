import type {
  AcknowledgementResource,
  ProviderConnectionResource,
  ProviderModelCollectionResource,
  ProviderModelResource,
} from '@kb/contracts';
import type { ProviderModelCreateOut, ProviderModelEditOut } from '@kb/contracts/forms';
import storeProviderModelRules from '@kb/contracts/rules/StoreProviderModelRequest.json';
import updateProviderModelRules from '@kb/contracts/rules/UpdateProviderModelRequest.json';

import { connectionPath } from '@/features/providers/api';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';

/**
 * The model-catalog transport for ONE provider connection: five endpoints, the capability vocabulary
 * the console offers, and the two display formatters the table needs. REACT-FREE on purpose — nothing
 * here imports a hook, so a spec can call a fetcher directly and a render site cannot accidentally
 * acquire a second copy of the envelope knowledge.
 *
 * ── THE RESOURCE TYPES ARE `@kb/contracts`', NOT THIS FILE'S ─────────────────────────────────────
 * `ProviderModelResource` and its collection wrapper are mirrored in
 * `packages/contracts/src/resources/provider-models.ts` and compared against the generated OpenAPI
 * document by `test/resource-drift.test.ts`. Declaring them here instead is the chain 6B named:
 * fixture -> hand-written type -> PHP resource, with no assertion at any step.
 *
 * ── NOTHING ON THIS SURFACE REACHES A CREDENTIAL, AND THAT IS WHY IT LOOKS SIMPLER THAN A3'S ────
 * `features/providers/api.ts` is long because half of it is about keeping `masked_key` away from an
 * input. A catalog row has no such field: `ProviderModelController` imports no vault, the service it
 * calls imports no vault, and the resource renders no field of the parent connection except its
 * ULID. So both request bodies have SHARED, drift-tested Zod schemas in `@kb/contracts/forms`, which
 * neither of the two credential requests may have.
 *
 * ── THE PATHS ARE BUILT FROM `connectionPath`, NOT RE-SPELLED ───────────────────────────────────
 * `features/providers/api.ts` exports it, and re-deriving
 * `/api/v1/organizations/{org}/provider-connections/{id}` here would be a second spelling of one
 * route. The same import brings `deleteConflictMessage`, which the delete path below needs and which
 * must never be copied — see `model-list.tsx`.
 *
 * THE ORGANIZATION AND THE CONNECTION ARE BOTH IN THE PATH, AND NEITHER IS THE SCOPE. The routes are
 * mounted with `->scopeBindings()` and `TenantContext` re-reads the membership row from PostgreSQL on
 * EVERY request, so both segments are ROUTING HINTS: a foreign organization, a foreign connection, or
 * a model belonging to a DIFFERENT connection of the same organization all 404 at BINDING time,
 * before any policy runs. The `orgId` is separately a CACHE NAMESPACE in the query key, which is a
 * different job (see `useOrgKey()`), and the `connectionId` joins it there so two connections' catalogs
 * cannot share one cache entry.
 */

export const modelsPath = (orgId: string, connectionId: string): string =>
  `${connectionPath(orgId, connectionId)}/models`;

/**
 * `encodeURIComponent` on a ULID is a no-op today. It is here because the value comes off a server
 * response and is interpolated into a URL, and the habit is what keeps the day it stops being a ULID
 * from being an injected path segment.
 */
export const modelPath = (orgId: string, connectionId: string, modelId: string): string =>
  `${modelsPath(orgId, connectionId)}/${encodeURIComponent(modelId)}`;

/**
 * `GET …/provider-connections/{id}` -> 200 `{data: …}` | 403 | 404.
 *
 * THE PARENT ROW, read by the catalog screen for its heading. It lives in this feature rather than in
 * `features/providers/api.ts` because this is the only screen that reads ONE connection — the list
 * screen reads the collection — and because the path builder it needs is already imported above.
 *
 * A foreign or unknown `connectionId` 404s at binding time, and `bootstrap/app.php` renders 404 as
 * `authorization` (the deny split), so the screen shows the class-mapped "You do not have access to
 * this." rather than a page that half-renders. That is the correct answer for both cases and the
 * client cannot tell them apart, which is the point of the split.
 */
export const fetchConnection = async (
  orgId: string,
  connectionId: string,
  signal: AbortSignal,
): Promise<ProviderConnectionResource> =>
  browserFetchData<ProviderConnectionResource>({
    path: connectionPath(orgId, connectionId),
    credential: await sessionCredential(),
    signal,
  });

/**
 * `GET …/models` -> 200 `{data: {models: [...]}}` | 403 | 404.
 *
 * The named key is unwrapped HERE, once, beside the `data` unwrap in `browserFetchData` — never at a
 * render site. EVERY ROW IS RETURNED WHATEVER ITS `enabled` STATE, ordered by model identifier:
 * an operator asking "why is this model missing from the bot's dropdown" has to be able to find it,
 * and hiding a disabled row makes the question unanswerable from the console while it sits in the
 * table.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it — and cancelling in-flight reads is step 2 of both logout and the organization switch,
 * which is exactly when another organization's catalog must not resolve.
 */
export const fetchModels = async (
  orgId: string,
  connectionId: string,
  signal: AbortSignal,
): Promise<readonly ProviderModelResource[]> => {
  const body = await browserFetchData<ProviderModelCollectionResource>({
    path: modelsPath(orgId, connectionId),
    credential: await sessionCredential(),
    signal,
  });
  return body.models;
};

/**
 * `POST …/models` -> 201 `{data: …}` | 422 | 403 | 404 | 409.
 *
 * A DUPLICATE `(organization, connection, model)` IS A 422 KEYED ON `model`, not a 500: the service
 * runs an org-scoped pre-flight for the readable message and catches SQLSTATE 23505 on the unique
 * index for the race it cannot win. So the create form's own field carries that message.
 *
 * NO `Idempotency-Key`, so this must never be retried by anything — including a double-click, which
 * `disabled={isPending}` on the submit button is for. A replayed create is a 422 rather than a
 * duplicate row, thanks to the index, but it is still a 422 the operator has to read and dismiss.
 *
 * NO `organization_id` AND NO `connection_id` IN THE BODY. Both are URL segments resolved by route
 * binding; a body field would be a tenant key a caller can set, which is an authorization bug with a
 * 201. `ProviderModelCreateOut` cannot express either — the schema is a `strictObject` and
 * `OWNERSHIP_KEYS` is asserted against every schema in the package.
 */
export const createModel = async (
  orgId: string,
  connectionId: string,
  body: ProviderModelCreateOut,
): Promise<ProviderModelResource> =>
  browserFetchData<ProviderModelResource>({
    path: modelsPath(orgId, connectionId),
    method: 'POST',
    body,
    credential: await sessionCredential(),
  });

/**
 * `PUT …/models/{model}` -> 200 `{data: …}` | 422 | 403 | 404 | 409.
 *
 * A PUT AND NOT A PATCH, AND A BODY THAT CHANGES NOTHING IS A 422. The FormRequest requires the full
 * attribute set, because "must change something" is expressible in `rules()` only as
 * `required_without_all` naming six siblings on each of seven fields, and the readable alternative —
 * an `after()` closure — is INVISIBLE to `kb:dump-form-rules`, so a generated client would never be
 * told the constraint exists.
 *
 * WHAT THAT MEANS FOR EVERY CALLER HERE: the body is seeded from the loaded row and then modified.
 * `providerModelEditDefaults(row)` returns the schema's OUTPUT type for exactly this reason, so
 * `{...providerModelEditDefaults(row), enabled: next}` is a complete replacement that cannot have
 * dropped a capability flag or re-scaled a price by omission.
 *
 * THE MODEL IDENTIFIER IS NOT IN THE BODY and cannot be: `UpdateProviderModelRequest` declares no
 * such field, `ProviderModelEdit` has no member for one, and the repository never assigns the column.
 * It is half of the vector-space identity for everything already embedded through this row.
 */
export const updateModel = async (
  orgId: string,
  connectionId: string,
  modelId: string,
  body: ProviderModelEditOut,
): Promise<ProviderModelResource> =>
  browserFetchData<ProviderModelResource>({
    path: modelPath(orgId, connectionId, modelId),
    method: 'PUT',
    body,
    credential: await sessionCredential(),
  });

/**
 * `DELETE …/models/{model}` -> 200 `{data: {acknowledged: true}}` | 403 | 404 | 409.
 *
 * A GUARDED HARD DELETE. The 409 that matters is not the suspended-organization one — it is "this row
 * is the (connection, model) pair the organization's embedding designation names", and its `message`
 * carries the ONLY sentence that tells the operator what to do next. It is read with
 * `deleteConflictMessage` from `features/providers/api.ts`, which is IMPORTED rather than copied; see
 * `model-list.tsx` for why a second copy of that inference would be a real defect rather than
 * duplication.
 *
 * NEVER a 204: `browserFetch` short-circuits 204/205 to `undefined` before parsing, and this endpoint
 * answers 200 with an acknowledgement body.
 */
export const deleteModel = async (
  orgId: string,
  connectionId: string,
  modelId: string,
): Promise<AcknowledgementResource> =>
  browserFetchData<AcknowledgementResource>({
    path: modelPath(orgId, connectionId, modelId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });

// ── THE CAPABILITY VOCABULARY ───────────────────────────────────────────────────────────────────

/**
 * WHICH TASK A ROW IS FOR. Not a server field: it is derived from `supported` and used only to
 * arrange the form, because ROWS ARE TASK-EXCLUSIVE — an embedding row never carries the chat flags
 * `text`/`tool_use`/`reasoning`, and no row carries both `embedding` and `rerank`.
 * `openai/text-embedding-3-large` and `gpt-5.6-sol` are different products reached through different
 * endpoints, and a row claiming both describes a model that does not exist.
 *
 * ── WHERE THAT IS ACTUALLY REFUSED, WHICH IS NOT WHERE THIS COMMENT USED TO SAY ────────────────
 * It used to claim "the data plane refuses it at save time". IT DOES NOT, and an operator reading
 * this form needs the true version. `assert_row_coherent` is never reached from the model-catalogue
 * write path: `ProviderModelService` imports no internal client and `ProviderModelController` never
 * crosses the Laravel↔FastAPI seam, so a POST or PUT of `openrouter` + `["rerank"]` returns 201/200
 * and stores exactly what it was sent. THE REFUSAL IS DEFERRED TO READINESS: the only reachable
 * caller is `ineligibility()` in `app/providers/embedding_selection.py`, which catches the `KbError`
 * and turns it into the `row_incoherent` rejection the embedding-designation screen renders
 * (`features/embedding/api.ts`).
 *
 * AND THAT PATH ONLY EVER WALKS *EMBEDDING* CANDIDATES. A mis-flagged rerank row is refused by
 * nothing, anywhere: it saves silently, it appears in no `rejected[]` list, and it simply never
 * reranks — which is the same silence the rerank capability gate produces and is why the family
 * description says so beside the checkboxes.
 *
 * So the CHOICE this form expresses is not a mirror of a server check; for the rerank branch it is
 * the only check there is. That is the reason it is a radio group rather than a warning.
 */
export type CapabilityFamily = 'chat' | 'embedding' | 'rerank';

/**
 * THE CLOSED VOCABULARY, DECLARED EXACTLY ONCE IN THIS APP. Every render site — the table's flag
 * cells, the form's checkbox groups, the "flags this console does not recognise" note — reads it
 * from here, and none of them restates a member or a count.
 *
 * ── WHY IT IS HERE AND NOT IN `packages/contracts` ──────────────────────────────────────────────
 * The authority is the data plane's `Capability` StrEnum
 * (`services/ai-service/app/providers/contract.py`). The CONTROL PLANE validates `supported.*` as
 * `string|max:64|regex:/^[a-z][a-z0-9_]*$/` and publishes `array<string>` with NO enum,
 * deliberately: the pattern is a claim about SPELLING (it closes an audit-field smuggling channel),
 * the vocabulary is not Laravel's, and a closed copy in the shared package would be a guarantee no
 * layer makes — it would break a generated client the day the data plane grows a member. So the wire
 * type stays open and this list is a UI AFFORDANCE over it: it decides which checkboxes exist, and
 * nothing else.
 *
 * WHICH IS WHY AN UNRECOGNISED FLAG IS RENDERED VERBATIM AND PRESERVED ON SAVE. A console that
 * silently dropped a flag it had never heard of would strip a capability the platform depends on,
 * with a 200 on the request that did it.
 *
 * ADDING A MEMBER IS A THREE-PART CHANGE ON THE OTHER SIDE — the enum, the adapter's `validate()`
 * mapping, and the `provider_models.capability_flags` backfill — and this list is the fourth part:
 * until it lands here, the flag exists and no operator can set it from the console.
 * `tests/unit/model-catalogue.test.ts` reads the enum and set-compares, so the omission is a red
 * suite rather than a feature that is dark.
 *
 * ── NO LABEL HERE MAY CONTAIN A FAMILY LABEL, OR BE CONTAINED BY ONE ───────────────────────────
 * `Produces vectors` rather than `Embedding`, and `Scores passages` rather than `Reranking`, because
 * the family radio buttons beside these checkboxes are named `Embedding` and `Rerank`. Accessible-name
 * matching is a case-insensitive SUBSTRING in both Playwright and vitest-browser, so a checkbox named
 * `Embedding` and a radio named `Embedding` resolve to two elements and every locator for either
 * fails on strict mode — the same rule that produced "Connection label" on the connections screen.
 * `Thinking text is returned` rather than `Reasoning trace` is the third instance: `Reasoning` is a
 * label in its own right one line above it.
 */
const CAPABILITY_CATALOGUE = [
  ['text', 'chat', 'Text generation'],
  ['image_input', 'chat', 'Image input'],
  ['tool_use', 'chat', 'Tool use'],
  ['structured_output', 'chat', 'Structured output (enforced JSON Schema)'],
  ['json_mode', 'chat', 'JSON mode (valid JSON, no schema)'],
  ['reasoning', 'chat', 'Reasoning'],
  ['reasoning_trace', 'chat', 'Thinking text is returned'],
  ['sampling', 'chat', 'Temperature and top-p accepted'],
  ['prompt_caching', 'chat', 'Prompt caching'],
  ['stream_usage', 'chat', 'Usage arrives on the stream'],
  ['early_input_usage', 'chat', 'Input usage known before the stream ends'],
  ['embedding', 'embedding', 'Produces vectors'],
  ['embedding_input_type', 'embedding', 'Query and passage input types'],
  ['embedding_dimensions', 'embedding', 'Configurable dimensions'],
  ['rerank', 'rerank', 'Scores passages'],
] as const satisfies readonly (readonly [string, CapabilityFamily, string])[];

/** Every flag this console can offer. Derived, never typed out a second time. */
export type Capability = (typeof CAPABILITY_CATALOGUE)[number][0];

export const CAPABILITIES: readonly Capability[] = CAPABILITY_CATALOGUE.map(([flag]) => flag);

/**
 * `Map`s rather than `Record`s, and the reason is not taste: the keys these are looked up by come
 * OFF THE WIRE (`supported` is an open string array), and indexing a plain object by a value a
 * server supplied is the injection sink `eslint-plugin-security` reports. `Map.get` is not, and it
 * also gives the unknown-flag case a natural answer instead of `undefined` at a type that promised
 * otherwise.
 */
const CAPABILITY_LABELS = new Map<string, string>(
  CAPABILITY_CATALOGUE.map(([flag, , label]) => [flag, label]),
);

const CAPABILITY_FAMILY_OF = new Map<string, CapabilityFamily>(
  CAPABILITY_CATALOGUE.map(([flag, family]) => [flag, family]),
);

/** A flag this console knows how to name. Everything else is a string we pass through untouched. */
export const isKnownCapability = (flag: string): boolean => CAPABILITY_LABELS.has(flag);

/**
 * A flag -> the words an operator reads, or THE FLAG ITSELF when the vocabulary has outgrown this
 * build. Rendering the raw value is the honest fallback: it is what the row claims, it is what the
 * data plane will read, and inventing a label for it would be worse than showing it.
 */
export const capabilityLabel = (flag: string): string => CAPABILITY_LABELS.get(flag) ?? flag;

/** The flags this console offers for one task. The form renders exactly one family's worth. */
export const capabilitiesInFamily = (family: CapabilityFamily): readonly Capability[] =>
  CAPABILITY_CATALOGUE.filter(([, member]) => member === family).map(([flag]) => flag);

/**
 * The three families, in the order the form offers them. A tuple rather than an inline literal at
 * the render site, so "how many task kinds are there" has one answer in this app.
 */
export const CAPABILITY_FAMILIES = [
  ['chat', 'Chat', 'Generation, tools and reasoning. The default for a conversational model.'],
  [
    'embedding',
    'Embedding',
    'Turns text into vectors. One connection can carry two embedding models, and those are two ' +
      'different vector spaces.',
  ],
  [
    'rerank',
    'Rerank',
    'Re-scores retrieved passages before the answer is written. Reranking is capability-gated: a ' +
      'model that can rerank but is not flagged here simply never reranks, and nothing errors.',
  ],
] as const satisfies readonly (readonly [CapabilityFamily, string, string])[];

/**
 * Which task an EXISTING row is for, read off its flags.
 *
 * The non-chat flags win because they are the discriminating ones: a coherent row carries at most one
 * of `embedding`/`rerank`, and a row carrying neither is a chat row (including one that claims
 * nothing at all, which is what a malformed stored value renders as). An INCOHERENT row — one the
 * data plane would refuse — still resolves to something here rather than throwing, because the
 * console has to be able to open and repair it.
 */
export const familyOfRow = (supported: readonly string[]): CapabilityFamily => {
  if (supported.includes('embedding')) return 'embedding';
  if (supported.includes('rerank')) return 'rerank';
  return 'chat';
};

/**
 * The flags on this row that this build has never heard of. Rendered as a note in the form and
 * carried through the submit untouched — see `CAPABILITY_CATALOGUE`.
 */
export const unknownCapabilities = (supported: readonly string[]): readonly string[] =>
  supported.filter((flag) => !isKnownCapability(flag));

/** Which family a known flag belongs to; `null` for a flag this build does not know. */
export const familyOfCapability = (flag: string): CapabilityFamily | null =>
  CAPABILITY_FAMILY_OF.get(flag) ?? null;

// ── WHAT TASK-EXCLUSIVITY ACTUALLY FORBIDS ──────────────────────────────────────────────────────

/**
 * THE THREE CHAT FLAGS THE DATA PLANE REFUSES BESIDE AN EMBEDDING OR RERANK FLAG, and the whole
 * point of this constant is that it is THREE and not eleven.
 *
 * `assert_row_coherent` (`services/ai-service/app/providers/capabilities.py`) forbids exactly two
 * combinations:
 *
 *   1. `embedding` together with `rerank` — two task surfaces on one row.
 *   2. either of those together with any of `{TEXT, TOOL_USE, REASONING}` — the flags that are
 *      *meaningless* on a non-chat row rather than merely false.
 *
 * IT FORBIDS NOTHING ELSE. `stream_usage`, `prompt_caching`, `sampling`, `image_input`,
 * `structured_output`, `json_mode`, `reasoning_trace` and `early_input_usage` are all accepted
 * alongside `embedding` or `rerank`, and some are genuinely true there — a provider that reports
 * usage on an embedding response, or caches an embedding prompt, is describing a real property of a
 * real deployment.
 *
 * This console used to treat all eleven members of its `chat` FAMILY as exclusive, and the family is
 * a UI arrangement rather than a server rule. So an embedding row that legitimately carried
 * `stream_usage` lost it the moment an operator touched the task radio for any reason — a client
 * STRICTER than the server, removing functionality with nothing reported anywhere, which is the
 * asymmetric failure `rhf-zod-forms` NN3 names. The narrowing is the repair; the cross-plane
 * assertion in `tests/unit/model-catalogue.test.ts` is what stops it drifting back.
 *
 * A `Capability[]` and not a bare `string[]`, so a member retired from `CAPABILITY_CATALOGUE`
 * fails to typecheck here rather than becoming a name nothing matches.
 */
export const TASK_EXCLUSIVE_CHAT_FLAGS: readonly Capability[] = ['text', 'tool_use', 'reasoning'];

/**
 * The flag whose PRESENCE makes a row that family's — for the two families that have one; `chat` is
 * the residue and has none, which is exactly what `familyOfRow` encodes.
 *
 * A `Map` and not a `Record`, for the reason the note above `CAPABILITY_LABELS` gives: indexing a
 * plain object by a variable is the sink `eslint-plugin-security` reports, and a keyed lookup that
 * reads a shape from the wire vocabulary tomorrow should not have to be rewritten to be safe.
 */
const FAMILY_DISCRIMINATOR = new Map<CapabilityFamily, Capability>([
  ['embedding', 'embedding'],
  ['rerank', 'rerank'],
]);

/**
 * The flags a row may not keep once it is declared to be for `family` — derived from the two rules
 * above and from nothing else.
 *
 * The other families' DISCRIMINATORS always go, for two independent reasons: rule 1 refuses
 * `embedding` + `rerank`, and `familyOfRow` reads the discriminators first, so a retained one would
 * re-open the row in the family the operator just left. The three chat flags go only when the target
 * is a non-chat family, which is rule 2 read literally.
 *
 * Everything else survives, INCLUDING a known flag from another family that the data plane permits
 * — `CapabilityGroup` shows those as carried-through badges beside the unknown ones, so nothing is
 * kept in the payload while being invisible on screen.
 */
export const flagsRefusedOn = (family: CapabilityFamily): ReadonlySet<string> => {
  const refused = new Set<string>();

  for (const [member] of CAPABILITY_FAMILIES) {
    const discriminator = FAMILY_DISCRIMINATOR.get(member);
    if (discriminator !== undefined && member !== family) refused.add(discriminator);
  }

  if (family !== 'chat') for (const flag of TASK_EXCLUSIVE_CHAT_FLAGS) refused.add(flag);

  return refused;
};

// ── DISPLAY FORMATTERS ──────────────────────────────────────────────────────────────────────────

/**
 * A stored price -> something a human reads, WITHOUT EVER PARSING IT INTO A NUMBER.
 *
 * `'0.020000'` is what the server returns for `'0.02'` (cast `decimal:6` over a `numeric(14, 6)`
 * column) and the six trailing digits are noise on screen. Stripping them is STRING SURGERY:
 * `Number('0.020000').toString()` would give the same answer today and is the first step of the
 * round-trip that re-scales the value on the way back — and `Intl.NumberFormat` takes a number by
 * definition. Nothing in this app converts a price to a float, so nothing in this app can.
 *
 * A null amount renders as an em dash and NOT as "free" or "0": null means no price has been
 * recorded, which is a different fact. The currency is appended when there is one; a currency with no
 * price is a legal row and shows as the dash.
 */
export function formatPrice(amount: string | null, currency: string | null): string {
  if (amount === null) return '—';

  // Only ever removes characters, and only after a decimal point: `'10.000000'` -> `'10'`,
  // `'1.250000'` -> `'1.25'`, `'1000000'` -> `'1000000'`.
  const trimmed = amount.includes('.')
    ? amount.replace(/0+$/, '').replace(/\.$/, '')
    : amount;

  return currency === null ? trimmed : `${trimmed} ${currency}`;
}

/**
 * A token count -> a grouped number, or the words for zero.
 *
 * ZERO MEANS "NOT RECORDED" AND IS NOT A LIMIT OF ZERO — the resource says so, and rendering a bare
 * `0` in a max-output column reads as a model that can emit nothing. `Intl` is safe here in a way it
 * is not for a price: these are integers off the wire, already typed `number`, and grouping them does
 * not round-trip through a decimal representation.
 *
 * The VIEWER's locale, and there is no hydration hazard: every value formatted here arrived from a
 * browser fetch, so this subtree does not exist in the RSC payload and there is no server-rendered
 * string for it to disagree with.
 */
export function formatTokenCount(tokens: number): string {
  if (tokens === 0) return 'Not recorded';
  return new Intl.NumberFormat().format(tokens);
}

// ── THE 422 FIELD VOCABULARIES ──────────────────────────────────────────────────────────────────

/**
 * The paths each form RENDERS, derived from the server's own `rules()` — never typed out.
 *
 * ── THE `supported.*` SUBTRACTION, WHICH IS THIS FEATURE'S `HIDDEN_PATHS` ───────────────────────
 * Both manifests declare `supported` AND `supported.*`. Laravel keys an array error POSITIONALLY
 * (`supported.3`), and `applyServerErrors` folds `\.\d+` to `.*` for the membership test — so with
 * the element path in this set, a 422 on one flag would be written to `setError('supported.3')`.
 * There is no control by that name: the capability group is one `Controller` over `supported`, and
 * `<FormMessage>` reads the error at its OWN field name. The message would display NOWHERE — the
 * operator clicks Save, the server rejects, nothing changes on screen, and they click again.
 *
 * Subtracting it routes the message to `root.serverError`, which is the banner above the form, where
 * "the capability flag at position 3 is not a string" belongs. `supported` ITSELF stays in the set,
 * because the group does render a message slot and `max:20` is a per-field 422 that belongs under it.
 *
 * The helper comes from `@/lib/forms/known-paths`, which is where `features/auth/known-paths.ts`
 * asked the next feature to move it. These two constants are NOT over there: they are this feature's,
 * and the auth module's spec closes over the exact set of `*_KNOWN_PATHS` it exports.
 */
const withoutElementPaths = (paths: readonly string[]): readonly string[] =>
  paths.filter((path) => !path.endsWith('.*'));

/** `StoreProviderModelRequest`'s vocabulary, minus the element sub-path. */
export const PROVIDER_MODEL_KNOWN_PATHS: readonly string[] = withoutElementPaths(
  knownPathsFromRules(storeProviderModelRules as FormRulesManifest),
);

/** `UpdateProviderModelRequest`'s vocabulary — the same set without `model`, which is immutable. */
export const PROVIDER_MODEL_EDIT_KNOWN_PATHS: readonly string[] = withoutElementPaths(
  knownPathsFromRules(updateProviderModelRules as FormRulesManifest),
);
