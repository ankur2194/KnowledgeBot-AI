<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackRating;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The thumb, and what the person said about it (docs/11 §16.6, docs/04 §8.23).
 *
 * ── `$table` IS `feedback` AND THE DECLARATION IS NOT DECORATION ──────────────────────────────
 *
 * Eloquent pluralizes a class name to guess a table, and `Feedback` pluralizes to `feedbacks`. The
 * failure is a 42P01 on the first query, which is loud — but it would be loud in whichever surface
 * ships first rather than here, so the explicit `$table` is what keeps this file the place the name
 * is decided. Every model in this application declares `$table` for that reason; this one would
 * break without it.
 *
 * ── NO `#[ScopedBy]`: THERE IS NO COLUMN TO SCOPE ─────────────────────────────────────────────
 *
 * Same reason as `Message`, whose docblock carries it in full. kb-tenancy-isolation NN1 names
 * `citations/feedback -> messages -> conversations` as its example of the NOT NULL chain.
 * tests/Arch/ConversationDoctrineTest.php pins the exemption by name.
 *
 * ── `submitted_by_user_id` IS NOT A TENANT TERM, AND MISREADING IT IS THE BUG ─────────────────
 *
 * The organization comes from `messages -> conversations` and from nowhere else. This column names
 * WHO RATED, which is deliberately not required to be the conversation's participant: an
 * administrator reviewing a transcript may rate an answer a customer received, and that is a real
 * and useful action the schema does not refuse. Scoping a feedback query by
 * `submitted_by_user_id = <the current user>` therefore answers "what have I rated", never "what
 * belongs to my organization".
 *
 * ── ONE VERDICT PER PERSON PER ANSWER ─────────────────────────────────────────────────────────
 *
 * Two partial unique indexes, one per submitter column. A visitor who clicks thumbs-down and then
 * thumbs-up has CHANGED THEIR MIND — the write path updates the existing row rather than inserting
 * a second. Without that, §8.23's feedback rate is a count of clicks that any bored visitor can
 * move on their own.
 *
 * ── THE COMMENT IS UNTRUSTED IN BOTH DIRECTIONS ───────────────────────────────────────────────
 *
 * It arrives from an unauthenticated stranger through a widget on somebody else's page. Bounded at
 * 4,000 characters by a CHECK under the FormRequest, stored behind the conversation's retention
 * deadline, cascaded away with the message, and never written to `audit_logs`. Whatever renders it
 * escapes it, exactly as retrieved content is escaped (non-negotiable 7).
 *
 * @property string $id
 * @property string $message_id
 * @property FeedbackRating $rating
 * @property string|null $comment
 * @property string|null $submitted_by_user_id
 * @property string|null $submitted_by_session
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Feedback extends Model implements OrgOwned
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    use HasUlids;

    /** See the class docblock: `Feedback` pluralizes to `feedbacks` and the table is `feedback`. */
    protected $table = 'feedback';

    /**
     * TWO FIELDS, AND THE OMISSIONS ARE THE DESIGN.
     *
     * `rating` and `comment` are the only things the person actually supplies, and both are
     * validated by a FormRequest before they reach here — `rating` against the enum's two values,
     * `comment` against the 4,000-character bound the CHECK backstops.
     *
     * `message_id` is absent because it is the TENANT LINK on a table with no tenant column. A
     * caller who could set it could attach a rating to another organization's answer, and no scope
     * anywhere would object.
     *
     * `submitted_by_user_id` and `submitted_by_session` are absent because they are the IDENTITY.
     * One comes from the authenticated context and the other from the session the request already
     * carries; a caller that could set either could vote as somebody else — which, given the two
     * partial unique indexes, also means OVERWRITING somebody else's verdict.
     *
     * @var list<string>
     */
    protected $fillable = ['rating', 'comment'];

    /**
     * The organization, resolved through `messages -> conversations`. TWO HOPS, AND NOT THROUGH
     * `submitted_by_user_id` — see the class docblock for why that column is not a tenant term.
     *
     * THROWS RATHER THAN LAZY-LOADING — see `Message`'s docblock. Load with
     * `->with('message.conversation')`.
     */
    public function organizationId(): string
    {
        if (! $this->relationLoaded('message')) {
            throw new LogicException(
                'Feedback::organizationId() needs its message loaded: feedback has no '
                .'organization_id column and reaches its organization through '
                .'`feedback -> messages -> conversations`. Load it with '
                .'`->with(\'message.conversation\')` before authorizing. It does NOT come from '
                .'submitted_by_user_id: an administrator may rate an answer a customer received.',
            );
        }

        $message = $this->getRelation('message');

        // LOADED AND NULL IS A REAL, REACHABLE STATE. `messages` carries no scope of its own, but
        // `->with('message.conversation')` resolves the SECOND hop through `Conversation`'s
        // `#[ScopedBy]`, which fails closed — so an unbound or wrongly-bound TenantContext makes the
        // chain resolve to null rather than raise. `Message::organizationId()` carries the full
        // explanation and raises its own named exception for the second hop; this guards the first.
        // `assert()` would be compiled out in production and leave a return-type TypeError instead.
        if (! $message instanceof Message) {
            throw new LogicException(
                'Feedback::organizationId() loaded its message and got NULL. The chain is '
                .'`feedback -> messages -> conversations`, and the conversation at the end of it is org-scoped by a scope that '
                .'FAILS CLOSED — so an unbound or wrongly-bound TenantContext hides the parent '
                .'rather than raising. Bind the right organization (TenantContext::runFor) before '
                .'resolving.',
            );
        }

        return $message->organizationId();
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The authenticated rater, when there is one. Exactly one of this and `submitted_by_session` is
     * set on every row (`feedback_submitter_exclusive`).
     *
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => FeedbackRating::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
