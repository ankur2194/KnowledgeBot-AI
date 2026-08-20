<?php

declare(strict_types=1);

namespace App\Services\Bots;

use App\Enums\BotDomainStatus;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Organization;
use App\Repositories\Contracts\BotDomainRepositoryInterface;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bot's widget origin allow-list: list, add, promote or withdraw, remove.
 *
 * ── WHAT A ROW HERE DECIDES, AND THEREFORE WHY EVERY WRITE IS AUDITED PER ROW ─────────────────
 *
 * An entry is what lets a page on the public internet boot a chat widget that speaks with this
 * organization's credential, on this organization's corpus, against this organization's quota. It
 * is a SECURITY CONTROL and not a preference, and §18.11 requires destructive operations and
 * config changes audited — but the reason these particular rows exist is narrower and is a named
 * finding rather than a general principle:
 *
 *   FINDING L2 (`docs/22` § *The security read of the bots surface*). Deleting a bot destroys its
 *   allow-list with NO RECORD OF WHAT IT PERMITTED, contradicting the reason `bot_domains` gives
 *   for its own ON DELETE RESTRICT — that a security review may later need to reconstruct it. The
 *   finding was latent only because no route created a domain. These endpoints make it live, and
 *   this is the sequencing where it gets forgotten, because the bot delete path is already written
 *   and already green.
 *
 * The closure is two things together and NEITHER IS SUFFICIENT ALONE: `bot.domain.created`,
 * `bot.domain.status_changed` and `bot.domain.deleted` carry one origin each, verbatim, with the
 * actor and the time, and they outlive the bot; and every `bot.*` row carries a scalar summary of
 * the collections so a reader landing on `bot.deleted` KNOWS TO GO LOOKING for them.
 *
 * ── NOTHING HERE TOUCHES A CREDENTIAL, AND NOTHING HERE CAN ───────────────────────────────────
 *
 * This class does not import `App\Support\Crypto\CredentialVault` and no method it calls reaches
 * one. An allow-list entry holds an origin and a status; the credential a widget booted from it
 * would eventually spend lives on a `provider_connections` row two hops away and is decrypted only
 * by `InternalAiClient`.
 *
 * ── EVERY AUDIT ROW IS WRITTEN INSIDE THE REPOSITORY'S TRANSACTION ────────────────────────────
 *
 * All three `bot.domain.*` operations are ON_FAILURE_ABORT, so a failed audit write must roll the
 * change back. `AuditLogger` opens no transaction of its own and `DB` is arch-pinned to
 * `App\Repositories\Eloquent`, so each mutating repository method takes the audit call as a
 * REQUIRED closure and invokes it inside its own transaction. The closures below are what land
 * there.
 */
final readonly class BotDomainService
{
    /**
     * How many origins one bot may list.
     *
     * ── A BOUND RATHER THAN A DESIGN CONSTRAINT, AND IT IS DELIBERATELY GENEROUS ──────────────
     *
     * There is no wildcard grammar, so a customer embedding on an apex, a `www`, a staging host and
     * a handful of country domains needs one row each — a tight cap would make a legitimate
     * deployment unexpressible and push somebody towards asking for wildcards, which is the one
     * thing this list must never grow. Fifty is far above every real deployment and still bounds
     * two things that matter: the list is read on every widget bootstrap, and it is summarised into
     * an append-only audit row whose echoed fields are truncated at 512 characters.
     */
    public const MAX_PER_BOT = 50;

    /** SQLSTATE 23505 — unique_violation. */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private BotDomainRepositoryInterface $domains,
        private AuditLogger $audit,
    ) {}

    /**
     * Every entry on this bot's allow-list, pending and disabled ones included.
     *
     * NO AUDIT ROW. §18.11 audits credential changes, config changes and destructive operations;
     * reading a list is none of them, and auditing it would bury the rows that matter under one per
     * page load — the same call `BotService::list()` and `ProviderModelService::list()` make.
     *
     * @return list<BotDomain>
     */
    public function list(Organization $organization, Bot $bot): array
    {
        return $this->domains->forBot($organization->organizationId(), $bot->id);
    }

    /**
     * Add one origin, always `pending`.
     *
     * ── THE DUPLICATE IS A 422 AND NOT A 500, IN TWO LAYERS ───────────────────────────────────
     *
     * `bot_domains_org_bot_origin` is UNIQUE on (organization, bot, origin), and a second row for
     * the same origin would otherwise surface as SQLSTATE 23505 rendered by the error envelope as
     * `internal_dependency` / 500 — a bug report about the server for what is plainly a bad
     * request. The PRE-FLIGHT check is an org-scoped repository query for the readable message; the
     * CATCH is the race it cannot win, two administrators pasting the same origin in the same
     * instant. The index is the authority and the check is the good message, which is the same
     * construction `BotService::create()` uses for the slug.
     *
     * THE ORIGIN IS ALREADY NORMALISED when it reaches here — `StoreBotDomainRequest::toOrigin()`
     * returns the RFC 6454 serialisation — which is what makes the duplicate check meaningful at
     * all: without it `https://Example.com/` and `https://example.com` would be two rows granting
     * one thing, and the unique index could not object because they are different strings.
     *
     * @throws ValidationException 422 for a duplicate origin or a full allow-list
     */
    public function add(
        Organization $organization,
        Bot $bot,
        string $origin,
        ?string $actorId = null,
        ?Request $request = null,
    ): BotDomain {
        $organizationId = $organization->organizationId();

        if ($this->domains->originExists($organizationId, $bot->id, $origin)) {
            throw $this->duplicateOrigin($origin);
        }

        if ($this->domains->countForBot($organizationId, $bot->id) >= self::MAX_PER_BOT) {
            throw ValidationException::withMessages([
                'origin' => 'This bot already lists the maximum of '.self::MAX_PER_BOT.' origins. '
                    .'Remove one you no longer embed on before adding another. The cap is a bound '
                    .'on a list that is read on every widget bootstrap and summarised into an '
                    .'append-only audit row — it is not a hint to use a wildcard, which this '
                    .'allow-list has no grammar for and never will.',
            ]);
        }

        try {
            return $this->domains->create(
                $organizationId,
                $bot->id,
                $origin,
                // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(...)` implicitly
                // RETURNS the call's value, `record()` is `void`, and the interface types the
                // callback as `Closure(BotDomain): void`.
                function (BotDomain $row) use ($organizationId, $actorId, $request): void {
                    $this->record(
                        AuditLogger::BOT_DOMAIN_CREATED,
                        $organizationId,
                        $actorId,
                        $row,
                        $request,
                    );
                },
            );
        } catch (QueryException $conflict) {
            if ($this->violates($conflict, 'bot_domains_org_bot_origin')) {
                throw $this->duplicateOrigin($origin);
            }

            throw $conflict;
        }
    }

    /**
     * Promote, withdraw or re-enable one entry.
     *
     * ── A NO-OP IS REFUSED, FOR THE REASON EVERY OTHER WRITE ON THIS SURFACE REFUSES ONE ──────
     *
     * Setting `active` on an already-active row changes nothing and would still write a
     * `bot.domain.status_changed` row claiming a promotion happened — a trail that lies about the
     * one class of event it exists to record. A 422 keyed on `status`, because there is a field to
     * key it on.
     *
     * ── THIS METHOD DOES NOT VERIFY ANYTHING, AND THE GAP IS NAMED RATHER THAN HIDDEN ─────────
     *
     * `UpdateBotDomainRequest` carries the argument in full. The short form: `App\Models\BotDomain`
     * keeps `status` out of `$fillable` so that the request which ENTERS an origin cannot also mark
     * it usable, and that stays true — this is a separate, deliberate, separately-audited action.
     * What nobody has proved is that the operator controls the origin. An automated proof (a DNS
     * TXT record, a well-known path) is what would make `active` mean "verified" rather than
     * "confirmed by an administrator of this organization", and it does not exist yet. What bounds
     * the gap today is that no public runtime surface reads this table.
     *
     * @throws ValidationException 422 when the entry already holds this status
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function changeStatus(
        Organization $organization,
        Bot $bot,
        BotDomain $domain,
        BotDomainStatus $status,
        ?string $actorId = null,
        ?Request $request = null,
    ): BotDomain {
        if ($domain->status === $status) {
            throw ValidationException::withMessages([
                'status' => 'This origin is already `'.$status->value.'`. A change that changes '
                    .'nothing would still write a `bot.domain.status_changed` audit row claiming a '
                    .'transition happened, on the one table whose trail exists to say exactly when '
                    .'an origin started and stopped granting an embed.',
            ]);
        }

        $organizationId = $organization->organizationId();

        $updated = $this->domains->changeStatus(
            $organizationId,
            $bot->id,
            $domain->id,
            $status,
            function (BotDomain $row, BotDomainStatus $previous) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::BOT_DOMAIN_STATUS_CHANGED,
                    $organizationId,
                    $actorId,
                    $row,
                    $request,
                    $previous,
                );
            },
        );

        if ($updated === null) {
            // Deleted between the route binding and the transaction. The same 404 the binding would
            // have produced, not a 500 describing a race the caller cannot act on.
            throw new NotFoundHttpException;
        }

        return $updated;
    }

    /**
     * Remove one entry.
     *
     * A HARD DELETE, and the audit row is the only thing that survives it — which is what makes
     * `origin` and `status` load-bearing on `bot.domain.deleted` rather than decorative:
     * `subject_id` resolves to nothing afterwards.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor removed a grant the
     * trail does not record them removing. The same call `BotService::delete()` makes.
     *
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function remove(
        Organization $organization,
        Bot $bot,
        BotDomain $domain,
        ?string $actorId = null,
        ?Request $request = null,
    ): void {
        $organizationId = $organization->organizationId();

        $deleted = $this->domains->delete(
            $organizationId,
            $bot->id,
            $domain->id,
            function (BotDomain $row) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::BOT_DOMAIN_DELETED,
                    $organizationId,
                    $actorId,
                    $row,
                    $request,
                );
            },
        );

        if (! $deleted) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * One audit row describing one allow-list entry.
     *
     * THE DETAILS ARE BUILT FROM THE PERSISTED ROW, NEVER FROM REQUEST INPUT, which is what makes
     * "nothing credential-shaped can appear here" a property of the code rather than of the
     * caller's discipline. A `BotDomain` has nothing to offer but an origin, a status and two
     * foreign keys.
     *
     * `bot_id` IS RECORDED EVEN THOUGH IT IS IN THE URL, and it is the field that makes these rows
     * useful after the fact: `subject_id` is the entry's own ULID, and once the bot is hard-deleted
     * it resolves to nothing. Without this the trail can say an origin was granted and cannot say
     * which bot it was granted for — which is most of what finding L2 asks.
     *
     * `organization_id` is NOT in `details`: it is a column on the audit row itself, and echoing it
     * into the payload would be a second copy that a query could disagree with.
     */
    private function record(
        string $operation,
        string $organizationId,
        ?string $actorId,
        BotDomain $domain,
        ?Request $request,
        ?BotDomainStatus $previous = null,
    ): void {
        $details = [
            'bot_id' => $domain->bot_id,
            // TENANT-CONTROLLED FREE TEXT AND THE SECURITY FACT ITSELF. Bounded by
            // MAX_VALUE_LENGTH and passing the shape backstop like any other echoed string — see
            // AuditLogger::BOT_DOMAIN_CREATED for the one legal-but-vendor-key-shaped host that
            // degrades to a fingerprint, and why that is the designed outcome rather than a bug.
            'origin' => $domain->origin,
            'status' => $domain->status->value,
        ];

        if ($previous !== null) {
            // ONLY ON THE TRANSITION ROW. A null is skipped by the sanitizer without being
            // reported, so passing it unconditionally would also work — it is omitted explicitly
            // because "this operation has no previous status" is a statement about the operation
            // rather than about a value that happened to be absent.
            $details['previous_status'] = $previous->value;
        }

        $this->audit->record(
            $operation,
            organizationId: $organizationId,
            actorId: $actorId,
            details: $details,
            subjectType: BotDomain::class,
            subjectId: $domain->id,
            request: $request,
        );
    }

    /**
     * The 422 a duplicate origin produces, keyed on the field the form renders.
     */
    private function duplicateOrigin(string $origin): ValidationException
    {
        return ValidationException::withMessages([
            'origin' => 'This bot already lists "'.$origin.'". An allow-list is a SET — the same '
                .'origin twice is not two grants, and a second row carrying a different status '
                .'would make "is this origin allowed" ambiguous in a way no reader of the console '
                .'could resolve. Note that what is stored is the normalised form: `https://Example.com/` '
                .'and `https://example.com` are the same origin and only one row can hold it. '
                .'Another bot, or another organization, listing the same origin is not a conflict '
                .'and does not reach this message.',
        ]);
    }

    private function violates(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === self::UNIQUE_VIOLATION
            && str_contains($exception->getMessage(), $constraint);
    }
}
