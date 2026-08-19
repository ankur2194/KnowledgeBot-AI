import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import type {
  BotAccessMode,
  BotAnswerMode,
  BotCollectionResource,
  BotDomainResource,
  BotResource,
  BotStarterQuestionResource,
  BotStatus,
  BotTheme,
  BotThemeRadius,
  EvidenceThresholdScale,
  ListMetaResource,
} from '../src/resources/bots.js';
import type { InvitationResource, MemberResource } from '../src/resources/members.js';
import type {
  ProviderModelCollectionResource,
  ProviderModelResource,
} from '../src/resources/provider-models.js';
import type {
  EmbeddingCandidate,
  EmbeddingDesignation,
  EmbeddingReadinessResource,
  EmbeddingRejection,
  ProviderConnectionResource,
  ProviderConnectionStatus,
  ProviderKey,
} from '../src/resources/providers.js';
import type {
  InvitationPreview,
  MembershipStatus,
  Role,
  SessionMembership,
  SessionResource,
  SessionUser,
} from '../src/resources/session.js';

/**
 * `src/resources/session.ts` is HAND-WRITTEN — there is no TypeScript generator in this repo — so
 * this file is the mechanical check that replaces one. Three pins, each catching a different way
 * the type can go wrong:
 *
 *   1. TYPE-LEVEL. A renamed or added key fails the TYPECHECK, in a test file, where a reader is
 *      looking, instead of in whichever app happened to read the field.
 *   2. WIRE-LEVEL. Set-compared against the generated OpenAPI document once it describes the
 *      resource — the only assertion that can catch the control plane and this file disagreeing.
 *   3. BUDGET. The built root entry's export list, exactly. `src/resources/` must contribute ZERO
 *      runtime bytes to it (<=1 kB brotli inside apps/widget's 30 kB app shell), and a `export {}`
 *      where a `export type {}` belonged is invisible in review and invisible in the typecheck.
 */

const here = dirname(fileURLToPath(import.meta.url));
const openapiPath = join(here, '..', 'openapi', 'core-api.openapi.json');
const distEntry = join(here, '..', 'dist', 'index.js');

/**
 * RECURSIVE, and it has to be: `DumpOpenApiCommand` INLINES an enum into the property that carries it
 * rather than publishing a named component, so `role`'s members are read at
 * `schemas.SessionMembership.properties.role.enum` — one level deeper than a flat
 * `Record<string, unknown>` can describe. Typed flat, the two `?.enum` reads below were
 * `TS2339: Property 'enum' does not exist on type '{}'`: green under Vitest, which does not
 * typecheck, and red in `pnpm contracts:typecheck`, which somebody has to run — there is no CI.
 */
interface OpenApiSchemaNode {
  readonly properties?: Readonly<Record<string, OpenApiSchemaNode>>;
  /**
   * `string | null`, not `string`, and the null is the document's rather than this file's invention:
   * a NULLABLE enum is emitted as `{"type": ["string","null"], "enum": [..., null]}`, which
   * `BotResource.evidence_threshold_scale` is the first instance of. Typed `readonly string[]` the
   * comparison against a TypeScript union — which spells the same fact as `| null` on the property —
   * fails on a correct type, and the repair that suggests itself is to add a `null` member to the
   * union, which is a second spelling of the nullability the `NULLABLE` map already owns.
   */
  readonly enum?: readonly (string | null)[];
  /**
   * The three fields the property-NAME comparison used to ignore, and finding N3 is that it did.
   *
   * `type` is a STRING OR AN ARRAY. `DumpOpenApiCommand` emits OpenAPI 3.1 / JSON Schema 2020-12, so
   * a nullable scalar is `{"type": ["string", "null"]}` rather than 3.0's `nullable: true` — reading
   * it as a plain `string` compiles, always misses, and reports every nullable field as non-nullable.
   */
  readonly type?: string | readonly string[];
  /** A nullable OBJECT is `anyOf: [{$ref}, {type: null}]`; a `$ref` cannot carry a type array. */
  readonly anyOf?: readonly OpenApiSchemaNode[];
  readonly required?: readonly string[];
}

/**
 * Whether the WIRE says this property may be null — both spellings, because both appear in the
 * document this suite reads and a check that knew only one would be silently half-blind.
 */
const wireNullable = (node: OpenApiSchemaNode | undefined): boolean => {
  if (node === undefined) return false;

  const type = node.type;
  if (type === 'null') return true;
  if (Array.isArray(type) && type.includes('null')) return true;

  return (node.anyOf ?? []).some((member) => wireNullable(member));
};

/** The properties of one component that the wire declares nullable, as a set. */
const wireNullableKeys = (component: string): ReadonlySet<string> =>
  new Set(
    Object.entries(schemas[component]?.properties ?? {})
      .filter(([, node]) => wireNullable(node))
      .map(([key]) => key),
  );

/**
 * THE TYPE-LEVEL HALF OF THE NULLABILITY PIN. `null extends T[K]` is what makes
 * `Record<NullableKeys<X>, true>` closed in both directions, exactly as `Record<keyof X, true>` is
 * for names: dropping `| null` from a field makes its entry below an excess property, and adding
 * `| null` to one makes the map incomplete. Both are `pnpm contracts:typecheck` failures.
 *
 * `-?` is load-bearing. Without it an optional property's type includes `undefined` and the
 * conditional resolves against `T[K] | undefined`, which quietly changes the question being asked.
 */
type NullableKeys<T> = {
  [K in keyof T]-?: null extends T[K] ? K : never;
}[keyof T];

interface OpenApiDocument {
  readonly components?: {
    readonly schemas?: Readonly<Record<string, OpenApiSchemaNode>>;
  };
}

const openapi = JSON.parse(readFileSync(openapiPath, 'utf8')) as OpenApiDocument;
const schemas = openapi.components?.schemas ?? {};

// ── 1. the type-level pin ────────────────────────────────────────────────────────────────────────

/**
 * `Record<keyof X, true>` is TOTAL and CLOSED in both directions: a missing key is a type error and
 * so is an extra one. That is the same property `tests/Contract/OpenApiDocumentTest.php:321-355`
 * asserts of every published resource component, restated on this side of the wire.
 */
describe('the hand-written session types', () => {
  it('SessionResource declares exactly three keys', () => {
    const keys: Record<keyof SessionResource, true> = {
      user: true,
      current_organization_id: true,
      organizations: true,
    };
    expect(Object.keys(keys).sort()).toEqual([
      'current_organization_id',
      'organizations',
      'user',
    ]);
  });

  it('SessionUser carries email_verified as a BOOLEAN, not a timestamp', () => {
    const keys: Record<keyof SessionUser, true> = {
      id: true,
      name: true,
      email: true,
      email_verified: true,
      is_platform_owner: true,
    };
    expect(Object.keys(keys)).toContain('email_verified');
    // `email_verified_at` was the obvious shape and it lost: the SPA renders a banner and a resend
    // button, never a date, and shipping the timestamp invites a UI that renders it.
    expect(Object.keys(keys)).not.toContain('email_verified_at');
  });

  it('SessionMembership is FLAT — no nested `organization` object', () => {
    const keys: Record<keyof SessionMembership, true> = {
      id: true,
      name: true,
      slug: true,
      role: true,
      status: true,
    };
    // Nesting would need a second published component, with its own totality proof, for three
    // fields that exist only inside this list.
    expect(Object.keys(keys)).not.toContain('organization');
    expect(Object.keys(keys).sort()).toEqual(['id', 'name', 'role', 'slug', 'status']);
  });

  it('InvitationPreview carries no id of any kind and does not name the inviter', () => {
    const keys: Record<keyof InvitationPreview, true> = {
      organization_name: true,
      email: true,
      role: true,
      expires_at: true,
    };
    // The endpoint is guest-reachable, authorized solely by possession of a 256-bit random. An org
    // id would let a token holder address a tenant they are not in; the inviter's name is a
    // person's identity disclosed to someone who is not a member yet.
    for (const banned of ['id', 'organization_id', 'invited_by', 'inviter', 'token']) {
      expect(Object.keys(keys)).not.toContain(banned);
    }
  });

  /**
   * The two unions, pinned as exhaustive `Record`s for the same reason: `Role` is a union rather
   * than a `ROLES` tuple because `src/resources/session.ts` may contain no runtime value at all
   * (pin 3), so this mapped type is the only way to assert its membership.
   */
  it('Role is the four-member organization catalog', () => {
    const roles: Record<Role, true> = {
      owner: true,
      admin: true,
      knowledge_manager: true,
      analyst: true,
    };
    expect(Object.keys(roles).sort()).toEqual(['admin', 'analyst', 'knowledge_manager', 'owner']);
    // Platform ownership is NOT an organization role — it is `SessionUser.is_platform_owner`.
    expect(Object.keys(roles)).not.toContain('platform_owner');
  });

  it('MembershipStatus is the three-member catalog, and non-active values are real', () => {
    const statuses: Record<MembershipStatus, true> = {
      active: true,
      invited: true,
      suspended: true,
    };
    // `organizations` lists non-active memberships too, so the UI can tell "you have no
    // organizations" from "your membership was suspended". Dropping either member from this union
    // would make that list unrepresentable.
    expect(Object.keys(statuses).sort()).toEqual(['active', 'invited', 'suspended']);
  });
});

/**
 * The same three pins for the provider-connection types. `MIRRORED` below compares a HAND-WRITTEN
 * property list against the published document, which leaves one link unasserted: that the list
 * matches the TypeScript INTERFACE. These `Record<keyof X, true>` maps close it — a renamed field
 * fails the typecheck here, in a test file where a reader is looking.
 */
describe('the hand-written provider types', () => {
  it('ProviderConnectionResource declares exactly six keys and no credential-shaped one', () => {
    const keys: Record<keyof ProviderConnectionResource, true> = {
      id: true,
      provider: true,
      label: true,
      masked_key: true,
      status: true,
      created_at: true,
    };
    expect(Object.keys(keys).sort()).toEqual([
      'created_at',
      'id',
      'label',
      'masked_key',
      'provider',
      'status',
    ]);
    // The resource carries a DISPLAY STRING and nothing else. `credential` is a request field on two
    // endpoints and appears in no response, no component, and no type in this package; `last_four`,
    // `key_version` and `credential_version` are columns the vault path owns. A client that could read
    // any of them would be a client that could put one in a form.
    for (const banned of ['credential', 'key', 'last_four', 'key_version', 'credential_version']) {
      expect(Object.keys(keys)).not.toContain(banned);
    }
  });

  it('EmbeddingReadinessResource carries both `ready` and its negation, deliberately', () => {
    const keys: Record<keyof EmbeddingReadinessResource, true> = {
      ready: true,
      blocks_ingestion: true,
      selected: true,
      designated: true,
      eligible: true,
      rejected: true,
      explanation: true,
    };
    // Not redundant: "not ready" and "cannot upload anything at all" are one fact under two names, and
    // only one of the two is actionable in a banner.
    expect(Object.keys(keys)).toContain('blocks_ingestion');
    // `selected` and `designated` are the OTHER pair that looks redundant and is not: `selected` is
    // what the resolver produced, `designated` is what the organization stored. A null `selected`
    // with a non-null `designated` is "your choice stopped resolving"; two nulls is "you never made
    // one" — two different next actions that were one rendering until this field existed.
    expect(Object.keys(keys)).toContain('designated');
    expect(Object.keys(keys).sort()).toEqual([
      'blocks_ingestion',
      'designated',
      'eligible',
      'explanation',
      'ready',
      'rejected',
      'selected',
    ]);
  });

  it('the two nested embedding shapes differ by exactly the refusal fields', () => {
    const candidate: Record<keyof EmbeddingCandidate, true> = {
      connection_id: true,
      provider: true,
      model: true,
    };
    const rejection: Record<keyof EmbeddingRejection, true> = {
      connection_id: true,
      provider: true,
      model: true,
      reason: true,
      detail: true,
    };
    expect(
      Object.keys(rejection).filter((key) => !(key in candidate)).sort(),
    ).toEqual(['detail', 'reason']);
  });

  it('EmbeddingDesignation carries NO provider, unlike EmbeddingCandidate', () => {
    const designation: Record<keyof EmbeddingDesignation, true> = {
      connection_id: true,
      model: true,
    };
    expect(Object.keys(designation).sort()).toEqual(['connection_id', 'model']);
    // The organization stores a connection id and a model string and nothing else — the vendor is a
    // property of the connection, resolved at read time. A `provider` here would be a third place
    // for it to be wrong, and a renderer that assumed one would print `undefined/model`, which is
    // why `describeCandidate` is deliberately not applicable to this shape.
    expect(Object.keys(designation)).not.toContain('provider');
  });

  it('ProviderKey and ProviderConnectionStatus agree with the document member for member', () => {
    const providers: Record<ProviderKey, true> = {
      openai: true,
      anthropic: true,
      deepseek: true,
      nvidia_nim: true,
      openrouter: true,
    };
    const statuses: Record<ProviderConnectionStatus, true> = {
      active: true,
      invalid: true,
      revoked: true,
    };

    // READ OFF THE PROPERTY, not off a named component: the dumper INLINES an enum into the property
    // that carries it. A sixth vendor is a server change first, and this is where the client finds out —
    // the alternative is a `<Select>` that silently cannot express a connection the API will return.
    const connection = schemas['ProviderConnectionResource']?.properties;
    expect(new Set(connection?.['provider']?.enum ?? []), 'provider enum').toEqual(
      new Set(Object.keys(providers)),
    );
    expect(new Set(connection?.['status']?.enum ?? []), 'status enum').toEqual(
      new Set(Object.keys(statuses)),
    );

    // `EmbeddingCandidate.provider` carries the SAME vocabulary and is published WITHOUT an enum,
    // because the control plane relays it from the data plane's resolution rule rather than validating
    // it. The TS type is `string` there for that reason, and this asserts the asymmetry is the
    // server's rather than something this package invented.
    expect(schemas['EmbeddingCandidate']?.properties?.['provider']?.enum).toBeUndefined();
  });
});

/**
 * The model-catalog types, pinned the same three ways. Three of the assertions below are about fields
 * that would be WRONG rather than merely absent if the type drifted, which is why they are spelled out
 * rather than left to the `MIRRORED` set comparison.
 */
describe('the hand-written provider-model types', () => {
  it('ProviderModelResource declares exactly twelve keys and no credential-shaped one', () => {
    const keys: Record<keyof ProviderModelResource, true> = {
      id: true,
      connection_id: true,
      model: true,
      display_name: true,
      supported: true,
      context_window: true,
      max_output_tokens: true,
      enabled: true,
      input_price_per_million: true,
      output_price_per_million: true,
      price_currency: true,
      created_at: true,
    };
    expect(Object.keys(keys).sort()).toEqual([
      'connection_id',
      'context_window',
      'created_at',
      'display_name',
      'enabled',
      'id',
      'input_price_per_million',
      'max_output_tokens',
      'model',
      'output_price_per_million',
      'price_currency',
      'supported',
    ]);
    // A catalog row reaches no vault. `ProviderModelController` imports none, the service it calls
    // imports none, and this resource renders no field of the parent connection except its ULID —
    // so the masked form of the key is not here either, and there is no shape in this package
    // pairing a model row with key material.
    for (const banned of ['credential', 'masked_key', 'provider', 'key_version']) {
      expect(Object.keys(keys)).not.toContain(banned);
    }
  });

  it('publishes `supported` as an OPEN string array, with no enum on either side', () => {
    // THE ASYMMETRY IS THE SERVER'S. The closed vocabulary is the data plane's `Capability` StrEnum;
    // the control plane validates the members as `string|max:64` and publishes no enum, so a closed
    // copy in this package would be a guarantee no layer makes — and it would reject a flag the data
    // plane added last week, which is functionality removed with nothing reported.
    expect(schemas['ProviderModelResource']?.properties?.['supported']?.enum).toBeUndefined();

    // …and therefore this package exports no capability tuple. `src/resources/provider-models.ts`
    // holds zero runtime values (pin 3 below), so the assertion is against the module's own text
    // rather than an export list: a tuple added there would have to be a value.
    const source = readFileSync(join(here, '..', 'src', 'resources', 'provider-models.ts'), 'utf8');
    expect(source).not.toMatch(/export const/);
  });

  it('types both prices as STRINGS, which is the whole reason the type is hand-written', () => {
    // `decimal:6` on a `numeric(14, 6)` column: `'0.02'` comes back as `'0.020000'`. A `number` here
    // would be assignable from a `JSON.parse` that re-scaled it, and the drift is invisible until a
    // month of usage is summed.
    const properties = schemas['ProviderModelResource']?.properties ?? {};
    for (const field of ['input_price_per_million', 'output_price_per_million', 'price_currency']) {
      expect(Object.keys(properties), `${field} must be published`).toContain(field);
    }

    // The TYPE side of the same claim, asserted by assignment rather than by a string: a `number`
    // literal in either slot is a typecheck failure in this file.
    const priced: Pick<
      ProviderModelResource,
      'input_price_per_million' | 'output_price_per_million' | 'price_currency'
    > = {
      input_price_per_million: '0.020000',
      output_price_per_million: null,
      price_currency: 'USD',
    };
    expect(priced.input_price_per_million).toBe('0.020000');
  });

  it('ProviderModelCollectionResource wraps the array under a named key', () => {
    const keys: Record<keyof ProviderModelCollectionResource, true> = { models: true };
    // `ResponseShape` maps a response KEY to a schema class and has no shape meaning "an array of",
    // and every published component must be `additionalProperties: false`, which an array-typed
    // schema cannot be. A fixture returning `{"data": [...]}` would make a spec pass against a shape
    // the server never sends.
    expect(Object.keys(keys)).toEqual(['models']);
  });
});

/**
 * The bot types, pinned the same three ways, plus one pin nothing else in this file needs: five
 * closed vocabularies that exist TWICE in this package on purpose — as unions here and as tuples
 * behind `@kb/contracts/forms` — because `src/resources/` may hold no runtime value and a `<Select>`
 * needs something it can iterate. Each spelling is pinned to the server independently, so the pair
 * cannot drift together silently: these against the document's inlined enums, the tuples against the
 * `in:` probes in test/form-drift.test.ts.
 */
describe('the hand-written bot types', () => {
  it('BotResource declares exactly thirty keys and no credential-shaped one', () => {
    const keys: Record<keyof BotResource, true> = {
      id: true,
      public_bot_id: true,
      name: true,
      slug: true,
      description: true,
      welcome_message: true,
      placeholder_text: true,
      system_instruction: true,
      answer_style_instruction: true,
      status: true,
      access_mode: true,
      provider_connection_id: true,
      provider_model_id: true,
      answer_mode: true,
      dense_top_k: true,
      sparse_top_k: true,
      rerank_candidates: true,
      rerank_retain: true,
      evidence_threshold: true,
      evidence_threshold_scale: true,
      retrieval_configuration_version: true,
      allow_general_answers: true,
      theme: true,
      rate_limit_per_minute: true,
      rate_limit_per_day: true,
      retention_days: true,
      collect_end_user_data: true,
      consent_text: true,
      created_at: true,
      updated_at: true,
    };
    expect(Object.keys(keys)).toHaveLength(30);

    // Nothing of the parent connection beyond its ULID: no vendor, no label, no masked credential.
    // The same property `ProviderModelResource` holds, and for the same reason — a client that could
    // read key material is a client that could put it in a form.
    for (const banned of ['credential', 'masked_key', 'provider', 'key_version', 'organization_id']) {
      expect(Object.keys(keys)).not.toContain(banned);
    }
  });

  it('separates the ADMIN id from the public one, and carries the system instruction', () => {
    // The two identifier fields are the reason this resource is authenticated-only, and they are not
    // interchangeable. `id` is a term in every vector query issued on this bot's behalf;
    // `public_bot_id` is the token a widget snippet on a stranger's page carries and authorizes
    // nothing. A shape with one field serving both purposes is the leak this split prevents.
    const published = Object.keys(schemas['BotResource']?.properties ?? {});
    expect(published).toContain('id');
    expect(published).toContain('public_bot_id');
    // `system_instruction` is the bot's own prompt. Its presence here is what makes the whole shape
    // admin-only: the hosted-chat, widget and stylesheet surfaces publish their own, smaller
    // resources, and none of them may carry it.
    expect(published).toContain('system_instruction');
  });

  it('publishes no form-settable spelling of the two derived fields', () => {
    // `retrieval_configuration_version` is DERIVED — it moves only when a retrieval knob's VALUE
    // changes, and it travels into every retrieval trace, so a client that could set it could make
    // two different configurations claim the same identity. `public_bot_id` is server-minted once.
    // Both are on the resource and in NEITHER form schema, which is the asymmetry this asserts:
    // readable, never writable.
    const rules = JSON.parse(
      readFileSync(join(here, '..', 'rules', 'UpdateBotRequest.json'), 'utf8'),
    ) as { rules: Readonly<Record<string, unknown>> };

    for (const derived of ['retrieval_configuration_version', 'public_bot_id', 'id']) {
      expect(Object.keys(rules.rules), `${derived} must not be validatable`).not.toContain(derived);
    }
  });

  it('BotTheme is the only type here with optional members, because the wire says so', () => {
    const keys: Record<keyof Required<BotTheme>, true> = {
      primary: true,
      accent: true,
      radius: true,
    };
    expect(Object.keys(keys).sort()).toEqual(['accent', 'primary', 'radius']);

    // The published `theme` object lists all three and REQUIRES none — the one place
    // `DumpOpenApiCommand` emits a partial object, and the reason the `declares every property
    // required` loop below cannot reach it: `theme` is inlined into `BotResource` rather than being a
    // named component, so this is the assertion.
    const wireTheme = schemas['BotResource']?.properties?.['theme'];
    expect(new Set(Object.keys(wireTheme?.properties ?? {}))).toEqual(new Set(Object.keys(keys)));
    expect(wireTheme?.required ?? []).toEqual([]);

    // Every other custom property the renderer writes — the whole `-foreground` and accent-ramp
    // family — is DERIVED at render time and never settable, because contrast is derived and never
    // chosen. A fourth key here would be a value stored forever and rendered nowhere.
    for (const derived of ['primary_foreground', 'accent_foreground', 'ring', 'foreground']) {
      expect(Object.keys(wireTheme?.properties ?? {})).not.toContain(derived);
    }
  });

  it('agrees with the document about all five closed vocabularies, member for member', () => {
    const statuses: Record<BotStatus, true> = {
      draft: true,
      testing: true,
      published: true,
      paused: true,
      archived: true,
    };
    const accessModes: Record<BotAccessMode, true> = { public: true, private: true };
    const answerModes: Record<BotAnswerMode, true> = { strict: true, rag_first: true };
    const scales: Record<EvidenceThresholdScale, true> = {
      logit: true,
      sigmoid: true,
      unit_interval: true,
    };
    const radii: Record<BotThemeRadius, true> = {
      '0rem': true,
      '0.25rem': true,
      '0.5rem': true,
      '0.625rem': true,
      '0.75rem': true,
      '1rem': true,
    };

    // READ OFF THE PROPERTY, not off a named component: the dumper INLINES an enum into the property
    // that carries it. A sixth lifecycle state is a server change first, and this is where the client
    // finds out — the alternative is a `<Select>` that silently cannot express a value the API
    // returns.
    const bot = schemas['BotResource']?.properties;
    expect(new Set(bot?.['status']?.enum ?? []), 'status enum').toEqual(new Set(Object.keys(statuses)));
    expect(new Set(bot?.['access_mode']?.enum ?? []), 'access_mode enum').toEqual(
      new Set(Object.keys(accessModes)),
    );
    expect(new Set(bot?.['answer_mode']?.enum ?? []), 'answer_mode enum').toEqual(
      new Set(Object.keys(answerModes)),
    );
    expect(
      new Set(bot?.['theme']?.properties?.['radius']?.enum ?? []),
      'theme.radius enum',
    ).toEqual(new Set(Object.keys(radii)));

    // THE ONE ENUM THAT CARRIES A `null` MEMBER, and the type does not: the document spells the
    // nullable enum as `type: ["string","null"]` WITH `null` in `enum`, while the TypeScript side
    // spells it `EvidenceThresholdScale | null` on the property. Comparing the raw member list would
    // fail on a correct type, so the null is dropped here and asserted as nullability below —
    // separately, because "which members" and "may it be absent" are different questions.
    const scaleEnum = (bot?.['evidence_threshold_scale']?.enum ?? []).filter(
      (member) => member !== null,
    );
    expect(new Set(scaleEnum), 'evidence_threshold_scale enum').toEqual(new Set(Object.keys(scales)));
    expect(bot?.['evidence_threshold_scale']?.enum).toContain(null);
  });

  it('ListMetaResource is the shared list envelope, not a bot-specific one', () => {
    const keys: Record<keyof ListMetaResource, true> = {
      page: true,
      per_page: true,
      total: true,
      total_pages: true,
      sort: true,
      dir: true,
      filter: true,
    };
    expect(Object.keys(keys).sort()).toEqual([
      'dir',
      'filter',
      'page',
      'per_page',
      'sort',
      'total',
      'total_pages',
    ]);

    // `sort` is typed `string` and NOT a union, deliberately: the sortable set is closed PER
    // ENDPOINT and published in that endpoint's request rules (`IndexBotsRequest`), not here. One
    // component serves every list in the API, so a union here would be one endpoint's vocabulary
    // pretending to be every endpoint's.
    expect(schemas['ListMetaResource']?.properties?.['sort']?.enum).toBeUndefined();
    expect(new Set(schemas['ListMetaResource']?.properties?.['dir']?.enum ?? [])).toEqual(
      new Set(['asc', 'desc']),
    );

    // `total_pages` is PUBLISHED rather than derived, because the client's `per_page` may not be the
    // one the server used — it is clamped. A client that recomputed it would be wrong on every
    // clamped response, and wrong in the direction that disables the Next button.
    expect(Object.keys(keys)).toContain('total_pages');
  });

  it('BotCollectionResource wraps the array under a named key, beside meta', () => {
    const keys: Record<keyof BotCollectionResource, true> = { bots: true, meta: true };
    // `ResponseShape` maps a response KEY to a schema class and has no shape meaning "an array of",
    // and every published component must be `additionalProperties: false`, which an array-typed
    // schema cannot be. `meta` is a SIBLING of the collection inside `data`, not of `data`.
    expect(Object.keys(keys).sort()).toEqual(['bots', 'meta']);
    // `meta` is present on an EMPTY page too: a client that had to branch on its absence would be
    // branching on "did this list have results", which is the question `total` answers.
    expect(schemas['BotCollectionResource']?.required ?? []).toContain('meta');
  });
});

// ── 2. the wire-level pin ────────────────────────────────────────────────────────────────────────

/**
 * NOT `describe.skipIf`. A suite that silently disappears when the schema is absent is a green
 * build that asserted nothing — the mistake form-drift.test.ts:314-318 calls out by name.
 *
 * The branch is taken at COLLECTION time, so exactly one of the two `it`s below is registered and a
 * reader sees which in the reporter output:
 *
 *   schema absent  → a named `it.fails`, reported as "expected to fail", carrying the reason and
 *                    the owner. It is deliberately not a hard red: `SessionResource` cannot be in
 *                    the document until the control plane's controllers exist and Batch 4 reruns
 *                    `php artisan kb:dump-openapi`, and leaving the suite red until then would
 *                    train every other agent in this batch to ignore it.
 *   schema present → the real set comparison, and the pending `it` is GONE rather than left
 *                    passing-because-it-failed. A shape that lands wrong is caught by that
 *                    comparison, not by this branch.
 */
/**
 * ── THE CLOSURE ASSERTION, AND WHY ITS ABSENCE WAS THE REAL FINDING ──────────────────────────────
 *
 * `form-drift.test.ts` closes over the dumped manifest set: every manifest must appear in `MIRRORS` or
 * in `NO_CLIENT_FORM`, so a FormRequest nobody mirrored fails BY NAME. This file had no equivalent —
 * just a hardcoded list of three components — so it could assert that the three it knew about were
 * right and say nothing at all about the other twelve.
 *
 * What that permitted, and what 6B found: `MemberResource`, `InvitationResource` and `InvitationStatus`
 * were hand-written a SECOND time in `apps/web/src/features/members/api.ts`, typed against the PHP
 * resources by eye, with no mechanical link — and the MSW fixtures for the members screen were typed
 * from that local copy, so fixture, type and server formed a three-link chain with no assertion
 * anywhere in it. They agreed field for field when checked. Nothing would have noticed if they had not.
 *
 * So the rule this suite now enforces is the same one `form-drift` enforces: EVERY published component
 * is either mirrored by a type in this package, or explicitly exempt with a reason. Adding a component
 * server-side turns this red until somebody decides which it is — which is the decision that was being
 * skipped.
 */
describe('every published component is mirrored here or exempt with a reason', () => {
  /**
   * Component name → the property set the TS type declares, asserted below. Keyed by WIRE name, which
   * is not always the TS type name (`InvitationPreviewResource` ↔ `InvitationPreview`); see the mapping
   * note in the SessionResource suite for why that asymmetry is real and not worth "fixing".
   */
  const MIRRORED: Readonly<Record<string, readonly string[]>> = {
    SessionResource: ['user', 'current_organization_id', 'organizations'],
    SessionUser: ['id', 'name', 'email', 'email_verified', 'is_platform_owner'],
    SessionMembership: ['id', 'name', 'slug', 'role', 'status'],
    InvitationPreviewResource: ['organization_name', 'email', 'role', 'expires_at'],
    AcknowledgementResource: ['acknowledged'],
    MemberResource: ['user_id', 'name', 'email', 'role', 'status', 'joined_at'],
    MemberCollectionResource: ['members'],
    InvitationResource: [
      'id',
      'email',
      'role',
      'status',
      'expires_at',
      'created_at',
      'invited_by_name',
    ],
    InvitationCollectionResource: ['invitations'],

    // ── the provider-connection surface, mirrored by src/resources/providers.ts ────────────────
    // These four moved out of NO_CLIENT_TYPE below when the Settings → Providers screen landed.
    // `EmbeddingReadinessResource` and its two nested components moved WITH them even though the
    // designation screen is a later step, and that is not anticipation: `POST /provider-connections`
    // returns the readiness verdict as a SECOND TOP-LEVEL KEY beside `data`, so the create form on
    // the providers screen consumes all three today.
    ProviderConnectionResource: [
      'id',
      'provider',
      'label',
      'masked_key',
      'status',
      'created_at',
    ],
    ProviderConnectionCollectionResource: ['connections'],
    EmbeddingReadinessResource: [
      'ready',
      'blocks_ingestion',
      'selected',
      'designated',
      'eligible',
      'rejected',
      'explanation',
    ],
    EmbeddingCandidate: ['connection_id', 'provider', 'model'],
    // MIRRORED rather than NO_CLIENT_TYPE: the designation screen RENDERS this shape. It is what
    // `/settings/embedding` branches on to tell "the pair you stored no longer resolves" apart from
    // "you have never designated one", which were one rendering — a bare `selected: null` — before
    // the field existed.
    EmbeddingDesignation: ['connection_id', 'model'],
    EmbeddingRejection: ['connection_id', 'provider', 'model', 'reason', 'detail'],

    // ── the model catalog, mirrored by src/resources/provider-models.ts ────────────────────────
    // These two moved out of NO_CLIENT_TYPE below when `/settings/providers/[connectionId]` landed.
    // Their exemption named this step as the claimant and said the reason out loud: a type mirrored
    // for nobody is a declaration with no reader to notice it going wrong. There is a reader now —
    // apps/web/src/features/models — so the exemption became an entry.
    ProviderModelResource: [
      'id',
      'connection_id',
      'model',
      'display_name',
      'supported',
      'context_window',
      'max_output_tokens',
      'enabled',
      'input_price_per_million',
      'output_price_per_million',
      'price_currency',
      'created_at',
    ],
    ProviderModelCollectionResource: ['models'],

    // ── the bot surface, mirrored by src/resources/bots.ts ─────────────────────────────────────
    // `ListMetaResource` is NOT bot-specific and is listed here because it arrived with the first
    // paginated list. One component serves every list endpoint in the API, so the second one imports
    // the type rather than declaring a near-identical `PaginationMeta` beside itself — which is the
    // duplication this whole suite was rewritten to catch after `MemberResource` was hand-written a
    // second time in apps/web with no mechanical link to anything. That duplicate exists today at
    // `apps/web/src/lib/table/envelope.ts` and is owed the same swap.
    BotResource: [
      'id',
      'public_bot_id',
      'name',
      'slug',
      'description',
      'welcome_message',
      'placeholder_text',
      'system_instruction',
      'answer_style_instruction',
      'status',
      'access_mode',
      'provider_connection_id',
      'provider_model_id',
      'answer_mode',
      'dense_top_k',
      'sparse_top_k',
      'rerank_candidates',
      'rerank_retain',
      'evidence_threshold',
      'evidence_threshold_scale',
      'retrieval_configuration_version',
      'allow_general_answers',
      'theme',
      'rate_limit_per_minute',
      'rate_limit_per_day',
      'retention_days',
      'collect_end_user_data',
      'consent_text',
      'created_at',
      'updated_at',
    ],
    BotCollectionResource: ['bots', 'meta'],
    ListMetaResource: ['page', 'per_page', 'total', 'total_pages', 'sort', 'dir', 'filter'],

    // ── the two child collections under a bot ──────────────────────────────────────────────────
    // MIRRORED RATHER THAN EXEMPTED WITH A CLAIMANT, and the decision is worth stating because the
    // usual test — "is there a reader today?" — answers no: the editor tab that renders these is
    // being written in parallel with this entry, by an agent that cannot edit this package.
    //
    // That is exactly why they are mirrored. The failure this suite was rewritten to catch is a
    // client hand-writing a resource type from the PHP by eye (`MemberResource`, 6B), and the
    // surest way to produce one is to ship the REQUEST schemas — `botDomainCreateSchema`,
    // `starterQuestionUpdateSchema` and their siblings are MIRRORS entries in form-drift.test.ts as
    // of this change — while leaving the RESPONSE shapes unmirrored. A form whose 201 body has no
    // type is a form whose caller declares one locally.
    //
    // `permits_embedding` is the field worth naming twice. It is DERIVED (`status === 'active'` and
    // nothing else) and it is published anyway, because a client computing it writes the check as
    // "not disabled" — which admits `pending`, and would admit any status added later.
    BotDomainResource: ['id', 'origin', 'status', 'permits_embedding', 'created_at', 'updated_at'],
    BotDomainCollectionResource: ['domains'],
    BotStarterQuestionResource: ['id', 'question', 'sort_order', 'created_at', 'updated_at'],
    BotStarterQuestionCollectionResource: ['starter_questions'],
  };

  /**
   * Published components with no TypeScript mirror, and why. An ALLOW-LIST, not a skip: an entry is a
   * decision with a reason, and CI diffs the file that produced it.
   */
  const NO_CLIENT_TYPE: Readonly<Record<string, string>> = {
    // The envelopes are declared in src/envelope.ts and consumed through `KbError`/`toKbError` rather
    // than as resource types — a caller never holds one, it holds the KbError built from it. Mirroring
    // them as resources would create a second declaration of a shape src/envelope.ts already owns.
    ErrorEnvelope: 'declared in src/envelope.ts and consumed via KbError, never held as a resource',
    ValidationErrorEnvelope:
      'declared in src/envelope.ts and consumed via KbError.errors, never held as a resource',

    // ── THE FOUR PROVIDER-CONNECTION ENTRIES THAT USED TO SIT HERE ARE GONE ────────────────────
    // They read "no client yet; the credential-field decision is recorded in form-drift" and "read by
    // no client yet — the designation screen is not built". Both stopped being true with
    // apps/web/src/features/providers, so they are MIRRORED entries above. Note that the CREDENTIAL
    // argument they gestured at was never an argument against the RESOURCE type — the resource carries
    // `masked_key` and no key material — it is an argument against a shared REQUEST schema, and it is
    // still in force there: `StoreProviderConnectionRequest` and `RotateProviderCredentialRequest` are
    // both NO_CLIENT_FORM in form-drift.test.ts.
    //
    // THE TWO MODEL-CATALOG ENTRIES THAT USED TO SIT HERE ARE GONE, and their note is worth keeping
    // for the same reason `StoreInvitationRequest`'s is kept in form-drift.test.ts: they are the
    // worked example of what this list is for. They read "the model catalogue screen is A4a's, next
    // batch; no client in this workspace reads a model row yet" — an entry that names a claimant and
    // a condition, rather than an exemption on the merits. The condition was met by
    // apps/web/src/features/models, so the entry became a MIRRORED one above and this list shrank
    // rather than growing an explanation.
  };

  it('the published set is exactly MIRRORED plus NO_CLIENT_TYPE', () => {
    // The positive control first: an empty document would make the comparison below vacuous, and this
    // suite reads a generated file that a failed dump could truncate.
    expect(Object.keys(schemas).length, 'the OpenAPI document must publish components').toBeGreaterThan(
      0,
    );

    expect(Object.keys(schemas).sort()).toEqual(
      [...Object.keys(MIRRORED), ...Object.keys(NO_CLIENT_TYPE)].sort(),
    );
  });

  it('no component is claimed twice', () => {
    for (const name of Object.keys(MIRRORED)) {
      expect(name in NO_CLIENT_TYPE, `${name} must be mirrored OR exempt, not both`).toBe(false);
    }
  });

  it('every mirrored component matches its declared property set', () => {
    for (const [component, keys] of Object.entries(MIRRORED)) {
      const published = schemas[component];
      expect(published, `${component} must be a published component`).toBeDefined();
      expect(new Set(Object.keys(published?.properties ?? {})), component).toEqual(new Set(keys));
    }
  });

  /**
   * FINDING N3: the comparison above reads property NAMES and nothing else, so a field that changed
   * from `string` to `string | null` server-side matched a TypeScript type that still promised a
   * string. The `Record<keyof X, true>` maps in section 1 close the name↔interface link and the price
   * assignments close `string | null` where a price is concerned; `created_at` closed nothing.
   *
   * Two of the three missing axes are closed here and the third is declined out loud below.
   */
  it('declares every property required — nothing on this wire is optional', () => {
    // The client types have no `?` on any member, so an OPTIONAL property would be a type promising
    // a value the server may omit. Asserted for every mirrored component rather than for the two new
    // ones, because it is a property of how `DumpOpenApiCommand` emits a resource and the day one
    // component stops holding it is the day to find out.
    for (const [component, keys] of Object.entries(MIRRORED)) {
      expect(new Set(schemas[component]?.required ?? []), component).toEqual(new Set(keys));
    }
  });

  it('agrees with each mirrored type about which properties may be null', () => {
    /**
     * The declared side. Each map is `Record<NullableKeys<X>, true>` and is therefore CLOSED against
     * its interface in both directions — that is the half a runtime comparison cannot do — and its
     * KEYS are then set-compared with the wire. So `created_at: ['string','null']` ↔ `string | null`
     * is now asserted on both new resources, which is exactly what N3 named.
     *
     * A component absent from this list is asserted to have NO nullable property, which is why the
     * loop below iterates `MIRRORED` rather than this object: a field that GAINS a `null` on a
     * component nobody listed must fail, not pass by omission.
     */
    const NULLABLE: Readonly<Record<string, Readonly<Record<string, true>>>> = {
      ProviderConnectionResource: {
        created_at: true,
      } satisfies Record<NullableKeys<ProviderConnectionResource>, true>,

      ProviderModelResource: {
        input_price_per_million: true,
        output_price_per_million: true,
        price_currency: true,
        created_at: true,
      } satisfies Record<NullableKeys<ProviderModelResource>, true>,

      // `selected` and `designated` are the two nullable OBJECTS in the document, both published as
      // `anyOf: [{$ref}, {type: null}]` rather than a type array — see `wireNullable`.
      EmbeddingReadinessResource: {
        selected: true,
        designated: true,
      } satisfies Record<NullableKeys<EmbeddingReadinessResource>, true>,

      // ── THE THREE THAT ARE NOT PROVIDER RESOURCES ────────────────────────────────────────────
      // They are here because the assertion REPORTED them on its first two runs. N3 asked only about
      // the two new provider resources; the loop covers every mirrored component, so these were
      // omissions rather than out of scope, and writing them down is the check working.
      //
      // `current_organization_id` is the one nullable field in this package that is load-bearing
      // rather than incidental: null means "signed in, no organization selected", a real reachable
      // state the org switcher renders for.
      SessionResource: {
        current_organization_id: true,
      } satisfies Record<NullableKeys<SessionResource>, true>,

      // Both are timestamps that are nullable only because an UNSAVED model has none; every
      // persisted row carries one. Same shape as the two `created_at`s above.
      MemberResource: {
        joined_at: true,
      } satisfies Record<NullableKeys<MemberResource>, true>,

      InvitationResource: {
        created_at: true,
      } satisfies Record<NullableKeys<InvitationResource>, true>,

      /**
       * FIFTEEN OF THIRTY, which is half the resource, and that is the shape of the thing rather
       * than an accident: a bot is created with two fields typed and every optional one unset, so
       * "null means not configured" is the normal state of most of this row and the console renders
       * a platform default for each. Three of them are load-bearing beyond that:
       *
       *   `provider_connection_id`/`provider_model_id` are null on a bot that has not been
       *   configured, which is the FIRST state every bot is in — a non-nullable type here would make
       *   the create response unrepresentable.
       *
       *   `evidence_threshold` is null WITH NO PLATFORM DEFAULT, deliberately: the scale is a
       *   property of the (provider, model) pair, so there is no number that means "unset but safe".
       *
       *   the three limits are null when the PLATFORM default applies, which is a different fact
       *   from a configured limit that happens to equal it — an operator asking whether somebody set
       *   this has to be able to tell them apart, and a `number` type collapses the two.
       */
      BotResource: {
        description: true,
        welcome_message: true,
        placeholder_text: true,
        system_instruction: true,
        answer_style_instruction: true,
        provider_connection_id: true,
        provider_model_id: true,
        evidence_threshold: true,
        evidence_threshold_scale: true,
        rate_limit_per_minute: true,
        rate_limit_per_day: true,
        retention_days: true,
        consent_text: true,
        created_at: true,
        updated_at: true,
      } satisfies Record<NullableKeys<BotResource>, true>,

      // Both child collections carry the same nullable pair as every other row in this document:
      // timestamps that are null only for a record whose timestamp was never set. `origin`,
      // `question` and `sort_order` are NOT nullable and that is load-bearing on all three —
      // an allow-list row with no origin grants nothing anyone can reason about, a chip with no
      // label is a control an end user can see and cannot read, and a null position would put a
      // gap in a sequence the server guarantees is 0..n-1.
      BotDomainResource: {
        created_at: true,
        updated_at: true,
      } satisfies Record<NullableKeys<BotDomainResource>, true>,

      BotStarterQuestionResource: {
        created_at: true,
        updated_at: true,
      } satisfies Record<NullableKeys<BotStarterQuestionResource>, true>,

      // `filter` is null rather than `''`, because an empty filter is no filter and two spellings of
      // "unfiltered" would make an unfiltered list's cache key depend on whether the client sent the
      // parameter at all. Every other field on this envelope is a number the pager does arithmetic
      // with, and a nullable one of those is a pager that renders `NaN`.
      ListMetaResource: {
        filter: true,
      } satisfies Record<NullableKeys<ListMetaResource>, true>,
    };

    for (const component of Object.keys(MIRRORED)) {
      expect(wireNullableKeys(component), component).toEqual(
        new Set(Object.keys(NULLABLE[component] ?? {})),
      );
    }
  });

  it('…and that check has teeth: both spellings of nullable are recognised', () => {
    // Without this, a `wireNullable` that understood neither spelling would report every set as
    // empty and the assertion above would be green against maps that were also empty — the vacuous
    // pass this file's positive controls exist to prevent. 3.0's `nullable: true` is NOT recognised
    // and must not be: the dump emits 3.1, and accepting a spelling the document never uses would
    // hide the day it starts emitting one.
    expect(wireNullable({ type: ['string', 'null'] })).toBe(true);
    expect(wireNullable({ anyOf: [{ type: 'object' }, { type: 'null' }] })).toBe(true);
    expect(wireNullable({ type: 'string' })).toBe(false);
    expect(wireNullable({ anyOf: [{ type: 'string' }, { type: 'integer' }] })).toBe(false);
    expect(wireNullable(undefined)).toBe(false);
    // The two real ones, read out of the document rather than constructed.
    expect(wireNullableKeys('ProviderModelResource').has('created_at')).toBe(true);
    expect(wireNullableKeys('ProviderModelResource').has('model')).toBe(false);
  });

  /**
   * THE THIRD AXIS IS DECLINED, WITH A REASON RATHER THAN IN SILENCE.
   *
   * Scalar TYPE — `string` vs `integer` vs `boolean` — is still unasserted. Closing it needs a
   * hand-written expected JSON type per property, and that is a THIRD spelling of every resource
   * (the PHP resource, the TypeScript interface, and a table here), which is the shape `rhf-zod-forms`
   * argues against for rules and the same argument applies to types: the third copy is the one nobody
   * updates, and it drifts into asserting what it asserted last quarter.
   *
   * What is left uncovered is narrow, because the two axes above catch the ways this has actually
   * gone wrong. A property whose type changed while its name and its nullability did not — `integer`
   * to `string` on `context_window`, say — would pass this suite and fail at the first render.
   *
   * It is caught one layer back instead, and by a check that cannot be written here:
   * `services/core-api/tests/Contract/OpenApiDocumentTest.php` SERIALIZES a real resource and asserts
   * every emitted value against the type its own schema declares (its `$types`/`get_debug_type`
   * comparison), so a document that describes `integer` while the model emits a string fails there.
   * That is a stronger check than a table in this file would be — it reads the value, not a second
   * declaration of it — and it is why the gap is accepted rather than papered over.
   */

  it('every exemption states a reason', () => {
    for (const [component, reason] of Object.entries(NO_CLIENT_TYPE)) {
      expect(reason.length, `${component}'s exemption must say why`).toBeGreaterThan(20);
    }
  });
});

describe('the wire shape of SessionResource', () => {
  const wire = schemas['SessionResource'];

  if (wire === undefined) {
    it.fails(
      'PENDING: components.schemas.SessionResource is absent from openapi/core-api.openapi.json — ' +
        'owed by the control plane (GET /api/v1/me), regenerated by `php artisan kb:dump-openapi`. ' +
        'When it lands, this branch disappears and the comparison below runs instead.',
      () => {
        expect(Object.keys(schemas)).toContain('SessionResource');
      },
    );
  } else {
    it('matches the hand-written SessionResource key for key', () => {
      expect(new Set(Object.keys(wire.properties ?? {}))).toEqual(
        new Set(['user', 'current_organization_id', 'organizations']),
      );
    });

    it('publishes the sibling components this package mirrors', () => {
      // KEYED BY WIRE COMPONENT NAME, NOT BY TYPESCRIPT TYPE NAME. The two conventions genuinely
      // differ: the PHP resources publish `InvitationPreviewResource` (the class is
      // `InvitationPreviewResource`), while the type this package exports is `InvitationPreview` —
      // a `Resource` suffix carries no meaning on the client, where nothing else is a resource. But
      // `SessionUser` and `SessionMembership` have no suffix on either side, because they are
      // component siblings rather than top-level resources. So the mapping is explicit; assuming the
      // two names are one string is what made this assertion fail the first time the document
      // actually contained them.
      const expected: readonly (readonly [string, readonly string[]])[] = [
        ['SessionUser', ['id', 'name', 'email', 'email_verified', 'is_platform_owner']],
        ['SessionMembership', ['id', 'name', 'slug', 'role', 'status']],
        ['InvitationPreviewResource', ['organization_name', 'email', 'role', 'expires_at']],
      ];

      for (const [component, keys] of expected) {
        const published = schemas[component];
        expect(published, `${component} must be a published component`).toBeDefined();
        expect(new Set(Object.keys(published?.properties ?? {})), component).toEqual(new Set(keys));
      }
    });

    it('agrees with the Role and MembershipStatus unions member for member', () => {
      // READ OFF THE PROPERTY, NOT OFF A NAMED COMPONENT. `DumpOpenApiCommand` INLINES an enum into
      // the property that carries it; it publishes no `Role` or `MembershipStatus` component, so
      // looking for one found `undefined` and the assertion compared `undefined` to the expected set —
      // which fails for the right reason but names the wrong cause. A vendor of a fifth role adds it
      // server-side first, and this is where the client finds out.
      const membership = schemas['SessionMembership']?.properties;

      expect(new Set(membership?.['role']?.enum ?? []), 'role enum').toEqual(
        new Set(['owner', 'admin', 'knowledge_manager', 'analyst']),
      );
      expect(new Set(membership?.['status']?.enum ?? []), 'status enum').toEqual(
        new Set(['active', 'invited', 'suspended']),
      );
    });
  }
});

// ── 3. the budget pin ────────────────────────────────────────────────────────────────────────────

/**
 * The property this pin exists for: `src/index.ts` re-exports the session types with `export type`,
 * `verbatimModuleSyntax: true` erases that, and therefore NOTHING from `src/resources/` reaches
 * dist/index.js. A bare `export { Role }` would compile, would typecheck, would pass review — and
 * would put a module the widget never reads inside its 30 kB brotli app shell.
 *
 * Reading the BUILT file rather than the source is the whole point: the source cannot tell you what
 * the emitter did. `pnpm contracts:build` is therefore a prerequisite of this suite, and it already
 * used to run before Vitest in CI, and now runs only when a human remembers it.
 */
describe('the root entry export list', () => {
  it('dist/ has been built — run `pnpm contracts:build` first', () => {
    expect(
      existsSync(distEntry),
      'dist/index.js is missing. It is gitignored and this suite reads the EMITTED output, ' +
        'because only the emitted output can prove a type-only re-export was erased. ' +
        'Run `pnpm contracts:build` first — nothing runs it for you, as this repo has no CI.',
    ).toBe(true);
  });

  it('exports exactly the runtime values it is supposed to, and no resource module', async () => {
    const entry = (await import('../dist/index.js')) as Record<string, unknown>;

    expect(Object.keys(entry).sort()).toEqual(
      [
        'CLIENT_EVENT_NAMES',
        'ERROR_CLASSES',
        'KbError',
        'STREAM_LOST',
        'TERMINAL_EVENTS',
        'createFrameBuffer',
        'isErrorClass',
        'isKbErrorEnvelope',
        'isKbEventName',
        'isKbValidationEnvelope',
        'isTerminalEvent',
        'parseFrame',
        'parseRetryAfter',
        'toKbError',
        'toKbEvent',
      ].sort(),
    );
  });

  it('emits no reference to the resources module at all', () => {
    // Belt and braces on the assertion above: a value could be re-exported under a DIFFERENT name
    // and the key list would still look plausible. The emitted text may not mention the module.
    const emitted = readFileSync(distEntry, 'utf8');
    expect(emitted).not.toContain('resources/session');
    expect(emitted).not.toContain('knowledge_manager');
    // Same property for the provider module, and one string chosen for a second reason: `masked_key`
    // appearing in the ROOT entry would mean `src/resources/providers.ts` emitted a runtime value,
    // which is both a budget regression and the first step of the credential-seeding bug that module's
    // docblock is about.
    expect(emitted).not.toContain('resources/providers');
    expect(emitted).not.toContain('masked_key');
    // And the model catalog, whose second string is chosen the same way: `input_price_per_million`
    // in the ROOT entry would mean `src/resources/provider-models.ts` emitted a runtime value —
    // most plausibly a capability tuple or a price constant, neither of which belongs in a widget's
    // app shell and neither of which this package may declare at all.
    expect(emitted).not.toContain('resources/provider-models');
    expect(emitted).not.toContain('input_price_per_million');
    // And the bot surface, whose second string is chosen for the reason that module holds five
    // closed vocabularies as UNIONS: `knowledge_manager` above proves `Role` stayed a type, and
    // `unit_interval` proves the same of `EvidenceThresholdScale`. The iterable tuples live behind
    // `@kb/contracts/forms`, and a tuple that migrated here would be a widget app-shell regression
    // that compiles, typechecks and passes review.
    expect(emitted).not.toContain('resources/bots');
    expect(emitted).not.toContain('unit_interval');
    expect(emitted).not.toContain('public_bot_id');
  });

  it('keeps zod out of the root entry', () => {
    // The same budget property, for the reason ADR-028 exists: the schemas live behind
    // `@kb/contracts/forms` so apps/widget never pulls Zod in, and `src/forms/auth.ts` must not
    // change that. Relying on tree-shaking to remove a library from a budget is the
    // silently-passing size gate preact-vite-library warns about.
    const emitted = readFileSync(distEntry, 'utf8');
    expect(emitted).not.toContain('zod');
    expect(emitted).not.toContain('loginSchema');
  });
});
