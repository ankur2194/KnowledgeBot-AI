<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One turn (docs/11 §16.6).
 *
 * ═══ NO `#[ScopedBy(OrganizationScope::class)]`, AND THE REASON IS NOT ADR-043's ═════════════
 *
 * ADR-043 records two models that hold `organization_id` and deliberately carry no attribute,
 * because `OrganizationScope` FAILS CLOSED and the guest paths that read them run before any
 * organization is known. THIS MODEL IS EXEMPT FOR A DIFFERENT AND HARDER REASON: there is no column
 * to scope. `messages` has no `organization_id`, so `OrganizationScope::apply()` would append
 * `where messages.organization_id = ?` against a column PostgreSQL does not have — SQLSTATE 42703,
 * on every read, from every surface. It is not a weaker guarantee; it is a syntax error.
 *
 * WHY THE COLUMN IS ABSENT IS THE ARGUMENT WORTH KEEPING. kb-tenancy-isolation NN1 admits two
 * shapes and names this exact chain — `citations/feedback -> messages -> conversations` — as an
 * example of the second: a NOT NULL foreign-key chain no code path can bypass. `conversation_id` is
 * NOT NULL with a real key, and `conversations.organization_id` is NOT NULL with a real key, so
 * every message reaches exactly one organization and no insert can produce one that does not.
 * Denormalizing a copy onto this table would buy an org-leading index and cost the one thing the
 * chain guarantees: a copy can DISAGREE with its parent, and a message whose copy says Org A while
 * its conversation says Org B passes an org-scoped query in one organization and renders in the
 * other's transcript.
 *
 * SO WHAT ENFORCES ISOLATION HERE, since the backstop is unavailable: every read goes through a
 * repository method taking `organization_id` as a REQUIRED POSITIONAL ARGUMENT and joining
 * `conversations`, which is the same substitute `OrganizationUser`, `OrganizationInvitation` and
 * `EmailVerificationToken` rely on. tests/Arch/ConversationDoctrineTest.php asserts the exemption
 * BY NAME with this reason attached, so a future model cannot inherit it by accident — which is the
 * annotated exception list ADR-043 says must exist before a fourth model claims the exemption.
 *
 * ═══ `organizationId()` NEEDS THE CHAIN LOADED, AND SAYS SO RATHER THAN LAZY-LOADING ════════
 *
 * `OrgOwned::organizationId()` is declared non-nullable because `OrgScopedPolicy::permit()` resolves
 * membership of THE RECORD'S organization and there is no membership of no organization. This model
 * can satisfy that only by reading its parent — and `Model::shouldBeStrict()` forbids lazy loading,
 * so reaching for `$this->conversation` on an unloaded relation would raise
 * `LazyLoadingViolationException` from inside a policy, which reads as a framework bug rather than
 * as the missing `with('conversation')` it is. The guard below raises first, with the fix in the
 * message. The same shape is used by `RetrievalTrace`, `Citation` and `Feedback`, whose chain is two
 * hops (`message.conversation`) rather than one.
 *
 * ═══ THERE IS NO `isSettled()` ACCESSOR THAT CONSULTS THE PROVIDER CALL ═════════════════════
 *
 * Whether an answer is finished is `MessageStatus::isSettled()` and nothing else. Reading the
 * provider call's status here would produce a second, disagreeing answer — one row is one ATTEMPT
 * and a fallback turn has two of them, so "the provider call succeeded" and "this message is
 * complete" are different facts with different producers.
 *
 * @property string $id
 * @property string $conversation_id
 * @property MessageRole $role
 * @property string|null $content
 * @property MessageStatus $status
 * @property string|null $client_message_id the CLIENT-MINTED ULID on a user turn, and null on
 *                                          every other role — `messages_client_message_id_only_on_user` refuses the alternative.
 *                                          It is the durable half of `chat.message`'s idempotency, under
 *                                          `messages_conversation_client_message_unique`, so a double submit collapses into one
 *                                          turn instead of a second provider bill.
 * @property string|null $parent_message_id
 * @property string|null $provider_call_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Message extends Model implements OrgOwned
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'messages';

    /**
     * TWO FIELDS, AND THE OMISSIONS ARE THE DESIGN.
     *
     * `conversation_id` is absent because it is the TENANT LINK on a table with no tenant column.
     * There is no `organization_id` here to over-post, so this is the column that plays its part:
     * a caller who could set it could append a turn to another organization's transcript, and no
     * scope anywhere would object because the row would have told it where it belonged.
     *
     * `status` is absent because it is a LIFECYCLE STATE. `MessageStatus::transitionTable()` holds
     * every legal move, and a mass-assignable status is a request that marks a pending answer
     * `complete` — which settles a turn nothing produced and stops the reaper looking at it.
     *
     * `parent_message_id` is absent because a retry chain is a claim about what happened. A caller
     * that could set it could graft one answer onto another turn's history.
     *
     * `provider_call_id` is absent because it is an ATTRIBUTION OF COST, and an attribution a client
     * can set is not one.
     *
     * @var list<string>
     */
    protected $fillable = ['role', 'content'];

    /**
     * The organization, resolved through `conversations`.
     *
     * THROWS RATHER THAN LAZY-LOADING when the relation is not loaded. See the class docblock: the
     * alternative is a `LazyLoadingViolationException` raised from inside a policy, whose message
     * names the framework rather than the missing eager load.
     */
    public function organizationId(): string
    {
        if (! $this->relationLoaded('conversation')) {
            throw new LogicException(
                'Message::organizationId() needs its conversation loaded: a message has no '
                .'organization_id column and reaches its organization through '
                .'`messages -> conversations`. Load it with `->with(\'conversation\')` before '
                .'authorizing. Reaching for the relation here would be a lazy load, which '
                .'Model::shouldBeStrict() forbids — and the exception it raises names the framework '
                .'rather than this call site.',
            );
        }

        $conversation = $this->getRelation('conversation');

        // LOADED AND NULL IS A REAL, REACHABLE STATE, AND IT IS THE ONE THAT BITES.
        // `Conversation` carries `#[ScopedBy(OrganizationScope::class)]`, and that scope FAILS
        // CLOSED — with no bound TenantContext it appends `whereRaw('1 = 0')`. So
        // `->with('conversation')` outside a request (a queue worker whose context was never set, a
        // console command, a test) resolves the relation to NULL while `relationLoaded()` reports
        // true. The same happens inside a request bound to a DIFFERENT organization, which is the
        // security-relevant half: the parent this message needs is deliberately invisible.
        //
        // `assert()` WAS THE FIRST SPELLING HERE AND IT WAS WRONG. Assertions are compiled out in
        // production (`zend.assertions=-1`), so the null would have fallen through to a return-type
        // TypeError with no sentence in it — from inside a policy, on a path that had already
        // decided the caller was allowed to be there.
        if (! $conversation instanceof Conversation) {
            throw new LogicException(
                'Message::organizationId() loaded its conversation and got NULL. That is not a '
                .'missing row: Conversation carries #[ScopedBy(OrganizationScope::class)], which '
                .'FAILS CLOSED — with no bound TenantContext it filters everything out, and with a '
                .'context bound to a DIFFERENT organization it correctly hides the parent. Bind the '
                .'right organization (TenantContext::runFor) before resolving, or read the '
                .'conversation through a repository method that takes organization_id explicitly.',
            );
        }

        return $conversation->organization_id;
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The turn this one is a RETRY of, when it is one.
     *
     * Guarded by the composite key `messages_parent_same_conversation`, so a retry can only name a
     * message in its own conversation — which matters because a conversation is what carries the
     * organization, and a simple key would have let a retry point across the tenant boundary.
     *
     * @return BelongsTo<Message, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    /**
     * The retries OF this turn. Plural: a turn may be retried more than once.
     *
     * @return HasMany<Message, $this>
     */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'parent_message_id');
    }

    /**
     * The attempt that SETTLED this answer, when one did.
     *
     * NOT "the attempts behind this turn" — that is `providerCalls()` below, and the two are
     * different questions. A fallback turn makes two attempts and this column names the one that
     * produced the text.
     *
     * @return BelongsTo<ProviderCall, $this>
     */
    public function providerCall(): BelongsTo
    {
        return $this->belongsTo(ProviderCall::class, 'provider_call_id');
    }

    /**
     * EVERY attempt made for this turn, including the ones that failed and were fallen back from.
     *
     * @return HasMany<ProviderCall, $this>
     */
    public function providerCalls(): HasMany
    {
        return $this->hasMany(ProviderCall::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * What retrieval did for this answer. At most one — `retrieval_traces_message_unique`.
     *
     * `HasOne` rather than `HasMany`, and the unique index is what makes that honest: two traces for
     * one answer would be two explanations with nothing saying which is real. A RETRY produces a new
     * message, and that message gets its own trace.
     *
     * @return HasOne<RetrievalTrace, $this>
     */
    public function retrievalTrace(): HasOne
    {
        return $this->hasOne(RetrievalTrace::class);
    }

    /**
     * The footnotes, in label order.
     *
     * @return HasMany<Citation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class)->orderBy('label');
    }

    /**
     * The thumbs. At most one per person, by two partial unique indexes.
     *
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'status' => MessageStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
