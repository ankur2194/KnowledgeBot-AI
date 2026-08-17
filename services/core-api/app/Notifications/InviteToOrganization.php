<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrgRole;
use App\Support\Kb\FrontendUrl;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * "You have been invited to join <organization>."
 *
 * SENT TO AN ON-DEMAND NOTIFIABLE, not to a User: the invitee usually has no account yet, which is
 * the entire point of an invitation. The caller does
 * `Notification::route('mail', $email)->notify(new InviteToOrganization(...))`.
 *
 * NAMED FOR THE MESSAGE, NOT THE RECORD, and deliberately not `OrganizationInvitation`: that name is
 * taken by App\Models\OrganizationInvitation. The two are legal side by side — different namespaces —
 * but every consumer that touches both (the invitation service reads the model and sends this) would
 * need an alias at each import, and the one that forgets gets a confusing type error rather than an
 * obvious one. The imperative form also matches this directory's two siblings, ResetPassword and
 * VerifyEmailAddress, which are named for what the mail asks the reader to do.
 *
 * EVERY ARGUMENT IS A SCALAR — never the OrganizationInvitation model, and never the Organization.
 * A queued notification that serialised a model would re-query it on the worker through
 * SerializesModels, and would then mail an invitation whose row had been REVOKED in the interim, or
 * fail with ModelNotFoundException for a row an admin deleted a second after sending. Passing the
 * five values it renders makes the mail a function of the moment it was authorised.
 *
 * `ShouldQueue` for the reason ResetPassword records: auth mail never sends on a request thread. The
 * queue is pinned to `notify` rather than `ai-dispatch`, so an invitation is not stuck behind a bulk
 * ingestion run.
 *
 * ACCEPTED RESIDUAL, WRITTEN DOWN: queueing puts the PLAINTEXT token in the Valkey job body for the
 * life of the job. Both alternatives are worse — not queueing re-opens the timing oracle on the
 * sibling endpoints, and re-minting inside the job changes the token after the row was written, so
 * the digest in PostgreSQL would no longer match the link. It is accepted because the job lives
 * milliseconds on the internal-only, ACL'd Valkey instance. The Security suite therefore asserts the
 * plaintext is absent from logs, audit details and response bodies — NOT from Valkey.
 */
final class InviteToOrganization extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $token,
        private readonly string $organizationName,
        private readonly OrgRole $role,
        private readonly string $inviterName,
        private readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * See ResetPassword::viaQueues(). `notify` exists in config/horizon.php's `worker-fast`
     * supervisor; a `mail` queue would exist nowhere and the invitation would never send.
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
            ->subject(sprintf('%s invited you to %s on KnowledgeBot', $this->inviterName, $this->organizationName))
            ->line(sprintf(
                '%s has invited you to join %s on KnowledgeBot as %s.',
                $this->inviterName,
                $this->organizationName,
                $this->roleLabel(),
            ))
            ->action('Accept invitation', FrontendUrl::for('/invitations/accept', [
                'token' => $this->token,
            ]))
            ->line(sprintf(
                'This invitation expires on %s. After that an administrator has to send a new one.',
                $this->expiresAt->toDayDateTimeString(),
            ))
            ->line('If you were not expecting this, you can ignore this email — no account is created until you accept.');
    }

    /**
     * A human-readable role name, derived rather than declared.
     *
     * DELIBERATELY NOT A `label()` METHOD ON App\Enums\OrgRole: the enum is the authorization
     * catalog, and a display string on it invites presentation logic into the one place the role x
     * permission matrix lives. `Str::headline('knowledge_manager')` is "Knowledge Manager", which is
     * the whole requirement.
     */
    private function roleLabel(): string
    {
        return Str::headline($this->role->value);
    }
}
