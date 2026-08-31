/**
 * The PUBLIC chat runtime — `sdk/v1` (bootstrap and session minting) and `rt/v1` (bot config,
 * conversation creation, history, citations, feedback).
 *
 * TYPES ONLY, ZERO RUNTIME VALUES: re-exported from `src/index.ts` under the <=1 kB brotli budget.
 * These shapes are read by hosted chat in `apps/web` and by `apps/widget`, which is exactly why they
 * belong in this package rather than in either app.
 *
 * ── `rt/v1` AND `sdk/v1` SIT OUTSIDE `api/v1`, AND THE PREFIX IS LOAD-BEARING ───────────────────
 * `api/*` is the admin group: Sanctum's cookie session plus `PreventRequestForgery`, built to REJECT
 * a bearer. The runtime group resolves exactly one credential — the opaque, origin-bound `kbw_`
 * chat-session token — and `config/cors.php` lists `sdk/*` explicitly, because Laravel's unpublished
 * default `paths` is `['api/*', 'sanctum/csrf-cookie']` and an SDK route mounted anywhere else gets
 * no CORS headers, fails the OPTIONS preflight, and leaves an empty server log.
 *
 * ── EVERY REJECTION ON `sdk/v1` IS THE SAME 404, WITH THE SAME BODY ────────────────────────────
 * Unknown bot id, a bot in another organization, a bot that is not live, an absent `Origin`,
 * `Origin: null`, an unlisted origin, `https://<allowed>.evil.com`, a valid bot from the wrong
 * origin — one status and one body. A client must NOT try to tell them apart, and must not render
 * copy that guesses which one happened.
 *
 * ── THE ORIGIN IS THE HEADER, AND IT IS THE ONE THING PAGE SCRIPT CANNOT FORGE ──────────────────
 * `BotDomainMatcher` compares the REQUEST's `Origin` header against the bot's ACTIVE `bot_domains`
 * rows, byte for byte (`hash_equals`), with no wildcard grammar and no substring form. The
 * consequence for hosted chat is concrete and is not a client bug when it bites: the hosted-chat
 * origin must itself be an active domain on the bot, or bootstrap 404s. See
 * `apps/web/src/features/chat/runtime-api.ts`, which states it where an operator will read it.
 */

/**
 * The public projection of a bot, as an anonymous visitor sees it.
 *
 * IT CARRIES NO CONFIGURATION: no provider connection, no model, no retrieval parameters, no limits,
 * and NOT THE INTERNAL ULID. `public_bot_id` identifies the bot and authorizes nothing — whether the
 * bot answers is the AND of its status, its access mode and the origin allow-list.
 */
export interface RuntimeBotResource {
  readonly public_bot_id: string;
  /** TENANT-AUTHORED TEXT. Escape at render, in every client. */
  readonly name: string;
  /** `null` means unset, never an empty string. */
  readonly description: string | null;
  /** Shown before the first question. `null` means the client renders its OWN default — a real state
   *  and not a missing one, so a client must have one. */
  readonly welcome_message: string | null;
  readonly placeholder_text: string | null;
  /**
   * The bot palette, from a closed key set the database enforces. `{}` when the tenant set none.
   *
   * IT IS NOT DELIVERED THROUGH THIS OBJECT ON HOSTED CHAT. The palette reaches the DOM as a
   * same-origin stylesheet response (`/c/{publicBotId}/theme.css`) so `style-src 'self'` covers it
   * with no nonce; a `style=""` attribute built from these values would be blocked by CSP in
   * production and would work in dev. Read `tailwind-shadcn`'s two-surface rule before using it.
   */
  readonly theme: Readonly<Record<string, unknown>>;
  /** True only when the bot collects end-user data AND has consent text to show. A flag with no text
   *  is not a consent record, so the two are reported as one fact. */
  readonly consent_required: boolean;
  /** Exactly what must be shown. Snapshotted onto the conversation at creation, so a later edit
   *  cannot rewrite what a visitor agreed to. */
  readonly consent_text: string | null;
  /** Suggested chips, in the order the operator set. TENANT-AUTHORED; escape at render. NEVER
   *  generated client-side from the corpus, which would leak what the corpus contains to anyone who
   *  can open the widget. */
  readonly starter_questions: readonly string[];
}

/**
 * A minted chat-session token.
 *
 * ── THIS BODY CONTAINS A LIVE CREDENTIAL ────────────────────────────────────────────────────────
 * Never log it, never put it in a URL or a fragment, never store it in `localStorage`,
 * `sessionStorage` or IndexedDB. It belongs in MEMORY and in an `Authorization` header. `apps/web`
 * holds it in component state for the life of the document and mints a new one on reload.
 */
export interface ChatSessionResource {
  /**
   * Opaque bearer, prefixed `kbw_`. It carries no readable claims — everything about the session is
   * server-side under a key this value derives. It grants exactly three abilities against exactly
   * one bot and can never carry an admin one: it is minted with NO HUMAN AUTHENTICATION AT ALL, so
   * anyone who can put the loader on an allow-listed page gets one.
   */
  readonly token: string;
  /**
   * Seconds from now. A DURATION and not an instant, because an absolute expiry would be compared
   * against a client clock that may be badly wrong.
   *
   * The TTL SLIDES on every authorized request, so this is the floor for an IDLE frame rather than a
   * promise about an active conversation — a client should not schedule a re-mint against it.
   */
  readonly expires_in: number;
}

/**
 * One conversation, as its own participant sees it.
 *
 * NEITHER PARTICIPANT COLUMN IS PUBLISHED: the session id is a component of a bearer credential, and
 * the user id would confirm an account exists.
 */
export interface RuntimeConversationResource {
  /** ULID. It addresses the conversation on the message and transcript routes and authorizes nothing
   *  on its own — every read re-checks that the calling session owns it. */
  readonly id: string;
  /** Only `active` accepts messages. `ended` and `expired` are refusals WITH A REASON rather than
   *  404s: the caller owns the row and is entitled to be told it is over. */
  readonly status: 'active' | 'ended' | 'expired';
  readonly locale: string | null;
  readonly consent_required: boolean;
  readonly consent_text: string | null;
  readonly consent_granted_at: string | null;
  readonly started_at: string | null;
  /** Moves when a message is SENT, not only when a turn settles, so an abandoned mid-answer
   *  conversation does not age as though nobody had touched it. */
  readonly last_activity_at: string | null;
}

/**
 * One transcript entry on the PUBLIC surface. Carries no provider call, no retry parent and no cost
 * data — those stop at Laravel.
 */
export interface RuntimeMessageResource {
  readonly id: string;
  readonly role: 'user' | 'assistant' | 'system';
  /** MODEL OUTPUT. Same sanitizer as everywhere else; never auto-load an image it names. `null` when
   *  the turn produced no text; never an empty string. */
  readonly content: string | null;
  readonly status: 'pending' | 'streaming' | 'complete' | 'failed' | 'cancelled';
  readonly created_at: string | null;
}

/**
 * The SETTLED entries of one conversation, oldest first.
 *
 * ENTRIES STILL IN FLIGHT ARE OMITTED — a `pending` row has no content and would render as a bubble
 * that never fills, because this endpoint is a POLL and not a stream. So a client that resumes a
 * document mid-answer sees the question and not the half-written answer, which is correct: the
 * answer is on the stream it just lost, and re-posting the same `client_message_id` replays the
 * persisted state rather than re-billing a generation.
 */
export interface RuntimeMessageCollectionResource {
  readonly messages: readonly RuntimeMessageResource[];
}

/** One footnote on the public surface. The same denormalized row conversation review reads, minus
 *  `created_at`. */
export interface RuntimeCitationResource {
  readonly label: string;
  /** `null` once the chunk has been purged. The footnote survives deliberately. */
  readonly chunk_id: string | null;
  /** The chunk's heading path, its URL, or a fixed fallback. NOT the source's name. */
  readonly title: string;
  /** Key set differs by source type; an absent locator is an absent KEY, never a null. */
  readonly location: Readonly<Record<string, unknown>>;
  /** UPLOADED OR CRAWLED CONTENT — hostile data permanently. Same sanitizer as model output. */
  readonly excerpt: string;
}

export interface RuntimeCitationCollectionResource {
  readonly citations: readonly RuntimeCitationResource[];
}
