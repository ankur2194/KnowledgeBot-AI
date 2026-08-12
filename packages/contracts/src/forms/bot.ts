import { z } from 'zod';

/**
 * Mirrors `UpdateBotRequest` in services/core-api (PATCH /api/v1/organizations/{organization}/bots/{bot}).
 * It MIRRORS it; it does not enforce it. Client validation is a UX affordance — the FormRequest is
 * the authority, and the drift test in test/form-drift.test.ts is what keeps the two honest.
 */

/**
 * Empty number inputs post "". `Number("")` is 0, so a bare `z.coerce.number().min(1)` says
 * "must be at least 1" on a CLEARED field instead of "required".
 */
const intField = (min: number, max: number) =>
  z.preprocess(
    (v) => (v === '' || v === null ? undefined : v),
    z.coerce.number().int().min(min).max(max),
  );

/**
 * `strictObject`, not `object`: an unknown key means the defaults builder leaked a server field
 * into form state. `z.object()` strips it silently and hides the bug until something bypasses the
 * parse — FormData uploads do. Ownership keys are unrepresentable here by construction.
 *
 * Every string field carries `.trim()` because Laravel's global `TrimStrings` middleware runs
 * before `max:120`: "  " + 119 chars is 119 server-side and 121 in the browser.
 */
export const botSettingsSchema = z.strictObject({
  name: z.string().trim().min(1, { error: 'Name is required' }).max(120),
  status: z.enum(['draft', 'testing', 'published', 'paused', 'archived']),
  welcome_message: z.string().trim().max(500),
  starter_questions: z.array(z.string().trim().min(1).max(200)).max(6),
  retrieval: z.strictObject({ top_k: intField(1, 50) }),
});

export type BotSettingsIn = z.input<typeof botSettingsSchema>;
export type BotSettingsOut = z.output<typeof botSettingsSchema>;

/**
 * The narrow shape `botFormDefaults` reads. Structural on purpose: the full `BotResource` lands in
 * src/resources/ once the control plane publishes its OpenAPI document, and this pick must keep
 * compiling against it without importing every counter and timestamp it carries.
 */
export interface BotFormSource {
  readonly name: string;
  readonly status: BotSettingsIn['status'];
  readonly welcome_message: string;
  readonly starter_questions: readonly string[];
  readonly retrieval: { readonly top_k: number };
}

/**
 * NEVER `reset(resource)`. The API Resource carries id, organization_id, timestamps and counters;
 * `reset()` replaces form state with exactly what it is handed, `getValues()` returns those keys,
 * and submit posts them back — a 200, an audit row, and no change. This pick is the ONLY path from
 * server data into form state.
 */
export const botFormDefaults = (bot: BotFormSource): BotSettingsIn => ({
  name: bot.name,
  status: bot.status,
  welcome_message: bot.welcome_message,
  starter_questions: [...bot.starter_questions],
  retrieval: { top_k: bot.retrieval.top_k },
});
