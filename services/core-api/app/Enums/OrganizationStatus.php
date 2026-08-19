<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A suspended organization still OWNS its rows — suspension is not deletion, which is a different
 * lifecycle entirely (kb-deletion-and-verification).
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * The one sentence an operator gets when check 5 refuses a write because the organization is
     * suspended.
     *
     * ── WHY THE SENTENCE HAS TO EXIST AT ALL ───────────────────────────────────────────────────
     *
     * `abort_unless($organization->status === OrganizationStatus::Active, 409)` with no third
     * argument produces an empty `message`, and bootstrap/app.php's render closure maps an
     * unclassified 4xx onto `internal_dependency` — so the client falls back to that class's
     * mapped copy, "Something on our side is unavailable. Try again shortly." That is false twice:
     * nothing is unavailable, and retrying never works while the organization is suspended. This
     * is the same defect docs/19 ADR-042 records as plan D36; on the invitation surface it was
     * fixed by not emitting a 409 at all, and here it cannot be, because the refusal is about the
     * state of the ORGANIZATION and not about a field of the submitted body — there is nothing to
     * key a 422 map on. The render closure preserves an unclassified 4xx's own status AND its own
     * message (`default => $e->getMessage()`), so a sentence supplied here reaches `message`
     * verbatim and apps/web's `deleteConflictMessage()` renders it with no client change.
     *
     * ── WHY IT LIVES ON THE ENUM ───────────────────────────────────────────────────────────────
     *
     * Eight call sites across four controllers raise it, and they must not drift into eight
     * spellings of one rule — the same argument ProviderConnectionService::DESIGNATED_FOR_EMBEDDING
     * makes for its two. No single service owns all eight, and every one of the four controllers
     * already imports this enum to make the comparison, so the constant sits on the type the check
     * is ABOUT and costs no new import.
     *
     * ── WHY IT MAY SAY "SUSPENDED" WHEN THE CHECK IS `!== Active` ──────────────────────────────
     *
     * This enum has exactly two cases, so `not Active` and `Suspended` are the same set. A third
     * case would make this sentence a lie, which is why the constant is here rather than in a
     * support class: the case list and the copy that describes it are one file apart from nothing.
     *
     * IT NAMES THE REMEDY, and the remedy is honestly "not from here". Suspension is set and
     * cleared by the platform operator — ADR-042 records that there is no self-service path — so
     * telling the caller to retry, or to change something in this console, would be advice that
     * cannot work.
     */
    public const SUSPENDED_REFUSAL = 'This organization is suspended, so its configuration is '
        .'read-only. Nothing on our side is unavailable and retrying will not help: reads on this '
        .'surface still work, nothing already stored has been changed, and every write resumes '
        .'exactly as it was the moment the suspension is lifted. A suspension is set and cleared '
        .'by whoever operates this KnowledgeBot deployment, not from this console — contact them '
        .'to have it lifted.';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
