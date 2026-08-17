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
 * The password-reset link, replacing Illuminate\Auth\Notifications\ResetPassword entirely.
 *
 * THE FRAMEWORK'S ONE FATALS HERE. Its `toMail()` builds `route('password.reset', …)`, and this
 * application registers no such route — there are no Blade pages at all — so it throws
 * RouteNotFoundException from inside PasswordBroker::sendResetLink(). `ResetPassword::createUrlUsing()`
 * would fix the URL, but not the second problem below, which is the one that matters.
 *
 * `ShouldQueue` IS A SECURITY REQUIREMENT, NOT A THROUGHPUT ONE. `PasswordBroker::sendResetLink()`
 * wraps its work in a 200 ms timebox and sends the notification INSIDE it. The timebox exists so
 * that "this address has an account" and "it does not" take the same wall-clock time — the whole
 * defence against account enumeration on this endpoint. A synchronous SMTP handshake takes far
 * longer than 200 ms, so it blows the floor on the exists-branch ONLY, and the difference is
 * measurable from the internet with no credentials at all. Queueing moves the send off the request
 * and restores the equality. The framework's notification is not `ShouldQueue`.
 *
 * The queue is pinned to `notify` — see viaQueues() below.
 *
 * THE TOKEN IS A SCALAR, and so is the address: nothing here serialises a model. That keeps the
 * queued payload independent of the row, which matters because the reset row may legitimately be
 * consumed or replaced between dispatch and delivery.
 */
final class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $token,
        private readonly string $email,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * MANDATORY, and not a tuning knob.
     *
     * `config/queue.php` defaults the `valkey` connection's queue to `ai-dispatch`, which carries
     * ingestion, crawl and deletion submissions. A password-reset mail queued behind a bulk
     * ingestion run is an unbounded delay against a token that expires in 60 minutes, and the user
     * experiences it as "the link never arrived".
     *
     * `notify` is an EXISTING queue: `config/horizon.php` puts it in the `worker-fast` supervisor
     * and sets its wait threshold. Do not invent a `mail` queue — no supervisor consumes one, so the
     * mail would sit in Valkey forever and nothing anywhere would say so.
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
            ->subject('Reset your KnowledgeBot password')
            ->line('We received a request to reset the password for your KnowledgeBot account.')
            ->action('Reset password', FrontendUrl::for('/reset-password', [
                // The address rides along because the broker's own token row is KEYED by it:
                // PasswordBroker::reset() needs it to find the user before it can verify the token.
                // It is the framework's shape, and the address is already in the mailbox this is
                // being delivered to.
                'token' => $this->token,
                'email' => $this->email,
            ]))
            ->line(sprintf('This link expires in %d minutes and can be used once.', self::expiresInMinutes()))
            ->line('If you did not request a password reset, you can ignore this email — nothing has changed.');
    }

    /**
     * Quoted from the broker's own configuration rather than restated, so the sentence in the email
     * cannot drift from the expiry the code enforces.
     */
    private static function expiresInMinutes(): int
    {
        $broker = (string) config('auth.defaults.passwords', 'users');

        return (int) config("auth.passwords.{$broker}.expire", 60);
    }
}
