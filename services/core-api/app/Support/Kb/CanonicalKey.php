<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * The stable identity of one item WITHIN its source — `source_items.canonical_key`.
 *
 * ── WHAT IT IS FOR ────────────────────────────────────────────────────────────────────────────
 *
 * It is what makes a recrawl recognise a page it has seen before instead of creating a second row
 * for it, and it is what makes a resubmission of the same paste a new VERSION of the same item
 * rather than a second item. `source_items_org_source_canonical` is UNIQUE on
 * `(organization_id, source_id, canonical_key)`, so getting it wrong does not corrupt anything — it
 * silently doubles the corpus, and the duplicate answers alongside the original.
 *
 * ── NORMALIZATION IS LOSSY ON PURPOSE, AND CONSERVATIVELY SO ─────────────────────────────────
 *
 * Two URLs that differ only in case of scheme or host, in a default port, or in a fragment are the
 * same page, and folding them is what stops a nightly crawl re-versioning a site for nothing. What
 * is deliberately NOT folded: the query string, the path's case, and a trailing slash on a
 * non-empty path. Each of those genuinely distinguishes documents on real sites — `?id=7` is the
 * whole address on a CMS, paths are case-sensitive on every non-Windows origin server, and
 * `/docs/` versus `/docs` is two different pages often enough that merging them would drop one.
 * The failure of folding too little is a duplicate somebody can see; the failure of folding too
 * much is a page that silently never gets indexed.
 *
 * TRACKING PARAMETERS ARE NOT STRIPPED HERE, and that is a deferral rather than a decision: the
 * `utm_*` family is easy and the long tail is not, the correct list is per-site, and it belongs
 * with the crawl configuration the crawler owns rather than in a helper that cannot see one.
 *
 * ── THE URL IS STILL KEPT SEPARATELY ─────────────────────────────────────────────────────────
 *
 * `source_items.url` holds the value as given, because normalization is lossy and a CITATION has to
 * link to something a human can open. Never render this value to an end user.
 */
final class CanonicalKey
{
    /**
     * The single item of a pasted-text source.
     *
     * A CONSTANT AND NOT A DIGEST OF THE TEXT. Keying it on the content would make an EDIT of the
     * paste a new ITEM rather than a new VERSION of the existing one — the version history would
     * reset, the active-version pointer would have nothing to switch, and every citation into the
     * previous text would point at an item nothing supersedes. A text source has exactly one item
     * for its whole life, and this is its name.
     */
    public const TEXT = 'text';

    /**
     * The canonical form of a URL, or the input trimmed when it cannot be parsed as one.
     *
     * NEVER RAISES. The value has already passed `knowledge_sources_origin_url_scheme` and the
     * FormRequest's own URL rule by the time it reaches here, and a helper that threw on the
     * remaining exotica would turn a storable value into a 500 rather than a slightly less folded
     * key. A key that folds nothing is still a correct key.
     */
    public static function forUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        // The default port for the scheme is not part of the address. Any other port is.
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $authority = $host.($port === null || $isDefaultPort ? '' : ':'.$port);

        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        // A bare origin is `https://example.com` and not `https://example.com/`. Only the EMPTY
        // path's slash is dropped — see the class docblock for why `/docs/` keeps its own.
        if ($path === '/') {
            $path = '';
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        // The fragment is deliberately absent: it is never sent to the server, so two URLs that
        // differ only in it are one request and one document.
        return $scheme.'://'.$authority.$path.$query;
    }
}
