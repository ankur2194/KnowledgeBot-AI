<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The permission catalog, in code (laravel-rbac-policies, "Package or hand-rolled").
 *
 * Roles are fixed by the spec, so there is no per-tenant role CRUD to store and nothing here needs
 * a table. The only database fact about authorization is the membership row.
 *
 * Only the permissions this change set actually authorizes are listed. A case nobody grants and
 * nobody checks is a permission that fails silently in both directions.
 */
enum Permission: string
{
    /** Read a provider connection, its masked credential, and the organization's embedding readiness. */
    case ProvidersView = 'providers.view';

    /**
     * Create or edit a provider connection, and DESIGNATE which connection supplies the embedding
     * credential.
     *
     * The designation sits behind the same permission as the credential itself, not behind a
     * weaker "settings" permission, because moving it moves the vector space every future corpus
     * is indexed under and moves which credential pays for the embedding calls.
     */
    case ProvidersManage = 'providers.manage';
}
