import type { BotResource } from '@kb/contracts';
import { botFormDefaults } from '@kb/contracts/forms';
import updateBotRules from '@kb/contracts/rules/UpdateBotRequest.json';
import storeBotRules from '@kb/contracts/rules/StoreBotRequest.json';
import { describe, expect, it } from 'vitest';

import {
  BOT_CREATE_KNOWN_PATHS,
  BOT_CREATE_RENDERED_FIELDS,
  BOT_IDENTITY_FIELDS,
  BOT_MODEL_FIELDS,
  BOT_PUBLISHING_FIELDS,
  BOT_STATUS_KNOWN_PATHS,
  botPanelDefaults,
  botPanelKnownPaths,
  botPath,
  botStatusDisplay,
  botStatusPath,
  botsPath,
  canManageBots,
  type BotSettingsField,
} from '@/features/bots/api';

/**
 * THE BOT EDITOR'S PUBLISHED CONTRACT — the half of it a `node` project can hold still.
 *
 * ── WHY THIS SPEC EXISTS AT ALL ──────────────────────────────────────────────────────────────────
 * `/bots/{id}` is one resource edited through three tabs written by three people who cannot see each
 * other's work, and the thing that keeps three independent implementations from colliding is a FIELD
 * PARTITION: three tuples that are disjoint and whose union is exactly the PATCH's key set. Neither
 * half is visible at runtime when it breaks. A field in two tuples is two saves racing over one
 * column; a field in none is a column no console can ever reach, and the symptom of both is a screen
 * that renders correctly.
 *
 * So the partition is asserted against TWO independent sources — `botFormDefaults`, which is the
 * schema side, and `UpdateBotRequest.json`, which is the SERVER'S own dumped `rules()` — and a field
 * added on either side fails here by name.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about tenant isolation. `botPath` takes an `orgId` and puts it in a PATH, where it is a
 * routing hint Laravel re-derives from the session cookie anyway; the org NAMESPACE lives in the
 * query key its caller builds, which is `tests/components/bot-editor-screen.test.tsx`'s claim. And
 * "organization A's bot never renders after a switch" is Playwright's, against a real server.
 */

/** The manifest shape `php artisan kb:dump-form-rules` writes. */
interface Manifest {
  readonly class: string;
  readonly rules: Readonly<Record<string, readonly string[]>>;
}

/** `theme.primary` -> `theme`. The tuples name top-level fields; the manifest keys nested ones. */
const root = (path: string): string => path.split('.')[0] ?? path;

/**
 * A path the FormRequest declares ONLY IN ORDER TO REFUSE IT — `prohibited`.
 *
 * `UpdateBotRequest.status` is the one instance in this repo: a lifecycle move became
 * `PUT .../bots/{bot}/status`, and the rule is present rather than the field being deleted from
 * `rules()` because an ABSENT rule makes `validated()` discard the key in silence — the console
 * would publish a bot, get a 200, and find it still in draft.
 *
 * SUBTRACTED FROM THE MANIFEST SIDE OF EVERY ASSERTION BELOW, and this is a STRENGTHENING rather
 * than a loophole. The partition is "the fields the three tabs may send", and a prohibited field is
 * one no tab may send: a tuple naming it would be a control whose every use is a 422. So the union
 * must equal the manifest's VALIDATED key set, and it stays closed in both directions — a tuple that
 * re-acquired `status` would be a superset and fail here, exactly as it fails the `satisfies` in
 * `api.ts` and the schema-side assertion below.
 */
const prohibited = (rules: readonly string[]): boolean =>
  rules.some((rule) => rule.split(':')[0] === 'prohibited');

const manifestFields = (manifest: Manifest): readonly string[] => [
  ...new Set(
    Object.entries(manifest.rules)
      .filter(([, rules]) => !prohibited(rules))
      .map(([path]) => root(path)),
  ),
];

/**
 * A complete row. Every field the resource publishes is present, because `botFormDefaults` reads
 * twenty-four of them and a partial fixture would make the union assertion pass for the wrong reason.
 *
 * `status` IS ON THE FIXTURE AND IS READ BY NOTHING HERE, deliberately: a real `BotResource` has one,
 * and the pick dropping it is a property worth exercising against a row that could have leaked it
 * rather than against a fixture that never had it.
 *
 * The two instruction fields carry canary text: they are a MANAGEMENT-ONLY PROJECTION, so a caller
 * without `bots.manage` receives `null` for both — which means "not shown to you", not "not set".
 */
const BOT: BotResource = {
  id: '01JBOTAAAAAAAAAAAAAAAAAAAA',
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: 'Answers billing and account questions.',
  welcome_message: null,
  placeholder_text: null,
  system_instruction: 'CANARY-SYSTEM-INSTRUCTION',
  answer_style_instruction: 'CANARY-ANSWER-STYLE',
  status: 'testing',
  access_mode: 'public',
  provider_connection_id: '01JCONNAAAAAAAAAAAAAAAAAAA',
  provider_model_id: '01JMODELAAAAAAAAAAAAAAAAAA',
  answer_mode: 'strict',
  dense_top_k: 40,
  sparse_top_k: 40,
  rerank_candidates: 25,
  rerank_retain: 8,
  evidence_threshold: 0.5,
  evidence_threshold_scale: 'logit',
  retrieval_configuration_version: 3,
  allow_general_answers: false,
  theme: { primary: 'oklch(0.55 0.18 265)' },
  rate_limit_per_minute: null,
  rate_limit_per_day: null,
  retention_days: 90,
  collect_end_user_data: false,
  consent_text: null,
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-02T09:00:00+00:00',
};

describe('the three panels partition the PATCH exactly once', () => {
  const tuples: readonly (readonly BotSettingsField[])[] = [
    BOT_IDENTITY_FIELDS,
    BOT_MODEL_FIELDS,
    BOT_PUBLISHING_FIELDS,
  ];
  const union = tuples.flat();

  it('names no field twice', () => {
    // A field in two tuples is two forms that both seed it and both send it: whichever tab saves
    // second silently overwrites the other with a value its operator never looked at.
    expect(union).toHaveLength(new Set(union).size);
  });

  it('covers every field `botFormDefaults` produces, which is the schema side', () => {
    // `botFormDefaults` is the ONE sanctioned path from server data into form state, and its pick is
    // exactly `botSettingsSchema`'s key set. A field it produces that no tuple names is a column no
    // tab can ever edit — nothing errors, the control simply does not exist.
    expect([...union].sort()).toEqual(Object.keys(botFormDefaults(BOT)).sort());
  });

  it('covers every field `UpdateBotRequest` rules, which is the SERVER side', () => {
    // The independent half. The schema could agree with the tuples and both disagree with Laravel;
    // this is the assertion that catches a field added to `rules()` and mirrored into the schema
    // while the console gained no control for it.
    expect([...union].sort()).toEqual([...manifestFields(updateBotRules as Manifest)].sort());
  });

  it('names no field the PATCH prohibits, which is where `status` went', () => {
    // The positive control on the subtraction above: with no prohibited key in the manifest the
    // filter is a no-op and every assertion in this block would pass whether or not it existed.
    const forbidden = Object.entries((updateBotRules as Manifest).rules)
      .filter(([, rules]) => prohibited(rules))
      .map(([path]) => path);

    expect(forbidden, 'UpdateBotRequest must still prohibit at least one path').toContain('status');
    for (const field of union) expect(forbidden).not.toContain(field);
  });

  it('leaves the transition its own endpoint, its own body and its own 422 key', () => {
    // A lifecycle move is `PUT .../bots/{bot}/status` and `updateBotStatus`, not a field beside a
    // rename — so the Publishing tab owns TWO saves, and the one name a transition 422 can be keyed
    // to comes from that request's own manifest rather than from `UpdateBotRequest`'s.
    expect(botStatusPath('01JORGAAAAAAAAAAAAAAAAAAAA', BOT.id)).toBe(
      `${botPath('01JORGAAAAAAAAAAAAAAAAAAAA', BOT.id)}/status`,
    );
    expect([...BOT_STATUS_KNOWN_PATHS]).toEqual(['status']);
    // …and that key is reachable from NO panel's set, which is what routes a `status` 422 on the
    // PATCH to the banner instead of to a control that displays nowhere.
    for (const tuple of tuples) expect(botPanelKnownPaths(tuple)).not.toContain('status');
  });

  it('keeps every cross-field rule inside one panel', () => {
    // Not decoration. `botSettingsSchema` judges each of these against a SIBLING, so a panel seeded
    // with one half of a pair can never satisfy its own resolver — and the failure is a form that
    // refuses to submit with a message about a field that is not on screen.
    const identity = new Set<string>(BOT_IDENTITY_FIELDS);
    const model = new Set<string>(BOT_MODEL_FIELDS);
    const publishing = new Set<string>(BOT_PUBLISHING_FIELDS);

    for (const pair of [
      ['evidence_threshold', 'evidence_threshold_scale'],
      ['provider_model_id', 'provider_connection_id'],
    ]) {
      expect(pair.every((field) => model.has(field))).toBe(true);
    }
    // `collect_end_user_data` + `consent_text` is enforced only by `BotService` and the database,
    // deliberately not by either FormRequest — but the two controls still have to be rendered
    // together, which means one tuple.
    expect(['collect_end_user_data', 'consent_text'].every((f) => publishing.has(f))).toBe(true);
    // The management-only projection belongs to exactly one tab, so exactly one tab has to reason
    // about `null` meaning "not shown to you".
    expect(identity.has('system_instruction')).toBe(true);
    expect(identity.has('answer_style_instruction')).toBe(true);
  });
});

describe('botPanelDefaults narrows the seed, which narrows the PATCH body', () => {
  it('returns exactly the tuple it was handed', () => {
    expect(Object.keys(botPanelDefaults(BOT, BOT_MODEL_FIELDS)).sort()).toEqual(
      [...BOT_MODEL_FIELDS].sort(),
    );
  });

  it('never carries a server-owned field into form state', () => {
    // `reset(resource)` is the failure this exists to make impossible: the API resource carries `id`,
    // `public_bot_id`, `retrieval_configuration_version` and both timestamps, `getValues()` returns
    // them, and submit posts them back — a 200, an audit row and no change. `public_bot_id` is the
    // one that would not be harmless: it is the token every live embed on the customer's own site
    // carries.
    const seeded = Object.keys({
      ...botPanelDefaults(BOT, BOT_IDENTITY_FIELDS),
      ...botPanelDefaults(BOT, BOT_MODEL_FIELDS),
      ...botPanelDefaults(BOT, BOT_PUBLISHING_FIELDS),
    });

    for (const owned of [
      'id',
      'public_bot_id',
      'retrieval_configuration_version',
      'created_at',
      'updated_at',
    ]) {
      expect(seeded).not.toContain(owned);
    }
  });

  it('copies the theme rather than sharing the cached row`s object', () => {
    // The resource's object is shared with the query cache. A form handed it would mutate the cached
    // row in place when a colour changed, and TanStack Query would then compare the "new" data
    // against a value that had already moved.
    const seeded = botPanelDefaults(BOT, BOT_IDENTITY_FIELDS);
    expect(seeded.theme).toEqual(BOT.theme);
    expect(seeded.theme).not.toBe(BOT.theme);
  });
});

describe('knownPaths decide which 422 keys can reach a control', () => {
  it('carries the nested theme paths with the field that owns them', () => {
    const identity = botPanelKnownPaths(BOT_IDENTITY_FIELDS);
    // `App\Rules\ReadableThemeColor`'s contrast half is deliberately NOT mirrored client-side, so a
    // colour in the unreachable band comes back as a 422 keyed `theme.primary`. It has to land under
    // the colour control, not in the banner.
    expect(identity).toContain('theme.primary');
    expect(identity).toContain('theme.accent');
    expect(identity).toContain('theme.radius');
  });

  it('partitions the manifest`s whole key set across the three panels', () => {
    const all = [
      ...botPanelKnownPaths(BOT_IDENTITY_FIELDS),
      ...botPanelKnownPaths(BOT_MODEL_FIELDS),
      ...botPanelKnownPaths(BOT_PUBLISHING_FIELDS),
    ];
    expect(all).toHaveLength(new Set(all).size);
    // The manifest's VALIDATED key set: a prohibited path is one no form renders, so it is not a
    // `knownPath` on any panel and a 422 keyed to it goes to the banner. See `prohibited` above.
    expect([...all].sort()).toEqual(
      Object.entries((updateBotRules as Manifest).rules)
        .filter(([, rules]) => !prohibited(rules))
        .map(([path]) => path)
        .sort(),
    );
  });

  it('gives the create dialog only the three names it actually renders', () => {
    // The dialog POSTs all twenty-five fields (`botCreateDefaults()` under the two the operator
    // types) and renders three. A 422 on one of the twenty-two has no control to land on; writing it
    // to a field that displays nowhere is a save where the server rejects, nothing changes on screen,
    // and the operator clicks again.
    expect([...BOT_CREATE_KNOWN_PATHS].sort()).toEqual([...BOT_CREATE_RENDERED_FIELDS].sort());
  });

  it('renders no name the create request does not rule, which a filter cannot report on its own', () => {
    // `BOT_CREATE_KNOWN_PATHS` is the manifest FILTERED, so a rendered field the server dropped
    // simply disappears from the set — silently. This is the direction that needs an assertion.
    const store = manifestFields(storeBotRules as Manifest);
    for (const rendered of BOT_CREATE_RENDERED_FIELDS) {
      expect(store).toContain(rendered);
    }
  });
});

describe('paths and role affordances', () => {
  it('builds the detail path under the organization`s collection', () => {
    expect(botPath('01JORGAAAAAAAAAAAAAAAAAAAA', BOT.id)).toBe(
      `${botsPath('01JORGAAAAAAAAAAAAAAAAAAAA')}/${BOT.id}`,
    );
  });

  it('percent-encodes the id, which is a no-op on a ULID and is the point', () => {
    // The habit is what keeps the day the identifier stops being a ULID from being an injected path
    // segment.
    expect(botPath('org', 'a/b')).toContain('a%2Fb');
  });

  it('grants bots.manage to owner and admin only', () => {
    // ADR-056: `bots.view` is held by ALL FOUR roles and `bots.manage` by two, which is why a
    // successful read says nothing about writing. An affordance, never authorization.
    expect(canManageBots('owner')).toBe(true);
    expect(canManageBots('admin')).toBe(true);
    expect(canManageBots('knowledge_manager')).toBe(false);
    expect(canManageBots('analyst')).toBe(false);
    expect(canManageBots(null)).toBe(false);
  });
});

describe('the status pill repair', () => {
  it('puts `testing` on the still info kind rather than in the slate bucket', () => {
    // The compromise this replaces: `running` is the only other info-coloured kind and its glyph
    // SPINS, so a trialled bot on a static list row read as "this page is stuck" — and taking the
    // slate bucket instead left `draft`, `testing` and `archived` separated by their word alone.
    expect(botStatusDisplay('testing')).toEqual({ kind: 'info', label: 'Testing' });
    expect(botStatusDisplay('draft').kind).toBe('pending');
    expect(botStatusDisplay('published').kind).toBe('ready');
    expect(botStatusDisplay('paused').kind).toBe('degraded');
    expect(botStatusDisplay('archived').kind).toBe('disabled');
  });

  it('renders an unheard-of status as its own value in the neutral bucket', () => {
    // The honest fallback: it is what the row claims and what the server will act on, and inventing
    // a label for it would be worse.
    expect(botStatusDisplay('quarantined')).toEqual({ kind: 'pending', label: 'quarantined' });
  });
});
