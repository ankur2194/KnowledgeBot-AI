<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened when a `provider_models` row was asked to go away.
 *
 * ── WHY A THREE-STATE RESULT AND NOT A BOOLEAN ─────────────────────────────────────────────────
 *
 * Deleting a connection has ONE refusal — the designation — and PostgreSQL enforces it: the
 * composite `ON DELETE RESTRICT` on `organizations.embedding_connection_id` raises 23503, so the
 * repository can return a bare bool and the service maps a SQLSTATE onto a 409.
 *
 * DELETING A CATALOG ROW HAS THE SAME REFUSAL AND NO CONSTRAINT BEHIND IT. `organizations.
 * embedding_model` is a bare `text` column: the composite foreign key ties the designation to a
 * CONNECTION, and there is no key from `(embedding_connection_id, embedding_model)` to
 * `provider_models (provider_connection_id, model)` — there could be, and adding one is a schema
 * decision with an ADR in front of it, not a drive-by. So nothing in the database will refuse the
 * delete, and a pre-flight check in the controller is a check with a RACE: another administrator
 * designating this pair between the read and the DELETE wins, and the organization is left naming
 * a row that no longer exists. The next upload then fails with a resolution error the operator
 * cannot connect to anything they did.
 *
 * Closing that race means re-reading the organization INSIDE the delete's transaction, and the
 * repository is the only layer that may open one (`DB` is arch-pinned to App\Repositories\Eloquent).
 * So the repository has to be able to say "I refused, and here is why" — which a bool cannot, and
 * which an exception thrown from a repository would say by putting an HTTP concern one layer below
 * the layer that owns HTTP concerns.
 *
 * `enum` rather than three bools or a nullable string, because the three outcomes are exhaustive
 * and a `match` over them is checked. It lives in App\Enums because
 * `arch()->preset()->laravel()` asserts `expect('App')->not->toBeEnums()->ignoring('App\Enums')` —
 * an enum anywhere else fails the Arch suite.
 */
enum ProviderModelDeletion
{
    /** The row is gone and the audit row committed with it. */
    case Deleted;

    /**
     * No such row in THIS organization. In practice: deleted between the route binding and the
     * transaction, because a foreign or unknown id 404s at binding time long before this.
     */
    case Missing;

    /**
     * The row is the (connection, model) pair the organization's embedding designation names.
     * Nothing was changed. The operator clears the designation first — and sees what that means.
     */
    case Designated;
}
