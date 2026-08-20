import { z } from 'zod';

/**
 * The widget origin allow-list — mirrors `App\Http\Requests\StoreBotDomainRequest` (POST
 * `…/bots/{bot}/domains`) and `App\Http\Requests\UpdateBotDomainRequest` (PATCH
 * `…/bots/{bot}/domains/{domain}`).
 *
 * They MIRROR the FormRequests; they do not enforce them (rhf-zod-forms NN2).
 * `test/form-drift.test.ts` probes both against the dumped manifests.
 *
 * ── A ROW HERE IS A SECURITY CONTROL, AND THAT SHAPES WHAT THIS FILE MAY MIRROR ────────────────
 *
 * An `active` row is what lets a page on the public internet boot a chat widget that speaks with
 * this organization's credential, on its corpus, against its quota. The grammar that decides which
 * strings may become one lives in `App\Support\Web\ExactOrigin`, with
 * `bot_domains_origin_exact` as the database's copy — and it is a security control rather than a
 * format preference: it refuses a wildcard, a path, a query, a fragment, userinfo, an IPv6 literal,
 * a non-ASCII host, `:0`, `:00443` and a trailing dot, each with its own sentence.
 *
 * NONE OF THAT IS MIRRORED HERE, and the argument is `ReadableThemeColor`'s one file over: a third
 * spelling of a control whose refusals are the point would be the copy nothing compares to the
 * other two, and the direction it fails in is the bad one — a client-side grammar one case stricter
 * than the server refuses an origin the operator really can embed on, with no 422 to explain it. So
 * this schema carries the LENGTH, the TYPE and the two-scheme prefix, and every other refusal is a
 * 422 keyed `origin` carrying the server's own sentence, which `ExactOrigin::parse()` returns
 * precisely so a form can render it.
 *
 * WHAT IS PROBED ANYWAY: `max:255`, through the `originOfLength` generator in
 * test/form-drift.test.ts — whose acceptance claim at 255 and rejection at 256 were MEASURED against
 * the installed `App\Support\Web\ExactOrigin` rather than reasoned about, because the generic
 * `'a'.repeat(255)` is refused by the rule ("An origin starts with `http://` or `https://`") and
 * would have made the boundary probe a false red on a correct schema.
 *
 * ── RENDER `origin` FROM THE RESPONSE, NEVER FROM THE SUBMITTED VALUE ──────────────────────────
 *
 * The server NORMALISES on write: the scheme and host are lower-cased, a single trailing slash is
 * dropped, and a default port (`:80` for http, `:443` for https) is dropped because the browser
 * omits it. `HTTPS://Example.COM:443/` is stored as `https://example.com`. Echoing what was typed
 * shows the operator a string that is not the row — on the one screen where "what was stored" is the
 * entire question, because the runtime comparison is byte equality against the browser's `Origin`
 * header. Seed the list from the POST's 201 body.
 */

/**
 * `ExactOrigin::MAX_LENGTH`, and it is an absurdity bound rather than a real ceiling: a host is at
 * most 253 characters, the scheme at most 5, `://` is 3 and `:65535` is 6, so 267 is the true
 * maximum and 255 is a round number above every real origin. It exists so an unbounded string
 * cannot reach a `text` column with no length limit.
 */
const ORIGIN_MAX = 255;

/**
 * The two schemes a browser embeds a widget from, and the only two the server's grammar admits
 * (`preg_match('#^(https?)://(.*)$#i', …)` after a `trim()`).
 *
 * CASE-INSENSITIVE, because the server's own match is: `HTTPS://Example.com` is accepted and
 * normalised, so a case-sensitive mirror would refuse a value the server stores. This is the ONE
 * half of the grammar mirrored here, and it is mirrored because it is the mistake operators
 * actually make — typing `example.com` — and because it is safe in the direction that matters:
 * every string `ExactOrigin` accepts matches it.
 */
const ORIGIN_SCHEME = /^https?:\/\//i;

/**
 * `bail|required|string|max:255|App\Rules\ExactWidgetOrigin`.
 *
 * `.min(1)` MIRRORS A BEHAVIOUR RATHER THAN A RULE, exactly as `theme.primary`'s does:
 * `TrimStrings` then `ConvertEmptyStringsToNull` run before any rule, so a cleared input arrives as
 * null and `required` refuses it. The lower bound is where that becomes visible on this side rather
 * than after a round trip.
 */
export const botDomainCreateSchema = z.strictObject({
  origin: z
    .string()
    .trim()
    .min(1, { error: 'Enter the origin a browser sends — for example `https://example.com`.' })
    .max(ORIGIN_MAX)
    .regex(ORIGIN_SCHEME, {
      error:
        'An origin starts with `http://` or `https://`. Copy it from the browser’s address bar ' +
        'without the path — `https://example.com`, not `example.com` and not ' +
        '`https://example.com/pricing`. There is no wildcard form: this list is compared for exact ' +
        'byte equality against the browser’s `Origin` header, so list each origin you embed on.',
    }),
});

export type BotDomainCreateIn = z.input<typeof botDomainCreateSchema>;
export type BotDomainCreateOut = z.output<typeof botDomainCreateSchema>;

/** An empty add-origin form. One field, and it starts blank — there is nothing sensible to guess. */
export const botDomainCreateDefaults = (): BotDomainCreateIn => ({ origin: '' });

/**
 * The lifecycle of one allow-list entry, as a runtime tuple.
 *
 * Here rather than in `src/resources/bots.ts` for the reason every vocabulary in this package is:
 * that module is re-exported from the ROOT entry, budgeted at <=1 kB brotli inside apps/widget's app
 * shell, and holds `BotDomainStatus` as a UNION with no runtime value. A `<Select>` needs a list it
 * can iterate. The two spellings are pinned to the server independently — this by the `in:` probes
 * in test/form-drift.test.ts, the union by the enum comparison in test/resource-drift.test.ts.
 *
 * `pending` is where every row starts and it grants nothing. `active` is the ONE value that permits
 * an embed. `disabled` is a withdrawn row kept so it can be turned back on without retyping.
 */
export const BOT_DOMAIN_STATUSES = ['pending', 'active', 'disabled'] as const;

/**
 * `bail|required|string|in:"pending","active","disabled"` — the whole PATCH body.
 *
 * THE ORIGIN IS IMMUTABLE and this request declares no rule for it, so `strictObject` makes an edit
 * that tried to correct a typo a parse failure rather than a silent strip. Editing an origin in
 * place would change what a live grant points at while every audit row naming it still read the old
 * string; the console deletes the row and adds the right one.
 *
 * IT IS MIRRORED RATHER THAN EXEMPTED FOR THE REASON `providerConnectionEditSchema` IS: a fourth
 * lifecycle value added server-side would otherwise become a `<Select>` that cannot express a value
 * the API returns, with nothing red anywhere.
 */
export const botDomainStatusSchema = z.strictObject({
  status: z.enum(BOT_DOMAIN_STATUSES),
});

export type BotDomainStatusIn = z.input<typeof botDomainStatusSchema>;
export type BotDomainStatusOut = z.output<typeof botDomainStatusSchema>;

/**
 * What the status control opens on: the state the row is IN.
 *
 * A narrow pick like every other defaults factory here, never `reset(resource)` — `BotDomainResource`
 * carries `id`, `permits_embedding` and both timestamps, and `permits_embedding` is the one that
 * would not be harmless: it is DERIVED from `status`, so round-tripping it would post a second,
 * stale spelling of the field being changed.
 */
export const botDomainStatusDefaults = (domain: {
  readonly status: (typeof BOT_DOMAIN_STATUSES)[number];
}): BotDomainStatusIn => ({ status: domain.status });
