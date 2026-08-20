/**
 * READING A LIST ENDPOINT'S BOUNDS OUT OF THE SERVER'S OWN DUMPED RULES, AND NOTHING ELSE.
 *
 * ── THIS FILE IS A MOVE, AND THE RULE THAT FORCED IT IS THE REPO'S OWN ──────────────────────────
 * Both functions were written as private declarations in `features/bots/api.ts` while the bot list
 * was the only server-driven table in the console. The sources list is the second, and this codebase
 * has already paid for the alternative once: `kb-ui-patterns`' own note about a private copy of a
 * shared helper, and `lib/api/browser.ts`'s record of `browserFetchData` being MOVED rather than
 * copied when a second feature needed it. A second parse of the same manifest shape is a second
 * place the manifest's grammar is known, and the two would drift silently — a parse that yields an
 * empty set does not throw, it makes every header unclickable.
 *
 * So `features/bots/api.ts` now imports these instead of declaring them, `features/sources/api.ts`
 * imports the same two, and `tests/unit/bot-list.test.ts` keeps pinning the bot list's parsed values
 * through its own module exactly as before.
 *
 * ── WHY A DERIVATION AT ALL, RATHER THAN A LIST IN THE CLIENT ───────────────────────────────────
 * `sort` reaches an `ORDER BY`, so every list FormRequest closes its sortable set with `Rule::in(…)`
 * and caps `per_page`. `php artisan kb:dump-form-rules` writes that set to
 * `packages/contracts/rules/Index*Request.json`, which is the only place a client learns it exists.
 * A hand-copied list is a 422 one header click away the moment the endpoint's set moves — and the
 * failure lands on a request the user made by clicking a control we drew.
 *
 * NEITHER FUNCTION THROWS. A manifest whose SHAPE changed yields an empty set or a `null` bound, and
 * `assertTableParamsConfig` refuses the resulting config loudly at the call site instead — one place
 * to look rather than a parse error in a module nobody imported deliberately.
 */

const IN_RULE_PREFIX = 'in:';
const MAX_RULE_PREFIX = 'max:';

/**
 * `in:"id","name","slug","status"` -> the four bare strings, in the manifest's order.
 *
 * Laravel writes the quotes; `Rule::in()` renders each value with `"` around it whether or not the
 * value needs one, so the unquoting is unconditional and anchored rather than a `replaceAll`.
 */
export function enumFromRule(rules: readonly string[] | undefined): readonly string[] {
  const rule = rules?.find((entry) => entry.startsWith(IN_RULE_PREFIX));
  if (rule === undefined) return [];
  return rule
    .slice(IN_RULE_PREFIX.length)
    .split(',')
    .map((value) => value.trim().replace(/^"(.*)"$/, '$1'))
    .filter((value) => value !== '');
}

/** `max:200` -> 200. `null` when the rule carries no numeric bound. */
export function maxFromRule(rules: readonly string[] | undefined): number | null {
  const rule = rules?.find((entry) => entry.startsWith(MAX_RULE_PREFIX));
  if (rule === undefined) return null;
  const bound = Number.parseInt(rule.slice(MAX_RULE_PREFIX.length), 10);
  return Number.isSafeInteger(bound) ? bound : null;
}
