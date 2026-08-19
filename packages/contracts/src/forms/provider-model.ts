import { z } from 'zod';

/**
 * Mirrors `App\Http\Requests\StoreProviderModelRequest` and
 * `App\Http\Requests\UpdateProviderModelRequest`
 * (POST and PUT `/api/v1/organizations/{organization}/provider-connections/{providerConnection}/models`).
 *
 * They MIRROR the FormRequests; they do not enforce them. Client validation is a UX affordance and the
 * FormRequest is the authority (rhf-zod-forms NN2). `test/form-drift.test.ts` probes both schemas
 * against the manifests dumped from Laravel's own `rules()`, which is what keeps that claim honest.
 *
 * ── WHY THESE MAY BE SHARED WHEN THE TWO CREDENTIAL REQUESTS MAY NOT ────────────────────────────
 * `StoreProviderConnectionRequest` and `RotateProviderCredentialRequest` are `NO_CLIENT_FORM` entries
 * because their bodies carry the plaintext provider key, and a shared importable schema naming that
 * field is one `defaults(resource)` away from seeding `masked_key` back as the new key. NEITHER
 * REQUEST HERE HAS A SECRET IN IT: a catalog row is an identifier, a display name, capability claims,
 * two token counts, two list prices and a currency. `ProviderModelController` imports no vault, the
 * service it calls imports no vault, and `ProviderModelResource` renders no field of the parent
 * connection except its ULID. So the argument that exempts those two does not reach these, and what
 * is left is exactly the kind of rule that drifts in silence — a decimal SCALE, a price CEILING, an
 * element cap on an array — with nothing red anywhere when it moves.
 *
 * ── TWO SCHEMAS, NOT ONE WITH `.partial()`, BECAUSE THE TWO REQUESTS REALLY DIFFER ──────────────
 * `update` is a PUT AND A BODY THAT CHANGES NOTHING IS A 422: with seven mutable attributes, "must
 * change something" is expressible in `rules()` only as `required_without_all` naming six siblings on
 * each of seven fields, and the readable alternative — an `after()` closure — is INVISIBLE to
 * `kb:dump-form-rules`, so a generated client would never be told the constraint exists. The server
 * therefore demands the FULL attribute set on every edit, and the differences show up here as three
 * `present` rules the create request does not have:
 *
 *   - `enabled` is `sometimes|boolean` on create and `required|boolean` on update.
 *   - the two prices and `price_currency` are `present|nullable` on update and merely `nullable` on
 *     create, so the key may be omitted there and may not be here.
 *
 * A single `.partial()`-ed schema would express none of that, and the drift harness would report the
 * looser half as a form accepting input the server rejects.
 *
 * `model` IS ABSENT FROM THE EDIT SCHEMA and cannot be added: the identifier is half of the
 * vector-space identity for everything already embedded through the row, `organizations.embedding_model`
 * references it as a bare string with no foreign key, and nothing in the database would follow a
 * rename. `strictObject` therefore makes `edit({...row})` a parse FAILURE rather than a silent strip.
 */

/**
 * `max:200` on both string identifiers, `max:64` per capability flag, `max:20` flags, and a token
 * ceiling of one hundred million. Named rather than inlined because every one of them is a SERVER
 * number this file is mirroring, and a literal repeated at two call sites is how the two spellings
 * start to disagree.
 */
const MODEL_ID_MAX = 200;
const DISPLAY_NAME_MAX = 200;
const CAPABILITY_FLAG_MAX = 64;
const SUPPORTED_MAX = 20;
const TOKEN_MAX = 100_000_000;

/**
 * `numeric|decimal:0,6|min:0|max:1000000`, and the ceiling is DELIBERATELY LOWER THAN THE COLUMN'S.
 *
 * `provider_models.input_price_per_million` is `numeric(14, 6)`, which holds up to 99,999,999.999999.
 * Both FormRequests stop at 1,000,000 — already absurd for a per-million-token list price — and the
 * gap is the design: if the two were flush, the boundary value would pass validation and then raise
 * SQLSTATE 22003 from the driver, rendered as a 500. A bug report about the server, for a value the
 * form said was fine. THIS SCHEMA MIRRORS THE FORM'S NUMBER AND NOT THE COLUMN'S, because the refusal
 * has to happen where there is a field to key it on.
 */
const PRICE_MAX = 1_000_000;
const PRICE_SCALE = 6;

/** Three upper-case letters. `size:3` is co-declared server-side and is implied by this pattern. */
const CURRENCY = /^[A-Z]{3}$/;

/**
 * Laravel's OWN pattern from `ValidatesAttributes::validateDecimal`, transcribed rather than
 * approximated: `preg_match('/^[+-]?\d*\.?(\d*)$/')`, then `strlen()` of the trailing group.
 *
 * The two halves of the server's rule are genuinely different questions and this file asks both.
 * `numeric` is PHP's `is_numeric`, which accepts `1e3`; `decimal` is the pattern above, which does
 * NOT — there is no exponent branch in it. So `'1e3'` passes one rule and fails the other, and a
 * client that checked only `Number.isFinite` would accept it.
 */
const DECIMAL = /^[+-]?\d*\.?\d*$/;

const decimalPlaces = (text: string): number => {
  const point = text.indexOf('.');
  return point === -1 ? 0 : text.length - point - 1;
};

/**
 * Is this string a value the SERVER's four price rules all accept?
 *
 * Operates on the STRING and never on a parsed float, which is the whole point: `Number('0.1') + …`
 * is where the exactness is lost, and the scale check in particular is a question about the text.
 * The magnitude comparisons go through `Number` because a numeric comparison is what `min:`/`max:`
 * are, and at six places under a million every value is exactly representable — the hazard is
 * ACCUMULATION over a month of usage, not one comparison.
 */
const isMirrorablePrice = (text: string): boolean => {
  // `Number('')` is 0, not NaN, so the empty string would read as a valid zero here. It is already
  // `null` by the time this runs (see `price`); the guard is belt, because the failure is silent.
  if (text === '' || !DECIMAL.test(text)) return false;
  if (decimalPlaces(text) > PRICE_SCALE) return false;

  // `'.'` and `'+'` both satisfy the pattern above and are NaN here, which is why the finite check
  // is not redundant with it.
  const magnitude = Number(text);
  return Number.isFinite(magnitude) && magnitude >= 0 && magnitude <= PRICE_MAX;
};

/**
 * A price, and the reason it is a UNION rather than a `z.number()` is the entire pricing hazard in
 * one line.
 *
 * WHAT THE FORM HOLDS IS A STRING, always: the operator types `0.02`, the server stores it as
 * `numeric(14, 6)` and answers `'0.020000'`, and a round-trip through a JS `number` would re-scale
 * it silently — `'0.020000'` becomes `0.02` becomes `'0.02'`, which is the same amount today and is
 * a different string in an audit row and a diff. So the OUTPUT of this schema is `string | null`,
 * exactly what came off the wire or exactly what was typed, and nothing here parses one into a float
 * for re-serialization.
 *
 * WHAT THE SERVER ACCEPTS IS BOTH, because Laravel's `numeric` accepts a numeric string and a JSON
 * number alike. The union mirrors that rather than being stricter than the server — and it is also
 * what makes the drift harness's `decimal:0,6` probes meaningful: those probes pass real JS numbers,
 * and a string-only schema would report a disagreement that is this schema's invention rather than
 * the server's.
 *
 * THE EMPTY STRING IS NULL, not a zero and not a validation error. Laravel's global
 * `ConvertEmptyStringsToNull` runs before every rule, so a cleared input posts `''` and the server
 * sees `null`; without this the client would accept `{input_price_per_million: '', price_currency:
 * null}` while the server evaluates `required_with` against a null and agrees — but it would also
 * read `''` as a value in the "is a price present" question below, which is the direction that
 * matters.
 */
const price = z
  .union([z.string(), z.number()])
  .nullable()
  .transform((value) => {
    if (value === null) return null;
    if (typeof value === 'number') return String(value);
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
  })
  .refine((value) => value === null || isMirrorablePrice(value), {
    error:
      'Enter a price as a plain decimal with at most six decimal places, between 0 and 1,000,000 ' +
      'per million tokens. Leave it empty if the vendor has not published one.',
  });

/**
 * `nullable|string|size:3|regex:/^[A-Z]{3}$/`, plus `required_with` BOTH prices — which is a
 * cross-field rule and therefore lives in the object's `superRefine` below rather than here.
 *
 * Empty string to null for the same `ConvertEmptyStringsToNull` reason as `price`. Upper case is NOT
 * coerced: the server's regex refuses `usd` rather than folding it, and a client that quietly
 * upper-cased would be accepting input the server rejects — the exact drift direction this package
 * exists to catch. The form says so in its description instead.
 */
const currency = z
  .string()
  .nullable()
  .transform((value) => {
    if (value === null) return null;
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
  })
  .refine((value) => value === null || CURRENCY.test(value), {
    error: 'A currency code is three upper-case letters, like USD.',
  });

/**
 * `required|integer|min:0|max:100000000`.
 *
 * ZERO IS LEGAL AND MEANS "NOT RECORDED", which is why there is no `.min(1)` and why the form says so
 * beside the input: a limit of zero would be a model that can emit nothing, and an operator who has
 * not looked the number up needs a value to save.
 *
 * NOT `z.coerce.number()`. Coercion turns `null` into 0 and `''` into 0, so an explicitly-null field
 * the server rejects would be accepted here as a valid zero — a form accepting input the server
 * rejects, and one the drift harness would catch only because it probes null. The form registers
 * these inputs with `valueAsNumber` instead, so an empty control is `NaN` and fails loudly.
 */
const tokenCount = z
  .number({ error: 'Enter a whole number of tokens, or 0 if the vendor does not publish one.' })
  .int('Token counts are whole numbers.')
  .min(0, 'Token counts cannot be negative.')
  .max(TOKEN_MAX, 'That is larger than any published context window; check the vendor’s figure.');

/** `required|string|max:200` — `required` refuses the empty string, which is what `.min(1)` mirrors. */
const modelIdentifier = z
  .string()
  .trim()
  .min(1, 'Enter the vendor’s official model identifier.')
  .max(MODEL_ID_MAX);

const displayName = z
  .string()
  .trim()
  .min(1, 'Give this model a name your team will recognise.')
  .max(DISPLAY_NAME_MAX);

/**
 * A capability flag's SHAPE, and the only thing about a flag either side checks.
 *
 * `^[a-z][a-z0-9_]*$` is the lexical form every member of the data plane's `Capability` StrEnum
 * already has (`text`, `tool_use`, `stream_usage`, …) — it is a claim about spelling, not about
 * membership, so a flag the data plane adds next week still passes. That distinction is the whole
 * design; see the note on `supported`.
 */
const CAPABILITY_FLAG = /^[a-z][a-z0-9_]*$/;

/**
 * `present|array|max:20`, with each member `string|max:64|regex:/^[a-z][a-z0-9_]*$/`.
 *
 * THE ELEMENT IS A SHAPE AND NOT AN ENUM, and that is still a deliberate refusal to be stricter than
 * the server — the constraint moved, it did not close. The closed vocabulary is the data plane's
 * `Capability` StrEnum; Laravel validates the members as lower-snake identifiers and nothing more,
 * and the OpenAPI document publishes `array<string>` with no enum for the same reason. A `z.enum(...)`
 * here would reject a flag the data plane added last week — functionality removed with nothing
 * reported. A pattern rejects nothing the data plane could ever emit.
 *
 * WHY THE SERVER GREW THE PATTERN: `supported` is echoed verbatim into the model row's audit detail,
 * so an unconstrained element let a tenant write an arbitrary attacker-chosen string — including a
 * key-shaped one — into an operator-facing field that reviewers read as trustworthy. Bounding the
 * alphabet costs the vocabulary nothing and removes the smuggling channel.
 *
 * The admin console still offers a closed checkbox list, declared locally beside the form that
 * renders it, and preserves an unrecognised flag rather than dropping it on save. That is a UI
 * affordance over an open wire vocabulary; this is the contract.
 *
 * `present` means the KEY must exist: an omitted `supported` is a 422, so a form that renders no
 * capability control still posts `[]`.
 *
 * THE PATTERN COSTS THE DRIFT HARNESS ITS GENERIC SIZER, which is why both model Mirrors in
 * test/form-drift.test.ts now declare a `sized` generator for `supported.*`: `regex` is a
 * `FORMAT_RULES` member, so `sizerFor` would otherwise return `undefined` and silently drop both
 * `max:64` probes. That suppression is caught by `missingSizeProbes()` rather than being invisible.
 */
const supported = z
  .array(z.string().max(CAPABILITY_FLAG_MAX).regex(CAPABILITY_FLAG))
  .max(SUPPORTED_MAX);

/**
 * `price_currency` is `required_with:input_price_per_million,output_price_per_million` — a price may
 * not exist without a currency, and a currency may exist alone.
 *
 * THE ONE-DIRECTIONALITY IS THE POINT AND IS REPEATED THREE TIMES SERVER-SIDE: the FormRequest rule,
 * the `provider_models_price_needs_currency` CHECK constraint, and the resource's own documentation.
 * A bare `15.00` is not an amount — an organization billed by an EU reseller and one billed directly
 * would both store `15.00`, a report would add them, and the sum would be a number in no currency at
 * all. A currency with no prices is the state of a row whose operator recorded the billing currency
 * before looking the prices up, and refusing it would make the form unfillable in the order a human
 * fills it.
 *
 * IT IS A `superRefine` RATHER THAN A FIELD RULE because the verdict depends on a sibling, and the
 * drift harness classifies `required_with` as CROSS_FIELD and SUPPRESSES the presence probes for
 * every field carrying one — it cannot answer "is this field required?" from one field's rule list.
 * So this refinement is asserted by hand in that file's cross-field section rather than by a
 * generated probe, which is also why the message is written for a person.
 *
 * The path is `price_currency` because that is the control the operator has to change: the prices
 * they typed are not the mistake.
 */
const priceNeedsCurrency = (
  value: {
    readonly input_price_per_million?: string | null;
    readonly output_price_per_million?: string | null;
    readonly price_currency?: string | null;
  },
  ctx: z.RefinementCtx,
): void => {
  const priced =
    (value.input_price_per_million ?? null) !== null ||
    (value.output_price_per_million ?? null) !== null;

  if (priced && (value.price_currency ?? null) === null) {
    ctx.addIssue({
      code: 'custom',
      path: ['price_currency'],
      message:
        'A price needs a currency: two organizations billed in different currencies would both ' +
        'store the same number, and a spend estimate would add them together.',
    });
  }
};

/**
 * POST — register one model under this connection.
 *
 * `enabled` is `.optional()` because the server rules it `sometimes|boolean`: omitting it takes the
 * column default rather than being a 422, and `sometimes` short-circuits every other rule on the
 * field when the key is absent. The form renders the switch and always sends it; the schema mirrors
 * what the SERVER permits, not what this form happens to do.
 *
 * The prices and the currency are `.optional()` for the same reason in reverse: neither carries
 * `present` on this request, so an omitted key is accepted — which is the shape of a create form that
 * has not been given a pricing section yet.
 */
export const providerModelCreateSchema = z
  .strictObject({
    model: modelIdentifier,
    display_name: displayName,
    supported,
    context_window: tokenCount,
    max_output_tokens: tokenCount,
    enabled: z.boolean().optional(),
    input_price_per_million: price.optional(),
    output_price_per_million: price.optional(),
    price_currency: currency.optional(),
  })
  .superRefine(priceNeedsCurrency);

export type ProviderModelCreateIn = z.input<typeof providerModelCreateSchema>;
export type ProviderModelCreateOut = z.output<typeof providerModelCreateSchema>;

/**
 * PUT — replace the row's mutable attributes. EVERY FIELD IS REQUIRED-OR-PRESENT, which is what makes
 * this a replace rather than a patch, and it is why the edit form seeds every control from the loaded
 * row: a partial submit is a 422, not a merge.
 */
export const providerModelEditSchema = z
  .strictObject({
    display_name: displayName,
    supported,
    context_window: tokenCount,
    max_output_tokens: tokenCount,
    enabled: z.boolean(),
    input_price_per_million: price,
    output_price_per_million: price,
    price_currency: currency,
  })
  .superRefine(priceNeedsCurrency);

export type ProviderModelEditIn = z.input<typeof providerModelEditSchema>;
export type ProviderModelEditOut = z.output<typeof providerModelEditSchema>;

/**
 * The narrow shape the defaults factory reads out of `ProviderModelResource`. STRUCTURAL ON PURPOSE
 * and NOT the resource type itself — the same load-bearing line `ProviderConnectionEditSource`
 * carries, for a different field.
 *
 * `reset({...row})` keeps every key it is handed, so a spread would put `id`, `connection_id`,
 * `created_at` AND `model` into form state. The first three are ownership-adjacent identifiers no
 * form may submit; `model` is the immutable identifier, and a form holding it is one input away from
 * offering to rename the vector space everything under this row was embedded into. An eight-member
 * structural parameter makes that spread a typecheck failure rather than a review question.
 */
export interface ProviderModelEditSource {
  readonly display_name: string;
  readonly supported: readonly string[];
  readonly context_window: number;
  readonly max_output_tokens: number;
  readonly enabled: boolean;
  readonly input_price_per_million: string | null;
  readonly output_price_per_million: string | null;
  readonly price_currency: string | null;
}

/**
 * NEVER `reset(resource)`. See `ProviderModelEditSource`.
 *
 * IT RETURNS THE SCHEMA'S **OUTPUT** TYPE, DELIBERATELY, and it is the one factory in this package
 * that does. Every field of a loaded row is already output-shaped — the prices are exact decimal
 * strings, `supported` is an array of strings — and `ProviderModelEditOut` is assignable to
 * `ProviderModelEditIn` field for field, so this one value serves as BOTH the form's `defaultValues`
 * AND a complete PUT body.
 *
 * That second use is what the inline `enabled` toggle in the table needs: the endpoint refuses a
 * partial body, so flipping one switch has to re-send the other seven attributes, and building them
 * by hand at that call site is how a price gets re-scaled or a capability flag gets dropped by a
 * spread that missed one. `{...providerModelEditDefaults(row), enabled: next}` cannot miss one,
 * because the return type says so.
 *
 * `supported` IS COPIED rather than passed through. The resource's array is `readonly` and shared
 * with the query cache; handing it to a form that then toggles a checkbox would mutate the cached
 * row in place, and TanStack Query would compare the "new" data against a value that had already
 * changed.
 */
export const providerModelEditDefaults = (
  source: ProviderModelEditSource,
): ProviderModelEditOut => ({
  display_name: source.display_name,
  supported: [...source.supported],
  context_window: source.context_window,
  max_output_tokens: source.max_output_tokens,
  enabled: source.enabled,
  input_price_per_million: source.input_price_per_million,
  output_price_per_million: source.output_price_per_million,
  price_currency: source.price_currency,
});

/**
 * What an empty create form holds.
 *
 * The token counts start at ZERO rather than empty, because zero is a legal value that means "not
 * recorded" and an empty numeric input registered with `valueAsNumber` is `NaN` — which fails
 * validation on a field the operator has not reached yet. The prices start as EMPTY STRINGS, which
 * the schema turns into `null`: that is the "no price recorded" state, and it is not the same as free.
 *
 * `enabled` starts TRUE. Registering a model in order to leave it switched off is the rarer act, and
 * the switch is right there.
 */
export const providerModelCreateDefaults = (): ProviderModelCreateIn => ({
  model: '',
  display_name: '',
  supported: [],
  context_window: 0,
  max_output_tokens: 0,
  enabled: true,
  input_price_per_million: '',
  output_price_per_million: '',
  price_currency: '',
});
