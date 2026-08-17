<?php

declare(strict_types=1);

namespace App\Support\Kb;

use RuntimeException;

/**
 * THE ONE PLACE an emailed link is built, and the one place `FRONTEND_URL` is validated.
 *
 * Every link this service mails — password reset, email verification, organization invitation —
 * points at the Next.js SPA, never at a Laravel route: there are no Blade pages in this application
 * at all. The SPA reads the token out of its own query string and POSTs it back to the API.
 *
 * IT IS NOT `APP_URL`. `config('app.url')` is THIS API's own host (`api.<domain>`); the SPA is
 * `app.<domain>`. Confusing the two sends every recipient to a JSON 404, and the failure looks
 * exactly like "the link never arrived".
 *
 * WHY THE VALIDATION LIVES HERE AND NOT IN CONFIG. Configuration must never throw: `config/kb.php`
 * is built during bootstrap and again by `php artisan config:cache`, so an exception there takes
 * down `artisan` itself — including the commands an operator would run to fix it. So the base URL is
 * rtrimmed in config and CHECKED here, at the moment a link is actually built.
 *
 * AND IT MUST BE CHECKED SOMEWHERE, because the failure mode of an unset value is not an error. It
 * is `https:///verify-email?token=…` — a syntactically valid URL that mails successfully, arrives,
 * and does nothing. Nobody reports it as a bug; the recipient simply never gets in. A throw is the
 * loud alternative: it fails the queued notification job, the job retries, and the token stays
 * unconsumed in the database rather than being burned on a dead link.
 *
 * The throw is a plain RuntimeException rather than a typed one because a misconfigured base URL is
 * an operator error at deploy time, not a condition any caller can branch on — and every throwable
 * in this application lives in App\Exceptions (the `laravel` arch preset enforces it), which would
 * make a dedicated class a new file in a namespace this change set does not own.
 */
final class FrontendUrl
{
    /**
     * Build an absolute SPA URL.
     *
     * @param  string  $path  the SPA path, with or without a leading slash
     * @param  array<string, string>  $query  appended as RFC 3986 percent-encoded pairs
     *
     * @throws RuntimeException when `kb.frontend_url` is empty or is not an http/https URL
     */
    public static function for(string $path, array $query = []): string
    {
        $url = self::base().'/'.ltrim($path, '/');

        if ($query !== []) {
            // RFC 3986, so a space becomes %20 rather than `+`. An `+` in a query value is decoded
            // as a space by some readers and kept literally by others, and one of the two values
            // this ever carries is an email address.
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * The validated, trailing-slash-free base.
     *
     * @throws RuntimeException
     */
    private static function base(): string
    {
        $base = config('kb.frontend_url');

        if (! is_string($base) || trim($base) === '') {
            throw new RuntimeException(
                'FRONTEND_URL is empty, so no emailed link can be built. Unset, this would mail '
                .'`https:///verify-email?token=…` — a link that sends, arrives, and does nothing. '
                .'Set FRONTEND_URL to the admin SPA\'s public base URL (NOT APP_URL, which is this '
                .'API\'s own host).',
            );
        }

        $base = rtrim(trim($base), '/');

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);

        // Both halves are checked. A scheme with no host is `https:///…` again; a host with no
        // scheme is a relative reference that no mail client will make clickable.
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new RuntimeException(
                'FRONTEND_URL must be an absolute http:// or https:// URL with a host. It is the '
                .'base of every password-reset, email-verification and invitation link, and a '
                .'malformed base produces mail that arrives and does nothing.',
            );
        }

        return $base;
    }
}
