<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\InvitationStatus;
use App\Enums\OrgRole;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * One invitation, as an administrator of the inviting organization sees it.
 *
 * THE ADMIN VIEW, NOT THE GUEST VIEW. The two are separate classes on purpose: this reader is a
 * member with `members.view`, so it is safe to name the inviter and the state, which
 * InvitationPreviewResource must not. Merging them into one resource with conditional fields is how a
 * guest response ends up carrying an admin field the day someone adds a `when()`.
 *
 * `status` IS DERIVED HERE AND NOWHERE STORED. There is no `status` column on
 * `organization_invitations` and there must not be: the state is a function of `revoked_at`,
 * `accepted_at` and `expires_at`, and `expired` is a state a row enters with nobody writing to it. A
 * cron flipping a column to keep up would make every read racy against its own schedule. See
 * App\Enums\InvitationStatus.
 *
 * THE TOKEN IS NOT HERE, IN ANY FORM — not the plaintext, which is never stored, not the digest, and
 * not a prefix of either. An invitation is a bearer capability, and an admin list is a screen, a log
 * line and a browser cache away from being a place a capability leaks. Re-sending is how a link is
 * re-delivered, and re-sending mints a NEW token.
 *
 * @property-read OrganizationInvitation $resource
 */
final class InvitationResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(OrganizationInvitation $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;
        $invitedBy = $invitation->invitedBy;

        if (! $invitedBy instanceof User) {
            // `invited_by_id` is NOT NULL with ON DELETE RESTRICT, so this means the relation was not
            // eager-loaded — which Model::shouldBeStrict() would already have thrown on. Loud rather
            // than a null name, because the published schema declares this field non-nullable.
            throw new RuntimeException(
                'InvitationResource needs `with(\'invitedBy\')`. The column is NOT NULL with '
                .'ON DELETE RESTRICT, so an absent relation is an unloaded one, and the published '
                .'schema declares `invited_by_name` non-nullable.',
            );
        }

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $invitation->status()->value,
            'expires_at' => $invitation->expires_at->toAtomString(),
            'created_at' => $invitation->created_at?->toAtomString(),
            // The NAME and not the id or the address: this answers "who is accountable for this
            // invitation" on a list screen. An administrator who needs the inviter's address reads the
            // member list, where the reader's permission to see it is checked for that purpose.
            'invited_by_name' => $invitedBy->name,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'InvitationResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One organization invitation as an administrator sees it. Carries no '
                    .'token and no token digest in any form — an invitation is a bearer capability, '
                    .'and re-delivering a link is done by re-sending it, which mints a new token and '
                    .'kills the old one.',
                'required' => [
                    'id', 'email', 'role', 'status', 'expires_at', 'created_at', 'invited_by_name',
                ],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the invitation. Addressable only under its own '
                            .'organization\'s path, where a foreign id 404s at binding time.',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'The invited address, always lower-case — a database CHECK '
                            .'enforces `email = lower(email)`, because the lookup that has to find '
                            .'the row is normalised.',
                    ],
                    'role' => [
                        'type' => 'string',
                        'enum' => OrgRole::values(),
                        'description' => 'The role this invitation grants on acceptance.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => InvitationStatus::values(),
                        'description' => 'DERIVED from `revoked_at`, `accepted_at` and `expires_at` — '
                            .'never stored. Precedence is revoked, then accepted, then expired: an '
                            .'invitation accepted after its expiry is `accepted`, not `expired`. '
                            .'Only `pending` can be resent or revoked.',
                    ],
                    'expires_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When the token stops working, RFC 3339. In the past for a '
                            .'row whose `status` is `expired`.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'When the invitation was created, RFC 3339. Nullable only '
                            .'because an unsaved model has no timestamp; every persisted row has one '
                            .'(the column is NOT NULL DEFAULT now()).',
                    ],
                    'invited_by_name' => [
                        'type' => 'string',
                        'description' => 'Display name of the member who created the invitation. The '
                            .'name only — the accountable actor\'s ULID and address are not part of a '
                            .'list screen.',
                    ],
                ],
            ],
        ];
    }
}
