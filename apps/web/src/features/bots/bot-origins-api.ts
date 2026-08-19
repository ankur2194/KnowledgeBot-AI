import type {
  AcknowledgementResource,
  BotDomainCollectionResource,
  BotDomainResource,
  BotDomainStatus,
} from '@kb/contracts';
import type { BotDomainCreateOut, BotDomainStatusOut } from '@kb/contracts/forms';
import storeBotDomainRules from '@kb/contracts/rules/StoreBotDomainRequest.json';
import updateBotDomainRules from '@kb/contracts/rules/UpdateBotDomainRequest.json';

import type { StatusKind } from '@/components/status-pill';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';

import { botPath } from './api';

/**
 * THE WIDGET ORIGIN ALLOW-LIST'S TRANSPORT — four endpoints, two `knownPaths` sets and one display
 * vocabulary. REACT-FREE, exactly as `./api.ts` is: nothing here imports a hook, so a spec can call
 * a fetcher directly and a render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── WHY THIS IS A PRIVATE MODULE AND NOT FOUR MORE EXPORTS IN `./api.ts` ────────────────────────
 * `./api.ts` is the editor's PUBLISHED SURFACE and is read-only to the three panel authors
 * (`bot-editor-screen.tsx` §1). The domains endpoints landed after it was frozen and it carries no
 * function for any of them, so the choice was a private module beside the one panel that renders
 * them or an edit to a file this agent may not touch. This is the first; `botPath` is imported from
 * there rather than respelled, so the ONE definition of a bot's URL still lives in one place.
 *
 * IT SHOULD MOVE. When `./api.ts`'s owner next opens it, `botDomainsPath`, the four fetchers and
 * the two `knownPaths` constants belong beside `botStatusPath` and `BOT_STATUS_KNOWN_PATHS` — they
 * are the same kind of thing, and a second surface that lists a bot's origins (an embed-instructions
 * screen, say) would otherwise import them across a panel boundary. Reported rather than done.
 *
 * ── A ROW HERE IS A SECURITY CONTROL, WHICH CHANGES WHAT THIS FILE MAY DO ───────────────────────
 * An `active` row is what lets a page on the public internet boot a chat widget that speaks with
 * this organization's credential, on its corpus, against its quota. Three consequences, all of them
 * in code below rather than in a comment somewhere else:
 *
 *   1. NOTHING HERE NORMALISES AN ORIGIN. `App\Support\Web\ExactOrigin` lower-cases the scheme and
 *      host, drops one trailing slash and drops a default port — and REFUSES a path rather than
 *      trimming it, because trimming would widen the grant from one page to a whole host. A client
 *      copy of that grammar would be the third spelling of a control whose refusals are its content
 *      (`packages/contracts/src/forms/bot-domain.ts` argues this at length). The schema carries the
 *      length, the type and the two-scheme prefix; everything else is a 422 keyed `origin` with the
 *      server's own sentence.
 *   2. THE 201 BODY IS WHAT THE LIST RENDERS, never the submitted string. `HTTPS://Example.COM:443/`
 *      is STORED as `https://example.com`, and the runtime comparison is byte equality against the
 *      browser's `Origin` header — so echoing the input would show the operator a string that is
 *      not the row, on the one screen where "what was stored" is the entire question.
 *   3. THERE IS NO WILDCARD FORM. The comparison is byte equality; a pattern would make it a
 *      matcher, and a matcher is where the bypasses live.
 */

/** `…/bots/{bot}/domains`. `botPath` already encodes both parent segments. */
export const botDomainsPath = (orgId: string, botId: string): string =>
  `${botPath(orgId, botId)}/domains`;

/**
 * `…/bots/{bot}/domains/{domain}`.
 *
 * `encodeURIComponent` on a ULID is a no-op today, and it is here for the reason `botPath` gives:
 * the value comes off a server response and is interpolated into a URL, and the habit is what keeps
 * the day it stops being a ULID from being an injected path segment.
 */
export const botDomainPath = (orgId: string, botId: string, domainId: string): string =>
  `${botDomainsPath(orgId, botId)}/${encodeURIComponent(domainId)}`;

/**
 * `GET …/bots/{bot}/domains` -> 200 `{data: {domains: [...]}}` | 403 | 404.
 *
 * `bots.view` — all four roles hold it, and `App\Enums\Permission::BotsView` names "a bot's
 * configuration, its origin allow-list, and its starter questions" in as many words. So an analyst
 * reads this list; only the three write verbs need `bots.manage`.
 *
 * EVERY ROW IS RETURNED, INCLUDING `pending` AND `disabled` ONES, and the collection resource says
 * why: filtering them out would make "why is my widget refused on this site" unanswerable from the
 * console while the row sat in the table.
 *
 * AN EMPTY ARRAY DENIES EVERY ORIGIN and must never be rendered as "unrestricted" — the embed
 * decision is "some active row matches this exact origin", which is false for the empty set by
 * construction rather than by a rule somebody has to remember.
 *
 * The named key is unwrapped HERE, once, beside the `data` unwrap in `browserFetchData` — never at
 * a render site. `signal` is forwarded because `queryClient.cancelQueries()` is a no-op against a
 * `queryFn` that drops it, and cancelling in-flight reads is step 2 of both logout and the
 * organization switch.
 */
export const fetchBotDomains = async (
  orgId: string,
  botId: string,
  signal: AbortSignal,
): Promise<readonly BotDomainResource[]> => {
  const body = await browserFetchData<BotDomainCollectionResource>({
    path: botDomainsPath(orgId, botId),
    credential: await sessionCredential(),
    signal,
  });
  return body.domains;
};

/**
 * `POST …/bots/{bot}/domains` -> 201 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * THE STORED VALUE IS NOT THE POSTED VALUE, and the 201 body is where the caller finds that out.
 * Every caller renders the returned `origin`; none renders what it submitted.
 *
 * A NEW ROW IS `pending` AND GRANTS NOTHING. Entering an origin and marking it usable are two
 * requests on purpose — see `updateBotDomainStatus` — so this call cannot promote.
 *
 * 422 IS A DUPLICATE ORIGIN or a string the grammar refuses, keyed `origin` either way, carrying
 * Laravel's own translated sentence. 409 is the suspended-organization refusal, which is not
 * keyed to any field and reaches the banner.
 */
export const createBotDomain = async (
  orgId: string,
  botId: string,
  body: BotDomainCreateOut,
): Promise<BotDomainResource> =>
  browserFetchData<BotDomainResource>({
    path: botDomainsPath(orgId, botId),
    method: 'POST',
    body,
    credential: await sessionCredential(),
  });

/**
 * `PATCH …/bots/{bot}/domains/{domain}` -> 200 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * ── THE WHOLE BODY IS `status`, AND `origin` IS IMMUTABLE ───────────────────────────────────────
 * `UpdateBotDomainRequest` declares no rule for `origin`, and `botDomainStatusSchema` is a
 * `strictObject` of one key so an edit that tried to correct a typo is a parse failure rather than
 * a silent strip. Editing an origin in place would carry an existing promotion across to a
 * DIFFERENT origin — a grant moved silently, while every audit row naming it still read the old
 * string. The console removes the row and adds the right one; the trail then says both happened.
 *
 * ── PROMOTION IS A SEPARATE, SEPARATELY-AUDITED REQUEST, AND `active` IS NOT A VERIFICATION ─────
 * No proof of control runs anywhere in this product: no DNS TXT record is read, no well-known path
 * is fetched, nothing is resolved. `active` means AN OPERATOR CONFIRMED IT, and every string this
 * client renders beside the control has to be true of that and of nothing more. The reason it is
 * its own endpoint is that one request must not be able to both enter an origin and make it usable
 * — `bot.domain.created` and `bot.domain.status_changed` are two audit rows for two decisions.
 */
export const updateBotDomainStatus = async (
  orgId: string,
  botId: string,
  domainId: string,
  body: BotDomainStatusOut,
): Promise<BotDomainResource> =>
  browserFetchData<BotDomainResource>({
    path: botDomainPath(orgId, botId, domainId),
    method: 'PATCH',
    body,
    credential: await sessionCredential(),
  });

/**
 * `DELETE …/bots/{bot}/domains/{domain}` -> 200 `{data: {acknowledged: true}}` | 403 | 404 | 409.
 *
 * NO REQUEST BODY, SO NO 422 AND NO FIELD TO KEY ONE TO. Every failure here is a banner, which is
 * what `actionErrorCopy` is for.
 *
 * The row is GONE rather than disabled, and `disabled` exists precisely so that "stop permitting
 * this for now" does not have to be a delete. `bot.domain.deleted` carries the origin verbatim and
 * outlives the bot, which is finding L2 being closed: deleting a bot used to destroy its allow-list
 * with no record of what it had permitted.
 */
export const deleteBotDomain = async (
  orgId: string,
  botId: string,
  domainId: string,
): Promise<AcknowledgementResource> =>
  browserFetchData<AcknowledgementResource>({
    path: botDomainPath(orgId, botId, domainId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });

/**
 * `StoreBotDomainRequest`'s vocabulary — the ONE name a create 422 can be keyed to.
 *
 * DERIVED from the server's own dumped `rules()` rather than typed out as `['origin']`, for the
 * reason every `knownPaths` set in this feature is: the filter is over the SERVER's key set, so a
 * field this form renders that the server drops disappears from the set rather than becoming a path
 * Laravel cannot key. It is a one-element set today and the derivation costs nothing.
 */
export const BOT_DOMAIN_CREATE_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  storeBotDomainRules as FormRulesManifest,
);

/** `UpdateBotDomainRequest`'s vocabulary — `status`, derived the same way. */
export const BOT_DOMAIN_STATUS_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  updateBotDomainRules as FormRulesManifest,
);

export interface BotDomainStatusDisplay {
  readonly kind: StatusKind;
  /** Sentence case. The WORD is the channel that survives greyscale and CVD, and it is never omitted. */
  readonly label: string;
  /** What this state means for an embed, in one clause, for the control's description. */
  readonly meaning: string;
}

/**
 * The three lifecycle values, mapped onto `<StatusPill>`'s closed vocabulary (P8).
 *
 * `pending` takes the pending bucket: it is where every row starts and it grants nothing. `active`
 * is `ready`, and it is the ONE value that permits an embed. `disabled` is a withdrawn row kept so
 * it can be turned back on without retyping — the disabled bucket, which renders the same slate as
 * pending, so the two are separated by their WORD alone. That residue is `./api.ts`'s (the shared
 * vocabulary gives `pending` and `disabled` one glyph) and is recorded there rather than repaired
 * here.
 *
 * NO LABEL CLAIMS A VERIFICATION. "Allowed" would; "Active" does not, and the `meaning` clause says
 * out loud that the operator is the only thing that confirmed it.
 *
 * A `Record` keyed by the union, so a fourth status added to `BotDomainStatus` fails to typecheck
 * HERE rather than rendering as a bare wire string on the screen.
 */
const BOT_DOMAIN_STATUS_DISPLAY = {
  pending: {
    kind: 'pending',
    label: 'Pending',
    meaning: 'entered but not turned on — a widget on it is refused',
  },
  active: {
    kind: 'ready',
    label: 'Active',
    meaning: 'an operator turned it on — a widget on it may boot',
  },
  disabled: {
    kind: 'disabled',
    label: 'Disabled',
    meaning: 'turned off and kept, so it can be turned back on without retyping',
  },
} as const satisfies Readonly<Record<BotDomainStatus, BotDomainStatusDisplay>>;

/**
 * A `Map`, and not the object above, for the LOOKUP: the key comes off the wire, and indexing a
 * plain object by a server-supplied value is the `security/detect-object-injection` sink. It also
 * gives the unknown-status case a natural answer.
 */
const BOT_DOMAIN_STATUS_BY_NAME = new Map<string, BotDomainStatusDisplay>(
  Object.entries(BOT_DOMAIN_STATUS_DISPLAY),
);

/**
 * A stored status -> the pill's kind and word, or THE VALUE ITSELF in the neutral bucket when this
 * build has not heard of it. Rendering the raw value is the honest fallback — it is what the row
 * claims and what the server will act on — and inventing a label for it would be worse. The
 * `meaning` clause deliberately says nothing in that case: a sentence about what an unknown state
 * permits would be a guess about a security control.
 */
export const botDomainStatusDisplay = (status: string): BotDomainStatusDisplay =>
  BOT_DOMAIN_STATUS_BY_NAME.get(status) ?? {
    kind: 'pending',
    label: status,
    meaning: 'a state this console does not recognise',
  };
