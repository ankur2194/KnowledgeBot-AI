import type {
  AcknowledgementResource,
  EmbeddingReadinessResource,
  ProviderConnectionCollectionResource,
  ProviderConnectionCreatedResponse,
  ProviderConnectionResource,
  ProviderConnectionStatus,
  ProviderKey,
} from '@kb/contracts';
import type { ProviderConnectionEditOut } from '@kb/contracts/forms';

import type { StatusKind } from '@/components/status-pill';
import { browserFetch, browserFetchData, sessionCredential } from '@/lib/api/browser';

/**
 * The provider-connection transport: five endpoints, their paths, and the two response envelopes
 * they use. REACT-FREE on purpose — nothing here imports a hook, so a spec can call a fetcher
 * directly and a render site cannot accidentally acquire a second copy of the envelope knowledge.
 *
 * ── THE RESOURCE TYPES ARE `@kb/contracts`', NOT THIS FILE'S ─────────────────────────────────────
 * `ProviderConnectionResource` and the embedding-readiness trio are mirrored in
 * `packages/contracts/src/resources/providers.ts` and compared against the generated OpenAPI document
 * by `test/resource-drift.test.ts`. Declaring them here instead is the exact chain that
 * `features/members/api.ts` used to carry and 6B named: fixture -> hand-written type -> PHP resource,
 * with no assertion at any step.
 *
 * ── THE CREDENTIAL IS WRITE-ONLY AND HAS NO TYPE THAT PAIRS IT WITH A RESOURCE ──────────────────
 * `masked_key` is `…` plus the last four characters of the stored key. It is a DISPLAY STRING: it
 * cannot authenticate anything, and posting it back sets the tenant's key to the literal text `…4a91`
 * — after which every provider call fails `provider_auth` and the 200 that caused it says nothing.
 * The server has a `not_regex:/^\x{2026}/u` rule to catch exactly that, and this client does not rely
 * on it.
 *
 * What keeps it out structurally rather than by discipline: the two request bodies below declare
 * `credential` in a LOCAL parameter type that has no other member in common with
 * `ProviderConnectionResource`, so there is no object in this app that holds both a mask and a
 * credential field and no spread that could move one into the other. `@kb/contracts/forms` exports no
 * schema naming `credential` for the same reason (NO_CLIENT_FORM in test/form-drift.test.ts).
 */

/**
 * ── THE ORGANIZATION IS IN THE PATH, AND THAT IS NOT A TENANCY VIOLATION ─────────────────────────
 * Every route below is mounted under `organizations/{organization}` with `->scopeBindings()`, and
 * `TenantContext` re-reads the membership row from PostgreSQL on EVERY request. So the segment is a
 * ROUTING HINT, never a scope: Laravel derives the real organization from the session and would ignore
 * a client-supplied one, and a foreign nested id 404s at BINDING time — before any policy runs and
 * before the row is in memory. The same `orgId` is separately a CACHE NAMESPACE in the query key,
 * which is a different job (see `useOrgKey()`).
 *
 * `encodeURIComponent` on a ULID is a no-op today. It is here because the value comes off a server
 * response and is interpolated into a URL, and the habit is what keeps the day it stops being a ULID
 * from being an injected path segment.
 */
/**
 * EXPORTED FOR `features/embedding`, which would otherwise have declared the THIRD private copy of
 * this two-line builder (`features/members/api.ts` holds the second). Three is the count the
 * `formatTimestamp` note below names as the moment a duplicate becomes a move — so rather than paste
 * it again, the embedding screen imports this one, exactly as `features/models` imports
 * `connectionPath`. The members copy is still private and is the one left to delete.
 */
export const organizationPath = (orgId: string): string =>
  `/api/v1/organizations/${encodeURIComponent(orgId)}`;

export const connectionsPath = (orgId: string): string =>
  `${organizationPath(orgId)}/provider-connections`;

export const connectionPath = (orgId: string, connectionId: string): string =>
  `${connectionsPath(orgId)}/${encodeURIComponent(connectionId)}`;

export const credentialPath = (orgId: string, connectionId: string): string =>
  `${connectionPath(orgId, connectionId)}/credential`;

/**
 * `GET …/provider-connections` -> 200 `{data: {connections: [...]}}` | 403.
 *
 * The named key is unwrapped HERE, once, beside the `data` unwrap in `browserFetchData` — never at a
 * render site. A render site that knows about the envelope is a render site that has to be edited when
 * the envelope changes, and there are more of those than there are fetchers.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it — and cancelling in-flight reads is step 2 of both logout and the organization switch,
 * which is exactly when a connection list must not resolve.
 *
 * EVERY ROW IS RETURNED WHATEVER ITS STATUS, oldest first. Revoked and invalid rows are included
 * deliberately: an operator diagnosing "why can I not ingest" has to be able to see the credential
 * that stopped working.
 */
export const fetchConnections = async (
  orgId: string,
  signal: AbortSignal,
): Promise<readonly ProviderConnectionResource[]> => {
  const body = await browserFetchData<ProviderConnectionCollectionResource>({
    path: connectionsPath(orgId),
    credential: await sessionCredential(),
    signal,
  });
  return body.connections;
};

/**
 * What the create form collects. A LOCAL type, and deliberately not exported from `@kb/contracts`:
 * see the module docblock. `credential` is write-only — it is read out of an uncontrolled-ish input
 * at submit time, never seeded into `defaultValues`, and never written back to form state afterwards.
 *
 * `models` IS SENT AS `[]` AND MUST BE. `StoreProviderConnectionRequest` rules it `present|array`,
 * and `present` means the KEY must exist — omitting it is a 422 on a field this form does not render.
 * The model catalogue is its own screen (A4a, `/settings/providers/[connectionId]`), so the create
 * path deliberately stores a connection with an empty catalogue and lets the operator fill it there;
 * that also keeps the credential form to three fields, which is the form a person can check before
 * pasting a secret into it.
 */
export interface NewProviderConnection {
  readonly provider: ProviderKey;
  readonly label: string;
  readonly credential: string;
}

/**
 * `POST …/provider-connections` -> 201 `{data: …, embedding_readiness: …}` | 422 | 403 | 409.
 *
 * THE ONE RESPONSE ON THIS API THAT IS NOT ENTIRELY UNDER `data`, so it is the one fetcher that calls
 * `browserFetch` rather than `browserFetchData`. `embedding_readiness` sits at the TOP LEVEL beside
 * `data` — one resource, two envelopes, because `GET/PUT /embedding-configuration` returns the same
 * shape wrapped. Reaching for `browserFetchData` here compiles, returns the connection, and DROPS the
 * readiness verdict in silence, which is the whole of what the create screen has to say next: "the
 * key is stored and you still cannot ingest anything, and here is why".
 *
 * NO `Idempotency-Key`, so this must never be retried by anything — including a double-click, which
 * is what `disabled={isPending}` on the submit button is for. A replayed create seals and stores the
 * key twice under two ids, and the second one is invisible until somebody wonders why there are two.
 *
 * NO `organization_id` IN THE BODY. It is a URL segment resolved by route binding; a body field would
 * be a tenant key a caller can set, which is an authorization bug with a 201 response.
 */
export const createConnection = async (
  orgId: string,
  body: NewProviderConnection,
): Promise<ProviderConnectionCreatedResponse> =>
  browserFetch<ProviderConnectionCreatedResponse>({
    path: connectionsPath(orgId),
    method: 'POST',
    body: { ...body, models: [] },
    credential: await sessionCredential(),
  });

/**
 * `PATCH …/provider-connections/{id}` -> 200 `{data: …}` | 422 | 403 | 404 | 409.
 *
 * `ProviderConnectionEditOut` IS THE SCHEMA'S OUTPUT TYPE, from `@kb/contracts/forms`, and it is the
 * only request body on this screen with a shared schema. That is not an inconsistency: this is the
 * only one of the three that carries no credential, and it cannot acquire one — the FormRequest
 * declares no such rule and `ProviderConnectionEdit` has no member to hold a key.
 *
 * Both fields are optional because the endpoint is a PATCH; the server refuses a body naming NEITHER
 * (`required_without` in both directions), and the schema mirrors that with a `superRefine`.
 */
export const updateConnection = async (
  orgId: string,
  connectionId: string,
  body: ProviderConnectionEditOut,
): Promise<ProviderConnectionResource> =>
  browserFetchData<ProviderConnectionResource>({
    path: connectionPath(orgId, connectionId),
    method: 'PATCH',
    body,
    credential: await sessionCredential(),
  });

/**
 * What the rotation dialog collects. LOCAL, write-only, and absent from every `defaultValues` in this
 * feature — see the module docblock.
 *
 * `current_password` is the §18.3 re-authentication: rotating breaks every live bot on this provider
 * the instant it commits, so the server demands a fresh proof of identity rather than the age of a
 * session. It is a `current_password:web` VALIDATION rule, so it runs before any row is read — a
 * wrong password is a 422 keyed on `current_password` and the stored credential is untouched, by
 * construction rather than by ordering discipline.
 */
export interface ProviderCredentialRotation {
  readonly credential: string;
  readonly current_password: string;
}

/**
 * `PUT …/provider-connections/{id}/credential` -> 200 `{data: …}` | 422 | 403 | 404 | 409.
 *
 * The response is the connection with a CHANGED `masked_key` and nothing else — not the new key, not
 * the old one, not a prefix of either, not a fingerprint. So the list is re-read from it rather than
 * patched, and the only visible difference is the last four characters.
 *
 * PUT rather than POST because replacing the secret is idempotent in the only sense that matters: the
 * same body applied twice leaves the same stored key. It is still never retried automatically — there
 * is no idempotency key, and a repeat costs the actor a second password prompt anyway.
 */
export const rotateCredential = async (
  orgId: string,
  connectionId: string,
  body: ProviderCredentialRotation,
): Promise<ProviderConnectionResource> =>
  browserFetchData<ProviderConnectionResource>({
    path: credentialPath(orgId, connectionId),
    method: 'PUT',
    body,
    credential: await sessionCredential(),
  });

/**
 * `DELETE …/provider-connections/{id}` -> 200 `{data: {acknowledged: true}}` | 403 | 404 | 409.
 *
 * A GUARDED HARD DELETE: the connection row and its `provider_models` rows go in one transaction and
 * the audit row is the only thing that survives. The 409 that matters is not the suspended-organization
 * one — it is "this connection supplies the organization's embedding credential", and its `message`
 * carries the ONLY sentence that tells the operator what to do next. See
 * `actionableConflictMessage` in `lib/api/actionable-conflict.ts`, which reads it.
 */
export const deleteConnection = async (
  orgId: string,
  connectionId: string,
): Promise<AcknowledgementResource> =>
  browserFetchData<AcknowledgementResource>({
    path: connectionPath(orgId, connectionId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });

/**
 * `ProviderKey` -> the vendor's own name. A `switch`, so a sixth provider added to the union fails the
 * TYPECHECK here rather than rendering the raw `nvidia_nim` wire value in a table cell; and an object
 * indexed by a value read off the wire is an injection sink eslint-plugin-security warns about.
 *
 * The casing is each VENDOR's, not this table's: "OpenAI" and "DeepSeek" are how those companies write
 * their own names, and an operator matching a row against a dashboard in another tab is matching on
 * exactly that string.
 */
export function providerLabel(provider: ProviderKey): string {
  switch (provider) {
    case 'openai':
      return 'OpenAI';
    case 'anthropic':
      return 'Anthropic';
    case 'deepseek':
      return 'DeepSeek';
    case 'nvidia_nim':
      return 'NVIDIA NIM';
    case 'openrouter':
      return 'OpenRouter';
  }
}

/** `ProviderConnectionStatus` -> the label a human reads. Same `switch` reasoning as above. */
export function connectionStatusLabel(status: ProviderConnectionStatus): string {
  switch (status) {
    case 'active':
      return 'Active';
    case 'invalid':
      return 'Invalid';
    case 'revoked':
      return 'Revoked';
  }
}

/**
 * `ProviderConnectionStatus` -> the CLOSED status vocabulary the pill renders (kb-ui-patterns P8).
 *
 * A `switch` rather than an object for the same two reasons as the label functions. The mapping is
 * the pattern's, not this screen's: ready/active -> success, failed/disabled -> destructive,
 * degraded/partial -> warning.
 *
 * `invalid` is DEGRADED and `revoked` is DISABLED, and the difference is the next step rather than the
 * severity. An invalid credential is a thing that broke and can be repaired in place — rotating the
 * key returns the row to `active` — so it reads as a warning. A revoked one is a decision somebody
 * made; nothing is wrong, and the row is inert until an operator changes their mind.
 */
export function connectionStatusKind(status: ProviderConnectionStatus): StatusKind {
  switch (status) {
    case 'active':
      return 'ready';
    case 'invalid':
      return 'degraded';
    case 'revoked':
      return 'disabled';
  }
}

/**
 * RFC 3339 -> something a human reads, or an em dash for the nullable column.
 *
 * DUPLICATED FROM `features/members/api.ts` DELIBERATELY, and this is the note that says so rather
 * than a shrug. It is eleven lines with no branching that this feature would otherwise import across a
 * feature boundary — `features/providers` importing from `features/members` is a dependency between
 * two screens that have nothing to do with each other, and the third copy is the one that should
 * become `@/lib/format/timestamp`. Recorded here so the next copy is a move rather than another
 * paste; two is not yet a pattern (the `asText` precedent, D41, moved at five).
 *
 * The VIEWER's locale and timezone, and there is no hydration hazard: every value formatted here
 * arrived from a browser fetch, so this subtree does not exist in the RSC payload and there is no
 * server-rendered string for it to disagree with. A malformed timestamp renders raw rather than
 * "Invalid Date" — the field is a straight carry off the wire, and the wire is not ours to trust twice.
 */
export function formatTimestamp(isoTimestamp: string | null): string {
  if (isoTimestamp === null) return '—';

  const at = new Date(isoTimestamp);
  if (Number.isNaN(at.getTime())) return isoTimestamp;

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(at);
}

/**
 * Is this organization's embedding blocked, and what did the data plane say about it?
 *
 * `ready` is the only field to branch on — `blocks_ingestion` is its negation under the name the
 * operator sees, and `explanation` is the data plane's OWN WORDS. The upload path raises the same
 * string, so the banner after a create and the error on the first upload say the same thing. Render
 * it; never paraphrase it, and never substitute a class-mapped sentence for it: this is not an error
 * envelope, it is a verdict, and it arrives on a 201.
 *
 * It is one of exactly two server strings this screen renders verbatim (the other is the delete
 * conflict's `message`), and both are end-user-facing by construction rather than by hope: they name
 * connection ids, provider names, model ids and matrix sources, and are asserted to carry no
 * credential and no tenant content by the data plane's own tests.
 */
export const embeddingBlockedExplanation = (
  readiness: EmbeddingReadinessResource,
): string | null => (readiness.ready ? null : readiness.explanation);

