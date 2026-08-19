import { existsSync, readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import {
  CAPABILITIES,
  CAPABILITY_FAMILIES,
  PROVIDER_MODEL_EDIT_KNOWN_PATHS,
  PROVIDER_MODEL_KNOWN_PATHS,
  TASK_EXCLUSIVE_CHAT_FLAGS,
  capabilitiesInFamily,
  capabilityLabel,
  familyOfCapability,
  familyOfRow,
  flagsRefusedOn,
  formatPrice,
  formatTokenCount,
  isKnownCapability,
  modelPath,
  modelsPath,
  unknownCapabilities,
} from '@/features/models/api';

/**
 * The REACT-FREE half of the model-catalogue screen: the request paths, the 422 field vocabularies,
 * the capability list and the two display formatters.
 *
 * It runs in the `unit` project, which is `node` and installs no react plugin — so every module it
 * reaches transitively must be JSX-free. `features/models/api.ts` is, deliberately, and so is
 * `features/providers/api.ts`, which it imports for `connectionPath` and `deleteConflictMessage`.
 * (`providers/api.ts` imports `StatusKind` from a `.tsx` file with `import type`, which
 * `verbatimModuleSyntax` erases, so no JSX is loaded.)
 *
 * WHAT THIS SPEC MAY NOT CLAIM: anything about isolation. It exercises pure functions. That two
 * organizations never see each other's catalogue is Playwright's, and is recorded as unproven here.
 */

const ORG = '01JORGAAAAAAAAAAAAAAAAAAAA';
const CONNECTION = '01JCONNAAAAAAAAAAAAAAAAAAA';
const MODEL_ROW = '01JMODELAAAAAAAAAAAAAAAAAA';

describe('the request paths carry the organization AND the connection', () => {
  it('nests the catalogue under both, in the order the routes are mounted', () => {
    // Both segments are ROUTING HINTS resolved by `->scopeBindings()`; the SCOPE is the session, and
    // `TenantContext` re-reads the membership row per request. A foreign organization, a foreign
    // connection, or a model under a DIFFERENT connection of the same organization all 404 at
    // binding time.
    expect(modelsPath(ORG, CONNECTION)).toBe(
      `/api/v1/organizations/${ORG}/provider-connections/${CONNECTION}/models`,
    );
    expect(modelPath(ORG, CONNECTION, MODEL_ROW)).toBe(
      `/api/v1/organizations/${ORG}/provider-connections/${CONNECTION}/models/${MODEL_ROW}`,
    );
  });

  it('encodes every interpolated segment, because all three come off a server response', () => {
    // A no-op on a ULID today. The habit is what keeps the day one of these stops being a ULID from
    // being an injected path segment.
    expect(modelPath(ORG, 'a/b', 'c?d')).toContain('a%2Fb');
    expect(modelPath(ORG, 'a/b', 'c?d')).toContain('c%3Fd');
  });

  it('builds the catalogue path from the connection path rather than re-spelling the route', () => {
    // `modelsPath` is `${connectionPath(...)}/models`, so a change to the route prefix moves both
    // screens at once. Asserted by shape: the connection path is a strict prefix.
    expect(modelsPath(ORG, CONNECTION).endsWith('/models')).toBe(true);
    expect(modelPath(ORG, CONNECTION, MODEL_ROW).startsWith(modelsPath(ORG, CONNECTION))).toBe(true);
  });
});

describe('the 422 field vocabularies come from the server’s own rules()', () => {
  it('renders every scalar field of the create request', () => {
    expect([...PROVIDER_MODEL_KNOWN_PATHS].sort()).toEqual([
      'context_window',
      'display_name',
      'enabled',
      'input_price_per_million',
      'max_output_tokens',
      'model',
      'output_price_per_million',
      'price_currency',
      'supported',
    ]);
  });

  it('drops `supported.*`, which has no control and would display NOWHERE', () => {
    // Laravel keys an array error POSITIONALLY (`supported.3`) and `applyServerErrors` folds
    // `\.\d+` to `.*` for the membership test — so with the element path in this set, the message
    // would be written to `setError('supported.3')`, a name no control has: the capability group is
    // one Controller over `supported` and `<FormMessage>` reads the error at its OWN field name. The
    // operator clicks Save, the server rejects, nothing changes on screen, and they click again.
    expect(PROVIDER_MODEL_KNOWN_PATHS).not.toContain('supported.*');
    expect(PROVIDER_MODEL_EDIT_KNOWN_PATHS).not.toContain('supported.*');
    // `supported` ITSELF stays: the group renders a message slot, and `max:20` belongs under it.
    expect(PROVIDER_MODEL_KNOWN_PATHS).toContain('supported');
  });

  it('omits `model` from the edit set, because the PUT declares no such field', () => {
    // The identifier is immutable: it is half of the vector-space identity for everything already
    // embedded through the row, and `organizations.embedding_model` references it as a bare string
    // with no foreign key, so nothing in the database would follow a rename.
    expect(PROVIDER_MODEL_EDIT_KNOWN_PATHS).not.toContain('model');
    expect([...PROVIDER_MODEL_EDIT_KNOWN_PATHS].sort()).toEqual(
      [...PROVIDER_MODEL_KNOWN_PATHS].filter((path) => path !== 'model').sort(),
    );
  });

  it('names no ownership column, in either direction', () => {
    for (const banned of ['organization_id', 'connection_id', 'id', 'created_by', 'user_id']) {
      expect(PROVIDER_MODEL_KNOWN_PATHS).not.toContain(banned);
      expect(PROVIDER_MODEL_EDIT_KNOWN_PATHS).not.toContain(banned);
    }
  });
});

/**
 * THE CROSS-PLANE DRIFT CHECK, and it is the only mechanical link between this console's checkbox
 * list and the vocabulary that actually decides what a flag does.
 *
 * The authority is `Capability` in `services/ai-service/app/providers/contract.py`. The CONTROL PLANE
 * validates `supported.*` as `string|max:64` and publishes `array<string>` with no enum, so there is
 * no OpenAPI component to compare against and no generated type to inherit — the wire is open by
 * design, and a closed copy in `packages/contracts` would be a guarantee no layer makes.
 *
 * That leaves this console's list unasserted by anything, which is the state the `masked_key` chain
 * was in before 6B: a hand-written list, typed against another file by eye, with nothing in between.
 * So this reads the enum and compares the two sets. Adding a member to the data plane is a three-part
 * change there — the enum, the adapter's `validate()` mapping, and the `capability_flags` backfill —
 * and this is the fourth part: until it lands here, the flag exists and no operator can set it from
 * the console, which is a feature that is dark with nothing reporting it.
 */
describe('the capability vocabulary agrees with the data plane’s enum', () => {
  const contractPath = new URL(
    '../../../../services/ai-service/app/providers/contract.py',
    import.meta.url,
  ).pathname;

  /** The members of `class Capability(StrEnum)`, read off the source. */
  const dataPlaneCapabilities = (): readonly string[] => {
    const source = readFileSync(contractPath, 'utf8');
    const start = source.indexOf('class Capability(StrEnum):');
    if (start === -1) return [];

    const rest = source.slice(start);
    const end = rest.indexOf('\nclass ', 1);
    const body = end === -1 ? rest : rest.slice(0, end);

    return [...body.matchAll(/^ {4}[A-Z][A-Z0-9_]* = "([a-z0-9_]+)"$/gm)].map(
      (match) => match[1] as string,
    );
  };

  it('reads a file that exists and an enum that parsed — the positive control', () => {
    // Without this, a moved file or a changed enum SPELLING would make the comparison below vacuous:
    // an empty set equals an empty set, and the suite would go green on a check that read nothing.
    expect(existsSync(contractPath), `${contractPath} must exist`).toBe(true);
    expect(dataPlaneCapabilities().length).toBeGreaterThan(0);
  });

  it('offers exactly the flags the data plane defines, no more and no fewer', () => {
    // NOT a count. The count is derived on both sides and stated in neither (ADR-036): a number in
    // prose here would be a third place to update and the first one to go stale.
    expect(new Set(CAPABILITIES)).toEqual(new Set(dataPlaneCapabilities()));
  });

  it('lists each flag once, and assigns each to exactly one family', () => {
    expect(new Set(CAPABILITIES).size).toBe(CAPABILITIES.length);

    const byFamily = CAPABILITY_FAMILIES.flatMap(([family]) => capabilitiesInFamily(family));
    expect([...byFamily].sort()).toEqual([...CAPABILITIES].sort());
    expect(new Set(byFamily).size).toBe(byFamily.length);
  });

  it('keeps the two non-chat families disjoint from the chat one', () => {
    // ROWS ARE TASK-EXCLUSIVE — a row claiming both `embedding` and `rerank`, or either beside
    // `text`/`tool_use`/`reasoning`, describes a model that does not exist. The form arranges the
    // checkboxes one family at a time, which only works while the families really do partition the
    // vocabulary. What the form STRIPS on a task change is a narrower question, and is the next
    // describe block.
    expect(capabilitiesInFamily('embedding')).toContain('embedding');
    expect(capabilitiesInFamily('rerank')).toEqual(['rerank']);
    expect(capabilitiesInFamily('chat')).not.toContain('embedding');
    expect(capabilitiesInFamily('chat')).not.toContain('rerank');
  });

  it('names the rerank gate in the family description, at the point of entry', () => {
    // An unset flag SILENTLY disables a downstream stage: a model that can rerank but is not flagged
    // simply never reranks, with no error and answer quality drifting for as long as nobody looks.
    // The sentence is in the form beside the checkboxes rather than in a tooltip or in documentation.
    const rerank = CAPABILITY_FAMILIES.find(([family]) => family === 'rerank');
    expect(rerank?.[2]).toMatch(/never reranks/);
  });
});

/**
 * THE SECOND CROSS-PLANE READ, AND THE ONE THAT WAS MISSING.
 *
 * The block above set-compares the console's VOCABULARY against `Capability`. Nothing compared the
 * console's EXCLUSION SET against the data plane's, and the two are different questions — which is
 * how the console came to be stricter than the server over eight flags with a green suite.
 *
 * `assert_row_coherent` (`services/ai-service/app/providers/capabilities.py`) forbids exactly two
 * combinations: `embedding` with `rerank`, and either of those with a member of
 *
 *     chat_flags = frozenset({Capability.TEXT, Capability.TOOL_USE, Capability.REASONING})
 *
 * Eleven flags are in this console's `chat` FAMILY and three are in `chat_flags`. The family is a UI
 * arrangement — which checkboxes appear together — and treating it as the refusal set meant an
 * embedding row that legitimately carried `stream_usage` or `prompt_caching` lost it the moment an
 * operator touched the task radio, with a 200 on the request that did it. That is the invisible
 * direction of the drift asymmetry: a form STRICTER than the server removes functionality nobody
 * reports.
 *
 * So this reads the frozenset out of the Python source and asserts equality. A narrowing that is only
 * correct today is a narrowing that drifts back the first time the data plane adds a fourth member.
 */
describe('the task-exclusivity this console enforces equals the one the data plane refuses', () => {
  const capabilitiesPath = new URL(
    '../../../../services/ai-service/app/providers/capabilities.py',
    import.meta.url,
  ).pathname;

  /**
   * The members of `chat_flags`, read off the source as `Capability.X` names and lowered to their
   * wire spelling — which is what `StrEnum` guarantees and what `contract.py`'s own `X = "x"` lines
   * confirm. The positive control below is what stops a moved file or a renamed local from making
   * this comparison vacuous.
   */
  const dataPlaneChatFlags = (): readonly string[] => {
    const source = readFileSync(capabilitiesPath, 'utf8');
    const match = /chat_flags\s*=\s*frozenset\(\{([^}]*)\}\)/.exec(source);
    if (match === null) return [];

    return [...(match[1] as string).matchAll(/Capability\.([A-Z][A-Z0-9_]*)/g)].map((member) =>
      (member[1] as string).toLowerCase(),
    );
  };

  it('reads a file that exists and a frozenset that parsed — the positive control', () => {
    // Without this, a renamed local or a reshaped literal would leave an empty set equal to an empty
    // set, and the assertion below would go green on a check that read nothing.
    expect(existsSync(capabilitiesPath), `${capabilitiesPath} must exist`).toBe(true);
    expect(dataPlaneChatFlags().length).toBeGreaterThan(0);
    // Every name it read is a flag this console knows, which is what makes the comparison meaningful
    // rather than a match against strings from a different vocabulary.
    for (const flag of dataPlaneChatFlags()) expect(CAPABILITIES).toContain(flag);
  });

  it('strips exactly `chat_flags` when the task becomes embedding or rerank', () => {
    expect(new Set(TASK_EXCLUSIVE_CHAT_FLAGS)).toEqual(new Set(dataPlaneChatFlags()));

    for (const family of ['embedding', 'rerank'] as const) {
      const refused = flagsRefusedOn(family);
      for (const flag of dataPlaneChatFlags()) expect(refused.has(flag), flag).toBe(true);
    }
  });

  it('is a strict subset of the chat FAMILY, which is the whole finding', () => {
    // If these two were ever equal the narrowing would be a no-op, and this spec would be asserting
    // the bug. Stated as a subset-plus-difference rather than as two counts (ADR-036).
    const family = new Set<string>(capabilitiesInFamily('chat'));
    for (const flag of TASK_EXCLUSIVE_CHAT_FLAGS) expect(family.has(flag), flag).toBe(true);
    expect(
      capabilitiesInFamily('chat').filter((flag) => !TASK_EXCLUSIVE_CHAT_FLAGS.includes(flag))
        .length,
    ).toBeGreaterThan(0);
  });

  it('keeps every chat flag the data plane permits on a non-chat row', () => {
    // The eight this console used to throw away. `stream_usage` on an embedding row is a true
    // statement about a real deployment — a provider that reports usage on an embedding response —
    // and nothing in `assert_row_coherent` objects to it.
    const permitted = capabilitiesInFamily('chat').filter(
      (flag) => !TASK_EXCLUSIVE_CHAT_FLAGS.includes(flag),
    );

    for (const family of ['embedding', 'rerank'] as const) {
      const refused = flagsRefusedOn(family);
      for (const flag of permitted) expect(refused.has(flag), `${family} · ${flag}`).toBe(false);
    }
    // Named explicitly as well as derived, because these two are the ones the finding was written
    // about and a derivation that stopped covering them would still pass the loop above.
    expect(flagsRefusedOn('embedding').has('stream_usage')).toBe(false);
    expect(flagsRefusedOn('embedding').has('prompt_caching')).toBe(false);
  });

  it('always refuses the OTHER families’ discriminators, in every direction', () => {
    // Rule 1 of `assert_row_coherent` — `embedding` with `rerank` — plus a UI necessity: `familyOfRow`
    // reads the discriminators first, so a retained one would re-open the row in the family the
    // operator just left.
    expect(flagsRefusedOn('embedding').has('rerank')).toBe(true);
    expect(flagsRefusedOn('rerank').has('embedding')).toBe(true);
    expect(flagsRefusedOn('chat').has('embedding')).toBe(true);
    expect(flagsRefusedOn('chat').has('rerank')).toBe(true);
    // A family never refuses its own discriminator.
    expect(flagsRefusedOn('embedding').has('embedding')).toBe(false);
    expect(flagsRefusedOn('rerank').has('rerank')).toBe(false);
  });

  it('refuses no chat flag at all when the task IS chat', () => {
    // `chat_flags` is a rule ABOUT non-chat rows. Applying it to a chat row would strip `text` from
    // a chat model, which is the reductio.
    for (const flag of capabilitiesInFamily('chat')) {
      expect(flagsRefusedOn('chat').has(flag), flag).toBe(false);
    }
  });

  it('never refuses a flag this build does not recognise', () => {
    // The refusal set is built from `CAPABILITY_CATALOGUE` members only, so an open-vocabulary flag
    // cannot land in it — which is what keeps `changeFamily`'s single filter from needing a second
    // clause for unknown flags.
    for (const family of CAPABILITY_FAMILIES) {
      for (const flag of flagsRefusedOn(family[0])) {
        expect(isKnownCapability(flag), flag).toBe(true);
      }
    }
    expect(flagsRefusedOn('embedding').has('speculative_decoding')).toBe(false);
  });
});

describe('an unrecognised flag is rendered verbatim and never dropped', () => {
  it('falls back to the raw string rather than inventing a label', () => {
    expect(capabilityLabel('text')).not.toBe('text');
    // A flag from a newer data plane. Rendering the raw value is the honest fallback: it is what the
    // row claims and what the data plane will read.
    expect(capabilityLabel('speculative_decoding')).toBe('speculative_decoding');
    expect(isKnownCapability('speculative_decoding')).toBe(false);
  });

  it('reports exactly the unknown members of a row, so the form can carry them through', () => {
    expect(unknownCapabilities(['text', 'nope', 'tool_use', 'also_nope'])).toEqual([
      'nope',
      'also_nope',
    ]);
    expect(unknownCapabilities(['text'])).toEqual([]);
  });

  it('places a known flag in its family and an unknown one in none', () => {
    expect(familyOfCapability('embedding')).toBe('embedding');
    expect(familyOfCapability('text')).toBe('chat');
    expect(familyOfCapability('speculative_decoding')).toBeNull();
  });
});

describe('the task a row is for is read off its flags', () => {
  it('lets the non-chat flags decide, and defaults to chat', () => {
    expect(familyOfRow(['embedding', 'embedding_dimensions'])).toBe('embedding');
    expect(familyOfRow(['rerank'])).toBe('rerank');
    expect(familyOfRow(['text', 'tool_use'])).toBe('chat');
    // A row that claims nothing is a chat row rather than an error: an empty array is also what a
    // malformed stored value renders as, and the console has to be able to open it.
    expect(familyOfRow([])).toBe('chat');
  });

  it('still resolves an INCOHERENT row, because the console has to be able to repair one', () => {
    // The data plane would refuse this row; Laravel would store it. Throwing here would make it
    // uneditable, which is the opposite of what an operator staring at a `row_incoherent` rejection
    // needs.
    expect(familyOfRow(['embedding', 'text'])).toBe('embedding');
  });
});

describe('a price is formatted without ever becoming a number', () => {
  it('trims the cast’s trailing zeros by STRING SURGERY and nothing else', () => {
    // `'0.02'` in, `'0.020000'` out: cast `decimal:6` over a `numeric(14, 6)` column. The six digits
    // are noise on screen and the value is exact, so only characters are removed — never a
    // `Number()` round-trip, which is the first step of re-scaling the value on the way back.
    expect(formatPrice('0.020000', 'USD')).toBe('0.02 USD');
    expect(formatPrice('10.000000', 'USD')).toBe('10 USD');
    expect(formatPrice('1.250000', 'EUR')).toBe('1.25 EUR');
    // No decimal point, nothing to trim — and in particular the trailing zeros of an INTEGER are
    // never touched, which a naive `replace(/0+$/, '')` would get wrong.
    expect(formatPrice('1000000', 'USD')).toBe('1000000 USD');
    expect(formatPrice('100', 'USD')).toBe('100 USD');
  });

  it('renders a null price as an em dash and never as free or zero', () => {
    // Null means NO PRICE HAS BEEN RECORDED, which is a different fact from free.
    expect(formatPrice(null, 'USD')).toBe('—');
    expect(formatPrice(null, null)).toBe('—');
    // A currency with no price is a legal row — the state of an operator who recorded the billing
    // currency before looking the prices up.
    expect(formatPrice('0.000000', null)).toBe('0');
  });

  it('keeps a value the JS number type could not hold exactly', () => {
    // Six places under a million is representable, but the point is that nothing here depends on
    // that: the string is carried through, so a wider column tomorrow needs no change and no
    // rounding decision.
    expect(formatPrice('0.000001', 'USD')).toBe('0.000001 USD');
    expect(formatPrice('999999.999999', 'USD')).toBe('999999.999999 USD');
  });
});

describe('a token count says when it is not recorded', () => {
  it('renders 0 as words, because a bare zero reads as a limit of nothing', () => {
    // The resource says so explicitly: 0 means "not recorded", and a max-output column showing `0`
    // describes a model that can emit nothing.
    expect(formatTokenCount(0)).toBe('Not recorded');
  });

  it('groups a real count', () => {
    // Integers off the wire, already typed `number`. Grouping them does not round-trip through a
    // decimal representation, which is why `Intl` is safe here and is not for a price.
    expect(formatTokenCount(400_000)).toMatch(/400/);
    expect(formatTokenCount(400_000)).not.toBe('400000');
  });
});
