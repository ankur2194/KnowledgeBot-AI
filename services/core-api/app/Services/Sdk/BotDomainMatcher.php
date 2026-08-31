<?php

declare(strict_types=1);

namespace App\Services\Sdk;

use App\Enums\BotDomainStatus;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Support\Tenancy\TenantContext;

/**
 * Is this browser `Origin` on this bot's allow-list, right now?
 *
 * ═══ BYTE EQUALITY, AND THERE IS NO SAFE SUBSTRING FORM OF THIS CHECK ═══════════════════════
 *
 * OWASP calls `origin.indexOf('.ourdomain.com') != -1` *"very insecure"*, and the whole family fails
 * the same way: `startsWith` admits `https://allowed.example.evil.com`, `endsWith` admits
 * `https://evilallowed.example`, and an unanchored regex admits both. `ExactOrigin` has already
 * normalised the stored value to the RFC 6454 serialisation the browser sends — lower-cased, no
 * trailing slash, default port dropped — so `hash_equals` on the full string is both correct and
 * sufficient.
 *
 * `hash_equals` rather than `===` is not a timing claim about origins, which are not secrets: it is
 * the one comparison idiom used for every allow-list decision in this service, so no call site has
 * to decide per-value whether constant time matters.
 *
 * ═══ THERE IS NO WILDCARD GRAMMAR AND THERE WILL NOT BE ═════════════════════════════════════
 *
 * `ExactOrigin::parse()` refuses `*` at the point of storage with a paragraph explaining that
 * `*.example.com` reads as "our sites" and means every host anybody can obtain a certificate for
 * under example.com — a customer subdomain, a status page, a marketing CMS, or one stale DNS record
 * pointing at an abandoned bucket. This class is the read side of that decision and adds no matcher.
 *
 * ═══ `pending` AND `disabled` GRANT NOTHING ═════════════════════════════════════════════════
 *
 * A row starts as `pending` and is activated deliberately, so a domain typed into the console does
 * not become an embedding grant until somebody confirms it — and `BotDomainStatus::permitsEmbedding()`
 * is the single place that decision is spelled. The status is matched POSITIVELY: a row whose status
 * is a value nobody has thought about yet grants nothing, where a `!== disabled` test would grant
 * everything new.
 */
final readonly class BotDomainMatcher
{
    /**
     * THE TENANT CONTEXT IS BOUND HERE, FROM THE BOT, AND WITHOUT IT THIS ALWAYS ANSWERS `false`.
     *
     * `BotDomain` carries `#[ScopedBy(OrganizationScope::class)]`, which FAILS CLOSED: with nothing
     * bound it appends `whereRaw('1 = 0')` and the read returns no rows. Both callers reach this
     * method on a surface where no context exists yet — the SDK bootstrap has none by construction,
     * and `ResolveChatSession` calls `resolve()` BEFORE it binds one — so an unbound read here is
     * not a leak, it is a control that silently denies every legitimate origin. The symptom is a 404
     * on a correctly configured widget, which reads as a configuration problem on the customer's
     * side and is not.
     *
     * Binding it from `$bot->organization_id` is a RESTATEMENT of the explicit predicate one line
     * below, not a widening: the organization comes out of a row the caller already resolved, never
     * from request input, and `runFor()` restores whatever was bound before.
     */
    public function __construct(private TenantContext $tenancy) {}

    /**
     * @param  string|null  $origin  the request's `Origin` HEADER and never a body or query field.
     *                               Null — an absent header — is a REJECTION and never a
     *                               default-allow: it is the one host-page fact page script cannot
     *                               forge, so its absence means the claim was never made.
     */
    public function matches(Bot $bot, ?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        // `Origin: null` is a real header value that a sandboxed iframe, a `data:` document and a
        // cross-origin redirect all send, and it is the string `"null"` rather than an absent
        // header. Allow-listing it allow-lists every opaque context on the internet
        // (`kb-security-baseline` control 4). It cannot be stored by `ExactOrigin` either, so this is
        // belt on brace — and it is spelled out because the failure would be invisible.
        if ($origin === 'null') {
            return false;
        }

        return $this->tenancy->runFor((string) $bot->organization_id, function () use ($bot, $origin): bool {
            $rows = BotDomain::query()
                ->where('organization_id', '=', $bot->organization_id)
                ->where('bot_id', '=', $bot->id)
                // POSITIVE, from the enum rather than from a literal — see the class docblock.
                ->where('status', '=', BotDomainStatus::Active->value)
                ->get(['origin']);

            foreach ($rows as $row) {
                if (hash_equals((string) $row->origin, $origin)) {
                    return true;
                }
            }

            return false;
        });
    }
}
