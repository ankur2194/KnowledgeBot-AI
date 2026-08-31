<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thread (docs/04 §8.22, docs/11 §16.6). The root of the conversation graph and the only one of
 * its six models that holds `organization_id` directly.
 *
 * ── EVERY OTHER MODEL IN THIS GRAPH REACHES ITS ORGANIZATION THROUGH THIS ROW ─────────────────
 *
 * `messages`, `retrieval_traces`, `citations` and `feedback` have no `organization_id` column, by
 * design (kb-tenancy-isolation NN1's second shape, which names this exact chain). That makes THIS
 * row's `organization_id` the tenant fact for the whole transcript, and it is why the column is NOT
 * NULL, why the bot key is composite, and why `#[ScopedBy]` belongs here and cannot belong there.
 *
 * ── A TRANSCRIPT IS AN AUDIT RECORD ───────────────────────────────────────────────────────────
 *
 * `bots` is `ON DELETE RESTRICT` from here, which closes `BotService::delete()`'s `TODO(phase-e)`:
 * a bot that has held a conversation cannot be deleted, and `BotStatus::Archived` — whose own
 * docblock already says "the row survives so conversation history and audit entries resolve" — is
 * what an operator does instead. The migration carries the full argument against the two
 * alternatives, and the short form is that CASCADE makes the data loss invisible and SET NULL keeps
 * the transcript while losing what it was a transcript of.
 *
 * ── THERE IS NO `isAnswerable()` ON THIS MODEL ────────────────────────────────────────────────
 *
 * Whether a new turn may be taken is the AND of this row's status, the BOT's status and access
 * mode, the origin allow-list where the channel is `embedded`, and the organization's own state —
 * and four of those five live somewhere other than this row. `ConversationStatus::acceptsMessages()`
 * answers its own term and says so, exactly as `BotStatus::isRetrievable()` and
 * `SourceState::isRetrievable()` do for theirs. An accessor that answered the whole question from
 * here would be a check that looks complete and consults one term.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bot_id
 * @property string|null $user_id
 * @property string|null $anonymous_session_id
 * @property ConversationChannel $channel
 * @property ConversationStatus $status
 * @property string|null $locale
 * @property bool $consent_required
 * @property \Carbon\CarbonImmutable|null $consent_granted_at
 * @property string|null $consent_text_snapshot
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable $last_activity_at
 * @property \Carbon\CarbonImmutable|null $retention_expires_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class Conversation extends Model implements OrgOwned
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * `started_at` IS the creation time. §16.6 names it "Started time" and there is no separate
     * `created_at` column: two columns holding one fact is how they end up disagreeing, and the
     * one that would disagree is the one every retention calculation is measured from.
     *
     * `updated_at` is untouched and is NOT the same fact as `last_activity_at`. It moves when any
     * column changes — a status flip, a consent record; `last_activity_at` moves only when a turn is
     * taken, and the second is what the idle sweeper and the console's "last active" column read.
     */
    public const CREATED_AT = 'started_at';

    protected $table = 'conversations';

    /**
     * ONE FIELD, AND THE OMISSIONS ARE THE DESIGN.
     *
     * `organization_id` is absent for the reason every model here states: over-posting a tenant key
     * is an authorization bug with a 200 response, and `Model::shouldBeStrict()` turns the silent
     * drop into an exception rather than a shrug.
     *
     * `bot_id` is absent because it is RESOLVED, not declared. The hosted page, the widget bootstrap
     * and the mobile client all arrive carrying `public_bot_id` — an opaque token — and the server
     * resolves it to a bot row. A mass-assignable `bot_id` is a caller naming a bot by its INTERNAL
     * ULID, which is the admin surface's key, on the unauthenticated surface.
     *
     * `channel` is absent, and this is the omission most likely to be argued with. It is not a
     * preference the client states — it is a property of the SURFACE the request arrived on, and the
     * route group knows it. A client-settable channel means a widget can label itself `playground`,
     * which corrupts every per-channel number in §8.23 and is unfalsifiable after the fact.
     *
     * `user_id` and `anonymous_session_id` are absent because they are the IDENTITY. One comes from
     * the authenticated context and the other is minted here; a caller that could set either could
     * append to somebody else's thread.
     *
     * `status` is absent because it is a LIFECYCLE STATE. Every legal move is in
     * `ConversationStatus::transitionTable()`, and a mass-assignable status is a request that ends a
     * conversation — or reopens an expired one, over a hole the retention sweeper has already made.
     *
     * `started_at`, `last_activity_at` and `retention_expires_at` are absent because they are the
     * CLOCK. `retention_expires_at` in particular is resolved from the bot's and organization's
     * retention policy at creation; a client that could set it could opt out of retention.
     *
     * The three consent columns are absent because consent is a RECORD OF WHAT WE SHOWED SOMEBODY.
     * A caller that could post `consent_text_snapshot` could assert agreement to a text nobody was
     * ever shown, which is the one claim on this row that exists to be defensible.
     *
     * @var list<string>
     */
    protected $fillable = ['locale'];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The bot that answered. NOT NULL and `ON DELETE RESTRICT` — see the class docblock.
     *
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * The authenticated participant, when there is one. Exactly one of this and
     * `anonymous_session_id` is set on every row (`conversations_participant_exclusive`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The turns, in transcript order.
     *
     * `Model::shouldBeStrict()` forbids lazy loading, so this relation has to exist before
     * `with('messages')` can be written at all. It carries the same total order the index
     * `messages_conversation_created` provides, and for the same reason: two messages can land in
     * the same microsecond, and without the `id` tie-break a second read may legally return the
     * question after the answer.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Every provider attempt made for this thread, across every turn.
     *
     * NULLABLE ON THE OTHER SIDE, WHICH IS WHY THIS RELATION IS NOT A COMPLETE BILLING VIEW.
     * `provider_calls.conversation_id` is `ON DELETE SET NULL`, because retention removes CONTENT
     * and not COST — so a call whose conversation has been swept still exists and is no longer
     * reachable from here. Read this for the diagnostics panel; read `provider_calls` scoped by
     * `organization_id` for anything that has to add up to an invoice.
     *
     * @return HasMany<ProviderCall, $this>
     */
    public function providerCalls(): HasMany
    {
        return $this->hasMany(ProviderCall::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // ENUM CASTS AND NOT STRINGS, so a value the database would accept but this application
            // has no meaning for cannot be read back silently — and so that every transition
            // question goes through ConversationStatus::canTransitionTo() rather than through a
            // string comparison somebody wrote at a call site.
            'channel' => ConversationChannel::class,
            'status' => ConversationStatus::class,
            'consent_required' => 'boolean',
            'consent_granted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'retention_expires_at' => 'immutable_datetime',
        ];
    }
}
