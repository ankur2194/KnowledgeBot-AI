/**
 * Laravel validation primitives transcribed once, for the schemas behind `@kb/contracts/forms`.
 *
 * A rule that appears in more than one dumped manifest gets exactly one spelling here. The
 * alternative is what `test/form-drift.test.ts` warns about beside the capability pattern — "a second
 * literal here is how the two spellings start to disagree" — and the disagreement is invisible,
 * because each copy is separately green against its own manifest right up until one of them is
 * edited.
 *
 * NOTHING IN THIS MODULE IS RE-EXPORTED FROM `src/index.ts`. The root entry is budgeted at <=1 kB
 * brotli inside apps/widget's app shell and may not touch Zod or anything that imports it; these are
 * plain regexes, but they belong to the forms subpath and stay there (ADR-028).
 */

/**
 * Laravel's `ulid` rule is `Str::isUlid()` → `Symfony\Component\Uid\Ulid::isValid()`: 26 Crockford
 * base32 characters (no I, L, O, U), either case, AND a first character whose uppercase form is
 * <= '7' — the 48-bit timestamp cannot overflow.
 *
 * NOT `z.ulid()`. zod 4.4.3's regex is `/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/` with no overflow
 * guard, so it accepts 26 'Z's, which the server rejects. That is the loose direction — a visible
 * 422 rather than lost functionality — but it is still a disagreement, and the drift suite probes
 * exactly that value on every manifest carrying a `ulid` rule.
 *
 * FOUR FIELDS CARRY THAT RULE TODAY across three manifests (`DesignateEmbeddingConnectionRequest`'s
 * `connection_id`, and `provider_connection_id`/`provider_model_id` on both bot requests), which is
 * why this lives here rather than beside the first schema that needed it.
 */
export const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i;
