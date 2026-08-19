<?php

declare(strict_types=1);

namespace App\Support\Web;

/**
 * One EXACT web origin — scheme, host, optional port — parsed, refused, or normalised.
 *
 * ── WHAT AN ORIGIN IS, AND WHY THE ANSWER IS NOT "WHATEVER `parse_url()` RETURNS" ─────────────
 *
 * RFC 6454 §6.1 serialises an origin as `scheme "://" host [ ":" port ]`, lower-cased, with the
 * DEFAULT PORT OMITTED and nothing else. That string is exactly what a browser puts in the `Origin`
 * request header and exactly what `postMessage` compares a `targetOrigin` against, and the widget
 * bootstrap will compare it for BYTE EQUALITY (`BotDomainStatus::permitsEmbedding()`). So a stored
 * value that is not that serialisation is a row that can never match — a grant the operator was
 * told they made and that will silently never fire.
 *
 * `parse_url()` is not enough on its own for three separate reasons, each of which has produced a
 * real vulnerability class somewhere: it accepts a path, a query and a fragment without comment; it
 * accepts userinfo; and it happily reports a host for inputs no browser would ever emit. This class
 * is deliberately stricter than `parse_url()` and stricter than the database CHECK it feeds.
 *
 * ── THE THREE MUTATIONS THIS CLASS PERFORMS, AND WHY EACH ONE IS SAFE ─────────────────────────
 *
 * Normalisation that CHANGES what the operator typed has to be justified per change, because a
 * silent widening is the worst possible outcome on an allow-list. Exactly three are performed, and
 * all three are identity-preserving on the origin:
 *
 *   1. CASE FOLDING of the scheme and the host. Both are case-insensitive per RFC 3986 §3.1/§3.2.2
 *      and a browser lower-cases both before it serialises, so `HTTPS://Example.COM` and
 *      `https://example.com` are one origin and the browser will only ever send the second.
 *   2. A SINGLE TRAILING SLASH is dropped. An empty path is not part of an origin, so `https://a.b/`
 *      and `https://a.b` are the same origin — and the trailing slash is what a browser's address
 *      bar shows, so it is what an operator pastes.
 *   3. THE DEFAULT PORT is dropped — 80 for `http`, 443 for `https`. This is the one a reviewer
 *      should not skim: the URL Standard omits the default port from the serialisation, so a stored
 *      `https://example.com:443` NEVER equals the `Origin: https://example.com` the browser sends.
 *      Keeping it would be a row that looks right in the console and grants nothing.
 *
 * ── AND THE ONE MUTATION THAT IS REFUSED INSTEAD, WHICH IS THE SECURITY DECISION ──────────────
 *
 * A NON-EMPTY PATH IS A 422, NOT A TRIM. `https://example.com/widget` is refused rather than
 * silently reduced to `https://example.com`, because reducing it would BROADEN the grant: the
 * operator believes they have allowed one page and the browser sends the same `Origin` for every
 * page on the host, so the row would permit the whole site. Same for a query string and a fragment.
 * Telling them so is the only honest answer — the alternative is a console that accepts a narrower
 * grant than the platform can express and never says which one it stored.
 *
 * ── WHAT IS REFUSED OUTRIGHT, WITH THE REASON EACH REFUSAL CARRIES ────────────────────────────
 *
 *   a wildcard (`*`)      There is no wildcard grammar here, in the schema, or in the matcher.
 *                         `*.example.com` reads as "our sites" and means "every host anyone can get
 *                         a certificate for under example.com" — a customer subdomain, a status
 *                         page, a marketing CMS, or one stale DNS record pointing at an abandoned
 *                         bucket. The comparison this list feeds is byte equality; a pattern would
 *                         force it to become a matcher, and a matcher is where the bypasses live
 *                         (kb-security-baseline: every deny-list is a bug waiting for an encoding
 *                         trick, and every matcher is a deny-list wearing a hat).
 *   userinfo (`u:p@host`) Credentials in an allow-list entry, and a browser never sends them in
 *                         `Origin`. It is also the classic host-confusion primitive:
 *                         `https://trusted.example@evil.test` has host `evil.test`.
 *   an IPv6 literal       `http://[::1]:3000` is a legal origin and is refused, deliberately and
 *                         with a message that says so. `bot_domains_origin_exact` does not admit
 *                         brackets, and widening the grammar for them means widening it for bracket
 *                         parsing — the corner of URL grammar that has produced the most parser
 *                         disagreements between two implementations that both believed they agreed.
 *   a non-ASCII host      An IDN has two spellings (`bücher.example` and `xn--bcher-kva.example`)
 *                         and the browser sends the punycode one. Storing the unicode form is a row
 *                         that never matches; converting it here would need `idn_to_ascii` and a
 *                         second normalisation contract nobody else in this system implements.
 *   `:0`, `:00080`,       A port is 1-65535 with no leading zeros, because that is what a browser
 *   `:70000`              emits. The database CHECK admits `[0-9]{1,5}`, which is looser — this
 *                         class is the stricter of the two on purpose, so the refusal is a 422 with
 *                         a sentence rather than a row that never matches.
 *
 * The database constraint stays the authority for every writer that is not an HTTP request; this
 * class is what turns that authority into a message a form can render, and it accepts a strict
 * subset of what the constraint does.
 */
final readonly class ExactOrigin
{
    /**
     * The longest origin this platform will store.
     *
     * A host is at most 253 characters (RFC 1035, and 255 minus the length byte and the root
     * label), the scheme is at most 5, `://` is 3 and `:65535` is 6 — so 267 is the true ceiling
     * and 255 is a round number comfortably above every real origin. It exists so an unbounded
     * string cannot reach a `text` column with no length limit, not because 254 would be dangerous.
     */
    public const MAX_LENGTH = 255;

    /**
     * The host grammar, and it is BYTE-FOR-BYTE the host half of `bot_domains_origin_exact`.
     *
     * Lower-case labels of letters and digits with internal hyphens, each at most 63 characters,
     * joined by dots. Written as an ALLOW-LIST rather than as a set of forbidden characters, which
     * is the same call the migration makes and for the same reason.
     *
     * An IPv4 literal (`127.0.0.1`) matches, and that is deliberate rather than accidental: it is
     * the loopback address a developer's local build serves from, it grants nothing on the public
     * internet, and refusing it would make the local-development case unexpressible.
     */
    private const HOST = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** The default port each admitted scheme serialises WITHOUT. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
    ) {}

    /**
     * Parse one candidate origin.
     *
     * ── THE RETURN TYPE IS A UNION, AND THAT IS THE WHOLE ERGONOMIC POINT ─────────────────────
     *
     * `self` when the value IS an exact origin, and a `string` REASON when it is not. A nullable
     * return would force every caller to reconstruct the reason from a second pass, and the reason
     * is the entire value of this class to a form: "invalid origin" is unactionable, and every
     * refusal below names what is wrong and what to send instead.
     *
     * One analysis, one answer — so `App\Rules\ExactWidgetOrigin` (which needs the reason) and
     * `StoreBotDomainRequest::toData()` (which needs the normalised value) cannot disagree about
     * which strings are admissible.
     *
     * @return self|string the parsed origin, or the sentence explaining the refusal
     */
    public static function parse(string $value): self|string
    {
        $candidate = trim($value);

        if ($candidate === '') {
            return 'An origin is required. It is the scheme, host and optional port a browser sends '
                .'in the `Origin` header — for example `https://example.com` or '
                .'`http://localhost:3000`.';
        }

        if (mb_strlen($candidate) > self::MAX_LENGTH) {
            return 'An origin may be at most '.self::MAX_LENGTH.' characters. A real one is far '
                .'shorter: the host alone cannot exceed 253 characters, and an origin carries no '
                .'path, so anything this long is not an origin.';
        }

        if (str_contains($candidate, '*')) {
            return 'Wildcards are not accepted, and there is no spelling of one that is. '
                .'`*.example.com` reads as "our sites" and means every host anybody can obtain a '
                .'certificate for under example.com — a customer subdomain, a status page, a '
                .'marketing site, or one stale DNS record pointing at an abandoned bucket. This '
                .'list is compared for exact byte equality against the browser\'s `Origin` header, '
                .'so list each origin you actually embed on, one row each.';
        }

        // ANY control character, not just the obvious ones. A newline would let a value pass the
        // database CHECK (POSIX `$` matches before a trailing newline) while being a different
        // string from the one a browser sends.
        if (preg_match('/[\x00-\x20\x7F]/', $candidate) === 1) {
            return 'An origin contains no spaces and no control characters. Copy it from the '
                .'browser\'s address bar without the path — `https://example.com`, not '
                .'`https://example.com/pricing`.';
        }

        if (preg_match('#^(https?)://(.*)$#i', $candidate, $matches) !== 1) {
            return 'An origin starts with `http://` or `https://`. Those are the only two schemes a '
                .'browser embeds a widget from, and they are the only two this list stores. `http` '
                .'is accepted because local development is a real origin; a row starts as `pending` '
                .'and grants nothing until it is activated.';
        }

        $scheme = mb_strtolower($matches[1]);
        $authority = $matches[2];

        if (str_contains($authority, '@')) {
            return 'An origin carries no username or password. A browser never sends them in the '
                .'`Origin` header, and `https://trusted.example@evil.test` is a host of '
                .'`evil.test` — which is why this is refused rather than stripped.';
        }

        if (str_contains($authority, '?') || str_contains($authority, '#')) {
            return 'An origin carries no query string and no fragment. Send just the scheme, host '
                .'and port — `https://example.com` — because that is the whole of what the browser '
                .'sends and the whole of what this list can compare against.';
        }

        if (str_contains($authority, '[') || str_contains($authority, ']')) {
            return 'An IPv6-literal origin such as `http://[::1]:3000` is a legal origin and is '
                .'deliberately not stored here: the allow-list grammar admits no brackets, and '
                .'widening it for them means taking on bracket parsing, which is the part of URL '
                .'grammar two implementations most often disagree about. Use a hostname — '
                .'`http://localhost:3000` — which is what a browser sends for local development '
                .'anyway.';
        }

        // A SINGLE TRAILING SLASH IS AN EMPTY PATH AND IS DROPPED; ANYTHING ELSE IS A PATH AND IS
        // REFUSED. The order matters: strip first, then test, or `https://a.b/` reads as a path.
        if (str_ends_with($authority, '/')) {
            $authority = substr($authority, 0, -1);
        }

        if (str_contains($authority, '/')) {
            return 'An origin has no path. `https://example.com/widget` cannot be stored as written '
                .'and is NOT quietly shortened to `https://example.com`, because that would grant '
                .'more than you asked for: a browser sends the same `Origin` for every page on a '
                .'host, so the shortened row would permit the whole site. Send the origin alone if '
                .'that is what you mean.';
        }

        if ($authority === '') {
            return 'An origin needs a host. `https://` on its own names nothing.';
        }

        $port = null;
        $host = $authority;

        // THE LAST COLON, not the first. There is no userinfo left (refused above) and no bracketed
        // IPv6 (refused above), so any colon here separates the host from the port — and taking the
        // last one makes a second colon a host-grammar failure with a clear message rather than a
        // port-grammar one with a confusing one.
        $colon = strrpos($authority, ':');

        if ($colon !== false) {
            $host = substr($authority, 0, $colon);
            $portText = substr($authority, $colon + 1);

            // `[1-9][0-9]{0,4}` and not `\d{1,5}`: `:0` is not a port a browser ever emits, and
            // `:00443` is a SECOND spelling of 443 that would sit in the table as a row that never
            // matches. The database CHECK admits both; this is the stricter half on purpose.
            if (preg_match('/^[1-9][0-9]{0,4}$/', $portText) !== 1) {
                return 'A port is a number from 1 to 65535, written without leading zeros — '
                    .'`http://localhost:3000`. A browser serialises it that way and compares it '
                    .'literally, so `:03000` would be stored as a row that can never match.';
            }

            $port = (int) $portText;

            if ($port > 65535) {
                return 'A port is a number from 1 to 65535. '.$portText.' is not one.';
            }
        }

        $host = mb_strtolower($host);

        if (preg_match(self::HOST, $host) !== 1) {
            return 'The host `'.$host.'` is not one this allow-list can store. A host is '
                .'dot-separated labels of ASCII letters, digits and internal hyphens — no '
                .'underscores, no trailing dot, and no internationalised characters. An '
                .'internationalised domain must be given in its punycode form '
                .'(`xn--bcher-kva.example`), which is the form the browser sends.';
        }

        // THE DEFAULT PORT IS DROPPED, and this is the line that decides whether a legitimate row
        // ever matches a real request. A browser serialises `https://example.com:443` as
        // `https://example.com`; keeping the port would store a grant that silently never fires.
        if ($port !== null && $port === (self::DEFAULT_PORTS[$scheme] ?? null)) {
            $port = null;
        }

        return new self($scheme, $host, $port);
    }

    /**
     * The RFC 6454 serialisation: what the browser sends, and therefore what is compared.
     */
    public function __toString(): string
    {
        return $this->scheme.'://'.$this->host.($this->port === null ? '' : ':'.$this->port);
    }
}
