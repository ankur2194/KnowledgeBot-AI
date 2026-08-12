import { z } from 'zod';

/**
 * Mirrors `DesignateEmbeddingConnectionRequest` in services/core-api
 * (PUT /api/v1/organizations/{organization}/embedding-configuration).
 *
 * It MIRRORS it; it does not enforce it. The FormRequest is the authority and
 * test/form-drift.test.ts, probing this schema against rules/DesignateEmbeddingConnectionRequest.json,
 * is what keeps the two honest.
 *
 * THE DESIGNATION IS A CONNECTION *REFERENCE*, NEVER KEY MATERIAL. `(connection_id, model)` names
 * which stored credential pays and which vector space the corpus is indexed under; the plaintext
 * key belongs to a different endpoint and never appears in this form, in its defaults, or in its
 * payload. `organization_id` appears nowhere either — it comes from the authenticated context
 * (kb-tenancy-isolation NN6), and a client that posts one is attempting escalation for a silent 200.
 */

/**
 * Laravel's `ulid` rule is `Str::isUlid()` → `Symfony\Component\Uid\Ulid::isValid()`: 26 Crockford
 * base32 characters (no I, L, O, U), either case, AND a first character whose uppercase form is
 * <= '7' — the 48-bit timestamp cannot overflow.
 *
 * NOT `z.ulid()`. zod 4.4.3's regex is `/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/` with no overflow
 * guard, so it accepts 26 'Z's, which the server rejects. That is the loose direction — a visible
 * 422 rather than lost functionality — but it is still a disagreement, and the drift suite probes
 * exactly that value.
 */
const ULID = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i;

/**
 * Laravel's global `ConvertEmptyStringsToNull` turns "" into null BEFORE any rule runs, and
 * `TrimStrings` runs before `max:`. A cleared `<select>` posts "", so without this preprocess the
 * client would accept `{connection_id: "01J…", model: ""}` while the server sees `model: null` and
 * 422s on `required_with` — the "submits cleanly, server rejects" case (rhf-zod-forms).
 */
const clearable = <T extends z.ZodType>(inner: T) =>
  z.preprocess((value) => (value === '' ? null : value), inner);

const connectionId = z
  .string()
  .trim()
  .regex(ULID, { error: 'Select a provider connection.' })
  .nullable();

const model = z.string().trim().max(200).nullable();

/**
 * `strictObject`, and BOTH KEYS ARE REQUIRED even though both values are nullable: the server's
 * rule is `present|nullable`, so clearing the designation is `{connection_id: null, model: null}`
 * — the same endpoint, not a second one and not an omitted key.
 *
 * The pair rule is `required_with` in both directions, which the database repeats one layer down as
 * `CHECK num_nonnulls(...) <> 1`. Half a designation names either no credential or no vector space,
 * and it would be discovered at the first upload.
 */
export const embeddingDesignationSchema = z
  .strictObject({
    connection_id: clearable(connectionId),
    model: clearable(model),
  })
  .superRefine((value, ctx) => {
    if (value.connection_id !== null && value.model === null) {
      ctx.addIssue({
        code: 'custom',
        path: ['model'],
        message:
          'An embedding designation names a connection AND a model: one connection can carry two ' +
          'embedding models, and those are two different vector spaces.',
      });
    }

    if (value.model !== null && value.connection_id === null) {
      ctx.addIssue({
        code: 'custom',
        path: ['connection_id'],
        message:
          'An embedding designation names a connection AND a model: a model with no connection ' +
          'names no credential.',
      });
    }
  });

export type EmbeddingDesignationIn = z.input<typeof embeddingDesignationSchema>;
export type EmbeddingDesignationOut = z.output<typeof embeddingDesignationSchema>;

/**
 * The narrow shape `embeddingDesignationDefaults` reads out of the GET response. Structural on
 * purpose: the generated `EmbeddingReadinessResource` type lands in src/resources/ from the OpenAPI
 * document (control-plane-engineer owes it), and this pick must keep compiling against it without
 * importing the eligible/rejected/explanation payload the banner renders.
 */
export interface EmbeddingDesignationSource {
  readonly selected: {
    readonly connection_id: string;
    readonly model: string;
  } | null;
}

/**
 * NEVER `reset(response)`. The readiness response carries `eligible`, `rejected`, `explanation` and
 * `blocks_ingestion`; `reset()` keeps every key it is handed and submit posts them back. This pick
 * is the ONLY path from server data into form state, and it can only reach two fields.
 */
export const embeddingDesignationDefaults = (
  source: EmbeddingDesignationSource,
): EmbeddingDesignationIn => ({
  connection_id: source.selected?.connection_id ?? null,
  model: source.selected?.model ?? null,
});
