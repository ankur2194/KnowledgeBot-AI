<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Bot;
use App\Services\Audit\AuditLogger;
use App\Services\Sdk\WidgetSessionService;
use Illuminate\Http\Request;

/**
 * Issue the D5 admin playground's chat-session credential, and record that it was issued.
 *
 * ═══ WHY THIS EXISTS RATHER THAN THE CONTROLLER CALLING THE MINT DIRECTLY ══════════════════
 *
 * Two things have to happen together and exactly one of them is the controller's business. The
 * controller decides PERMISSION — the six checks, each with its own status code. This class decides
 * BEHAVIOUR: which mint, and the audit row that says a diagnostics-capable bearer was handed out.
 * Splitting them that way is the same split every other write on this surface makes, and it is what
 * keeps the audit row from being something a future call site can forget: there is one way to obtain
 * a playground credential and it writes the row on the way past.
 *
 * ═══ THE ORDER IS MINT-THEN-AUDIT, AND IT IS THE ONLY CORRECT ONE ══════════════════════════
 *
 * `AuditLogger::BOT_PLAYGROUND_SESSION_MINTED` is `ON_FAILURE_LOG`, so a failed audit write logs at
 * ERROR and the response stands. That is right here and it is why the row cannot be written first:
 * a row claiming a credential was issued, followed by a mint that failed, is a trail that is wrong
 * in the direction nobody checks it in. Auditing afterwards can only ever UNDER-report, and the
 * under-report is loud (`AUDIT WRITE FAILED and the audited action stands`).
 *
 * There is no transaction to put either half in — the credential lives in Valkey, not in
 * PostgreSQL — which is the same reason `auth.login.succeeded` is `ON_FAILURE_LOG`. See the
 * operation's own docblock.
 *
 * ═══ WHAT LEAVES THIS CLASS ════════════════════════════════════════════════════════════════
 *
 * The token and its lifetime, and nothing else. `session_id` is consumed HERE, by the audit row, and
 * is deliberately not returned: the caller has no use for it, and a value the response shape could
 * pick up is a value that eventually appears in one (Non-negotiable 9's shape, applied to a
 * derivative rather than to a secret).
 */
final readonly class PlaygroundSessionService
{
    public function __construct(
        private WidgetSessionService $sessions,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  string  $actorId  the administrator whose `bots.manage` grant the controller has
     *                           already checked. It becomes `conversations.user_id` and the internal
     *                           request's actor id — never a tenant SCOPE, which is the
     *                           organization's job.
     * @return array{token: string, expires_in: int}
     */
    public function mint(Bot $bot, string $actorId, ?Request $request = null): array
    {
        $session = $this->sessions->mintPlayground($bot, $actorId);

        $this->audit->record(
            AuditLogger::BOT_PLAYGROUND_SESSION_MINTED,
            (string) $bot->organization_id,
            $actorId,
            [
                'bot_id' => (string) $bot->id,
                // THE DERIVED ID AND NEVER THE TOKEN. One-way from the bearer, useless without it,
                // and the only value that ties this row to the `rl:` buckets and the log lines
                // naming the same session. `$session['token']` is not referenced anywhere in this
                // method except the return below.
                'session_id' => $session['session_id'],
                'diagnostics' => true,
                'expires_in' => $session['expires_in'],
            ],
            subjectType: Bot::class,
            subjectId: (string) $bot->id,
            request: $request,
        );

        return [
            'token' => $session['token'],
            'expires_in' => $session['expires_in'],
        ];
    }
}
