<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification as an OPAQUE, SINGLE-USE, REVOCABLE database token.
 *
 * Not a signed URL. `URL::hasValidSignature()` validates against `$request->url()`, which is the
 * API's host, while the URL the user clicked was the SPA's — so a signed link cannot be validated
 * after the SPA echoes its parameters back without hand-reconstructing the signed SPA URL, and any
 * difference in query-parameter order or percent-encoding silently fails `hash_equals`. A row, by
 * contrast, is consumable (single use), overwritable (a resend kills the old link), and shares one
 * shape with the two other token flows in this design.
 *
 * `email` IS THE ADDRESS THE TOKEN WAS ISSUED FOR, and it is not redundant with `users.email`.
 * A token minted before an address change must not verify the new address; comparing this column
 * against the user's current email at consume time IS that check, and there is nowhere else to put
 * it.
 *
 * `token_hash` is 32 raw bytes of sha256 in a `bytea`; the plaintext is never stored. Same reasoning
 * as `organization_invitations`.
 *
 * No `organization_id` and no FK chain to one — verification is a fact about a user. See the
 * `password_reset_tokens` migration for the tenancy-exemption note both tables share.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE email_verification_tokens (
                id          char(26) COLLATE "C" PRIMARY KEY,
                user_id     char(26) COLLATE "C" NOT NULL
                            REFERENCES users (id) ON DELETE RESTRICT,
                email       text NOT NULL,
                token_hash  bytea NOT NULL,
                expires_at  timestamptz NOT NULL,
                consumed_at timestamptz,
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT email_verification_tokens_email_lowercase
                    CHECK (email = lower(email))
            )
        SQL);

        $this->run(
            'CREATE UNIQUE INDEX email_verification_tokens_token_hash '
            .'ON email_verification_tokens (token_hash)'
        );

        // One LIVE token per user. A resend therefore DELETEs then INSERTs inside one transaction —
        // the same shape as DatabaseTokenRepository::create() — and that is the reason an
        // expired-but-unconsumed row does not wedge the resend path forever.
        $this->run(
            'CREATE UNIQUE INDEX email_verification_tokens_one_live_per_user '
            .'ON email_verification_tokens (user_id) WHERE consumed_at IS NULL'
        );

        // The expiry sweep, partial for the same reason as the invitation one. It also serves as the
        // FK-child index for user_id on the unconsumed rows; the unique index above covers user_id
        // for that half of the table, so no separate single-column index is added.
        $this->run(
            'CREATE INDEX email_verification_tokens_pending_expires '
            .'ON email_verification_tokens (expires_at) WHERE consumed_at IS NULL'
        );

        // The FULL FK-child index, unconditional. The two indexes above are both partial on
        // `consumed_at IS NULL`, so neither can prove a CONSUMED row does not reference a user being
        // deleted — which is exactly the check ON DELETE RESTRICT performs.
        $this->run(
            'CREATE INDEX email_verification_tokens_user_id '
            .'ON email_verification_tokens (user_id)'
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS email_verification_tokens');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};
