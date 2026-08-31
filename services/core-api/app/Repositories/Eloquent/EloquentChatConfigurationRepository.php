<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Bot;
use App\Models\BotFallbackEntry;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Services\Chat\ChatConnection;
use App\Services\Rerank\RerankDesignation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class EloquentChatConfigurationRepository implements ChatConfigurationRepositoryInterface
{
    /**
     * The tenant context is INJECTED rather than reached for with `app()`, because this class is the
     * one place in the chat path that binds it from a row instead of receiving it bound — and a
     * container lookup buried inside a method is exactly the kind of dependency a reader has to go
     * find. It is a singleton, so this is the same instance `OrganizationScope` reads.
     */
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * Run one read with the tenant context bound to the organization the caller named.
     *
     * ═══ WHY EVERY METHOD HERE GOES THROUGH IT ══════════════════════════════════════════════
     *
     * Every model this repository reads carries `#[ScopedBy(OrganizationScope::class)]`, which FAILS
     * CLOSED — with nothing bound it appends `whereRaw('1 = 0')`. And both public surfaces reach this
     * class BEFORE a context exists: the SDK bootstrap has none by construction (discovering the
     * organization is the point), and `ResolveChatSession` calls `WidgetSessionService::resolve()` —
     * which calls `botForOrg()` — before it binds one.
     *
     * So without this the global scope does not fail SAFE, it fails ALWAYS: every read answers null,
     * every mint 404s, and a correctly configured widget looks broken on the customer's side.
     *
     * ═══ IT IS A RESTATEMENT, NOT A WIDENING ════════════════════════════════════════════════
     *
     * `$organizationId` is the scope, passed positionally by every caller, and every query below ALSO
     * carries it as an explicit predicate. Binding it makes the backstop agree with the mechanism
     * instead of fighting it. `runFor()` restores whatever was bound before, so a caller that DID
     * have a context keeps it.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function scoped(string $organizationId, callable $read): mixed
    {
        return $this->tenancy->runFor($organizationId, $read);
    }

    public function botForOrg(string $organizationId, string $botId): ?Bot
    {
        return $this->scoped($organizationId, fn (): ?Bot => Bot::query()
            ->where('organization_id', '=', $organizationId)
            ->whereKey($botId)
            ->first());
    }

    public function botByPublicId(string $publicBotId): ?Bot
    {
        // TWO STEPS, AND THE SPLIT IS THE WHOLE DESIGN. `organizationOwning()` reads ONE column with
        // no tenant predicate, because on this surface there is no organization yet and discovering
        // it is the point; everything after it is ordinary tenant-scoped code, running through the
        // global scope, inside a context bound from the row rather than from request input. The
        // interface docblock carries the argument for why the identifier is safe to look up
        // unscoped.
        $organizationId = $this->organizationOwning($publicBotId);

        if ($organizationId === null) {
            return null;
        }

        return $this->tenancy->runFor($organizationId, fn (): ?Bot => Bot::query()
            ->where('organization_id', '=', $organizationId)
            ->where('public_bot_id', '=', $publicBotId)
            ->first());
    }

    public function organization(string $organizationId): ?Organization
    {
        // `Organization` itself carries no organization scope — it IS the organization — so this one
        // needs no binding. It is written through `scoped()` anyway so every method in this class
        // reads the same, and so a future column or relation that DOES need one is already inside it.
        return $this->scoped($organizationId, fn (): ?Organization => Organization::query()
            ->whereKey($organizationId)
            ->first());
    }

    public function chatConnection(string $organizationId, Bot $bot): ?ChatConnection
    {
        return $this->scoped($organizationId, function () use ($organizationId, $bot): ?ChatConnection {
            $connectionId = $bot->provider_connection_id;
            $modelId = $bot->provider_model_id;

            if (! is_string($connectionId) || ! is_string($modelId)) {
                return null;   // an unconfigured bot; the caller refuses rather than choosing a model
            }

            $connection = ProviderConnection::query()
                ->where('organization_id', '=', $organizationId)
                ->whereKey($connectionId)
                ->first();

            // `enabled` IS PART OF THE LOOKUP AND NOT A CHECK AFTERWARDS. A disabled model row is a
            // model the operator has taken out of service, and a bot still naming it must refuse
            // rather than answer from it — the miss is what produces that refusal.
            $model = ProviderModelEntry::query()
                ->where('organization_id', '=', $organizationId)
                ->whereKey($modelId)
                ->where('provider_connection_id', '=', $connectionId)
                ->where('enabled', '=', true)
                ->first();

            if ($connection === null || $model === null) {
                return null;
            }

            return ChatConnection::from($connection, $model);
        });
    }

    /**
     * @return list<ChatConnection>
     */
    public function fallbackConnections(string $organizationId, string $botId): array
    {
        return $this->scoped($organizationId, function () use ($organizationId, $botId): array {
            $entries = BotFallbackEntry::query()
                ->where('organization_id', '=', $organizationId)
                ->where('bot_id', '=', $botId)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $chain = [];

            foreach ($entries as $entry) {
                $model = ProviderModelEntry::query()
                    ->where('organization_id', '=', $organizationId)
                    ->whereKey($entry->provider_model_id)
                    ->where('enabled', '=', true)
                    ->first();

                if ($model === null) {
                    continue;   // a rotted rung is skipped, never fatal — see the interface docblock
                }

                $connection = ProviderConnection::query()
                    ->where('organization_id', '=', $organizationId)
                    ->whereKey($model->provider_connection_id)
                    ->first();

                if ($connection === null) {
                    continue;
                }

                // §8.7's per-connection rate-limit fallback switch is ON for a rung that IS a
                // fallback: an operator who configured a chain has already said they want a rate
                // limit to move to the next model. It stays OFF on the primary
                // (`ChatConnection::from`'s default), where the switch would mean "quietly move
                // spend on the first 429".
                $chain[] = ChatConnection::from($connection, $model, fallbackOnRateLimit: true);
            }

            return $chain;
        });
    }

    public function embeddingConnectionForSpace(
        string $organizationId,
        string $provider,
        string $model,
        ?string $preferredConnectionId,
    ): ?ChatConnection {
        return $this->scoped($organizationId, function () use ($organizationId, $provider, $model, $preferredConnectionId): ?ChatConnection {
            $rows = ProviderModelEntry::query()
                ->where('provider_models.organization_id', '=', $organizationId)
                ->where('provider_models.model', '=', $model)
                ->where('provider_models.enabled', '=', true)
                ->join('provider_connections', function ($join) use ($organizationId, $provider): void {
                    $join->on('provider_connections.id', '=', 'provider_models.provider_connection_id')
                        ->where('provider_connections.organization_id', '=', $organizationId)
                        ->where('provider_connections.provider', '=', $provider);
                })
                // DETERMINISTIC. Two connections serving the same `(provider, model)` are
                // interchangeable for this purpose, so the tiebreak only has to be STABLE — an
                // unordered read would embed one turn's question through one account and the next
                // turn's through another, which is two bills for one bot and no way to reconcile
                // either.
                ->orderBy('provider_connections.created_at')
                ->orderBy('provider_connections.id')
                ->get(['provider_models.id', 'provider_models.provider_connection_id']);

            if ($rows->isEmpty()) {
                return null;
            }

            $chosen = null;

            foreach ($rows as $row) {
                if ($preferredConnectionId !== null && (string) $row->provider_connection_id === $preferredConnectionId) {
                    $chosen = $row;

                    break;
                }
            }

            // `$rows` IS NON-EMPTY (guarded above), so `first()` always answers here. The
            // preferred connection wins when it is among them; otherwise the deterministic order
            // decides — see the interface on why a STABLE tiebreak is all this needs.
            $chosen ??= $rows->first();

            // Re-read both rows in full. The join above selected two columns so the ordering could be
            // done in SQL; `ChatConnection::from()` needs the capability flags and the window, and
            // `Model::shouldBeStrict()` makes reading an unselected column an exception rather than a
            // null — which is the correct behaviour and the reason this is a second read rather than
            // a wider projection nobody would notice going stale.
            $modelRow = ProviderModelEntry::query()
                ->where('organization_id', '=', $organizationId)
                ->whereKey($chosen->id)
                ->first();

            $connectionRow = ProviderConnection::query()
                ->where('organization_id', '=', $organizationId)
                ->whereKey($chosen->provider_connection_id)
                ->first();

            if ($modelRow === null || $connectionRow === null) {
                return null;
            }

            return ChatConnection::from($connectionRow, $modelRow);
        });
    }

    public function rerankConnection(string $organizationId, RerankDesignation $designation): ?ChatConnection
    {
        return $this->scoped($organizationId, function () use ($organizationId, $designation): ?ChatConnection {
            $connection = ProviderConnection::query()
                ->where('organization_id', '=', $organizationId)
                ->whereKey($designation->connectionId)
                ->first();

            $model = ProviderModelEntry::query()
                ->where('organization_id', '=', $organizationId)
                ->where('provider_connection_id', '=', $designation->connectionId)
                ->where('model', '=', $designation->model)
                ->where('enabled', '=', true)
                ->first();

            if ($connection === null || $model === null) {
                return null;   // the degraded path, not an error — see the interface docblock
            }

            return ChatConnection::from($connection, $model);
        });
    }

    /**
     * Which organization owns a public bot identifier.
     *
     * ── THE ONE SCOPE-FREE READ, NARROWED TO ONE COLUMN ────────────────────────────────────
     *
     * tenancy-exempt: this is the tenant-DISCOVERY step of the unauthenticated SDK bootstrap. It is
     * written as a raw builder over two columns rather than as a model read for a specific reason:
     * `Bot` carries `#[ScopedBy(OrganizationScope::class)]`, which fails CLOSED with `1 = 0` when no
     * tenant context is bound, so a model read here would return nothing on the very surface that
     * has no context yet — and the two ways to make a model read work are both worse than this.
     * `withoutGlobalScopes()` is banned outright by tests/Arch/StringLevelDoctrineTest.php, and
     * binding a context from request input to satisfy the scope is the privilege escalation the
     * whole surface exists to prevent.
     *
     * What it returns is one ULID and nothing else. No status, no configuration, no tenant content:
     * the caller re-reads the bot THROUGH the scope once the organization is bound, so every
     * subsequent read on the request is ordinary tenant-scoped code.
     */
    private function organizationOwning(string $publicBotId): ?string
    {
        // tenancy-exempt: the tenant-DISCOVERY step of the unauthenticated SDK bootstrap. There is
        // no organization to scope by until this row is read, and taking one from request input is
        // the privilege escalation the surface exists to prevent.
        //
        // A QUERY BUILDER AND NOT A MODEL READ, and the two alternatives are both worse. `Bot`
        // carries #[ScopedBy(OrganizationScope::class)], which fails CLOSED with `1 = 0` when no
        // context is bound — so a model read returns nothing on precisely the surface that has none
        // yet. `withoutGlobalScopes()` would work and is banned outright by
        // tests/Arch/StringLevelDoctrineTest.php, correctly: the ban is what stops the same
        // three-word escape being used where a scope was merely inconvenient.
        //
        // ONE COLUMN. No status, no configuration, no tenant text — a ULID, which the caller then
        // binds as the tenant context so every read after it is scoped normally.
        $row = DB::table('bots')
            ->where('public_bot_id', '=', $publicBotId)
            ->first(['organization_id']);

        if ($row === null || ! is_string($row->organization_id)) {
            return null;
        }

        return $row->organization_id;
    }
}
