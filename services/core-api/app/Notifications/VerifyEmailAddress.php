<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Kb\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The email-verification link, replacing Illuminate\Auth\Notifications\VerifyEmail.
 *
 * NAMED `VerifyEmailAddress`, NOT `VerifyEmail`, on purpose: the framework's class of that name is
 * imported by `Illuminate\Auth\MustVerifyEmail` and by the auto-registered
 * `SendEmailVerificationNotification` listener, and two classes called `VerifyEmail` in one stack
 * trace is a trap for the next reader.
 *
 * WHY OURS. The framework's builds a SIGNED URL to `route('verification.verify')`, and
 * `URL::hasValidSignature()` validates against THIS API's URL while the recipient clicked the SPA's
 * — so a signed URL cannot be verified after the SPA echoes its parameters back. We carry an opaque
 * single-use database token instead (App\Support\Kb\OpaqueToken records the full reasoning). The
 * URL therefore carries NO user id, NO email and NO hash: the token identifies the row, and the row
 * carries the user and the address it was issued for.
 *
 * `ShouldQueue` for the same reason as ResetPassword: an SMTP handshake on the request thread is a
 * timing signal, and this notification is sent from a path (`Registered` -> the framework listener,
 * and the resend endpoint) that must not vary its response time with the state of a mail relay.
 * The queue is pinned to `notify`; `ai-dispatch` would put auth mail behind bulk ingestion.
 *
 * The token is a scalar — no model is serialised into the job body.
 */
final class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * See ResetPassword::viaQueues() for why this is mandatory and why `notify` is the only correct
     * value. Do not invent a `mail` queue: nothing consumes one.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'notify'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your KnowledgeBot email address')
            ->line('Confirm this email address to finish setting up your KnowledgeBot account.')
            ->action('Confirm email address', FrontendUrl::for('/verify-email', [
                'token' => $this->token,
            ]))
            ->line(sprintf(
                'This link expires in %d hours. If it does, sign in and request a new one.',
                (int) config('kb.email_verification_ttl_hours', 24),
            ))
            ->line('If you did not create a KnowledgeBot account, you can ignore this email.');
    }
}
