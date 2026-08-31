<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Organization;
use App\Services\Sdk\WidgetSession;

/**
 * One authorized-but-not-yet-executed turn: everything `ChatGate` resolved, handed to the relay.
 *
 * It exists so the controller cannot re-resolve any of it. The bot, the organization and the
 * conversation were read under a tenant scope inside the gate; a controller that took ids instead
 * would have to read them again, and the second read is the one somebody forgets to scope.
 */
final readonly class ChatTurn
{
    public function __construct(
        public Organization $organization,
        public Bot $bot,
        public Conversation $conversation,
        public WidgetSession $session,
        public string $clientMessageId,
        public string $content,
    ) {}
}
