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
import type {
  BotSourceAssignmentCollectionResource,
  BotSourceAssignmentResource,
} from '../src/resources/bot-source-assignments.js';
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
import type {
  OrgUploadLimits,
  SourceActiveVersionResource,
  SourceCollectionResource,
  SourceDetailResource,
  SourceResource,
  SourceStatus,
  SourceType,
  SourceWarningResource,
} from '../src/resources/sources.js';

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
  /**
   * The element schema of an array property. Declared for the same reason `$ref` is: an array of a
   * published component is where a near-identical copy of that component gets inlined instead, and
   * `warnings: {type: 'array', items: {$ref: SourceWarningResource}}` is only distinguishable from
   * `warnings: {type: 'array', items: {type: 'object', properties: …}}` by reading this node.
   */
  readonly items?: OpenApiSchemaNode;
  readonly required?: readonly string[];
  /**
   * A non-nullable reference to another component, which the nullable case above spells inside
   * `anyOf` instead. Declared so a suite can assert WHICH component a property points at: the second
   * paginated list is exactly where a near-identical `PaginationMeta` would have been declared beside
   * the collection instead of `ListMetaResource` being reused, and reading the `$ref` is the only way
   * to tell the two apart from this side of the wire — a property-NAME comparison sees `meta` either
   * way.
   */
  readonly $ref?: string;
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
  it('BotResource declares exactly thirty-one keys and no credential-shaped one', () => {
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
      instructions_visible: true,
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
    expect(Object.keys(keys)).toHaveLength(31);

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

  it('publishes `instructions_visible` as a boolean, so a withheld null is not read as "not set"', () => {
    // THE PROJECTION IS SELF-DESCRIBING, and this is the field that says so. Both instruction fields
    // are `null` for a caller without `bots.manage`, whatever is stored, and `UpdateBotRequest` rules
    // them `sometimes|nullable|string` — so a form seeded from a withheld body PATCHes `null` over an
    // operator-authored prompt and gets a 200. The client reads this key instead of re-deriving the
    // grant from a role name it holds locally.
    //
    // A BOOLEAN AND REQUIRED, not a nullable or optional one: "the server did not say" is exactly the
    // state that put the data-loss path there, so the wire may not be able to express it.
    const bot = schemas['BotResource'];
    expect(bot?.properties?.['instructions_visible']).toMatchObject({ type: 'boolean' });
    expect(bot?.required ?? []).toContain('instructions_visible');

    // The TYPE side of the same claim, asserted by assignment rather than by a string: a `boolean |
    // null` on the mirror would fail to typecheck here.
    const projection: Pick<
      BotResource,
      'instructions_visible' | 'system_instruction' | 'answer_style_instruction'
    > = { instructions_visible: false, system_instruction: null, answer_style_instruction: null };
    expect(projection.instructions_visible).toBe(false);
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

/**
 * The knowledge-source types, pinned the same three ways — plus the vocabulary pin, which is the only
 * thing standing under `SourceStatus` at all.
 *
 * `bots.ts` holds each of its five vocabularies TWICE on purpose (a union here, an iterable tuple
 * behind `@kb/contracts/forms`) and each spelling has its own pin: the union against the document's
 * inlined enum, the tuple against the `in:` probes in form-drift.test.ts. `sources.ts` holds its two
 * ONCE, because no source form ships yet — `UpdateSourceStatusRequest` is NO_CLIENT_FORM over there,
 * recorded as OWED — so the enum comparison below is the whole of the pin rather than half of it.
 * That is the correct amount for a vocabulary nothing iterates, and it is the assertion that has to
 * survive the day the tuple arrives: it goes on pinning the union, and the tuple brings its own.
 */
describe('the hand-written source types', () => {
  it('SourceResource declares exactly sixteen keys, and no ownership or version-pointer one', () => {
    const keys: Record<keyof SourceResource, true> = {
      id: true,
      type: true,
      name: true,
      description: true,
      origin_url: true,
      status: true,
      status_permits_retrieval: true,
      status_is_processing: true,
      tags: true,
      effective_at: true,
      expires_at: true,
      created_by: true,
      created_at: true,
      updated_at: true,
      deleted_at: true,
      purged_at: true,
    };
    expect(Object.keys(keys)).toHaveLength(16);

    // `organization_id` is the one every tenant-owned resource in this document withholds, for the
    // reason `rhf-zod-forms` NN1 and `kb-tenancy-isolation` NN6 agree on: the organization comes from
    // the authenticated context, and a shape that carried it invites a client to send it back.
    //
    // THE OTHER THREE ARE THE INTERESTING ONES, and they are absent because a SOURCE IS NOT THE UNIT
    // OF VERSIONING. One crawl source owns hundreds of independently-versioned items, so there is no
    // single active version to point at — a field named any of these would be a scalar answer to a
    // per-item question, and every client that read it would render one document's state as the whole
    // source's.
    for (const banned of [
      'organization_id',
      'active_version_id',
      'source_version_id',
      'content_hash',
    ]) {
      expect(Object.keys(keys), `${banned} must not be on this shape`).not.toContain(banned);
    }
  });

  it('publishes both lifecycle predicates as booleans, so no client re-derives them', () => {
    // THE SAME PROPERTY `permits_embedding` HOLDS ON `BotDomainResource`, and it is here for the same
    // failure: a check spelled "not disabled" admits `deleting`, and a sixteenth state would be
    // admitted by every negative test in every client. Both are REQUIRED booleans rather than
    // nullable ones — "the server did not say" is not a state a predicate may express.
    const source = schemas['SourceResource'];
    for (const predicate of ['status_permits_retrieval', 'status_is_processing']) {
      expect(source?.properties?.[predicate], predicate).toMatchObject({ type: 'boolean' });
      expect(source?.required ?? [], predicate).toContain(predicate);
    }

    // The TYPE side of the same claim, asserted by assignment rather than by a string: a
    // `boolean | null` on either member is a typecheck failure in this file.
    const predicates: Pick<
      SourceResource,
      'status_permits_retrieval' | 'status_is_processing'
    > = { status_permits_retrieval: false, status_is_processing: false };
    expect(predicates.status_permits_retrieval).toBe(false);

    // `status_permits_retrieval` IS ONE TERM OF FOUR (kb-tenancy-isolation NN2), so the shape must not
    // publish anything that reads as the whole answer. A `retrievable` or `is_live` field would be a
    // claim this service cannot make: the other three terms are the item's active-version pointer, the
    // bot assignment and the organization, and none of them is on this row.
    for (const overclaim of ['retrievable', 'is_live', 'searchable']) {
      expect(Object.keys(source?.properties ?? {}), overclaim).not.toContain(overclaim);
    }
  });

  it('publishes `tags` as a non-null array of strings, which is why nothing guards it', () => {
    // ALWAYS PRESENT, EMPTY WHEN UNTAGGED. Null would be a second spelling of "no tags" and every
    // renderer would need a nullish guard that one of them forgets — the same argument `description`
    // makes in the other direction, where null IS the only spelling of unset.
    const tags = schemas['SourceResource']?.properties?.['tags'];
    expect(tags?.type).toBe('array');
    expect(wireNullable(tags)).toBe(false);

    // Open, with no enum on either side: the vocabulary is whatever operators typed, and a closed copy
    // in this package would reject a tag somebody created this morning.
    expect(tags?.enum).toBeUndefined();

    // …and therefore this module exports no tuple, no status list and no constant at all. The
    // assertion is against the module's own text rather than an export list, because pin 3 below only
    // proves the ROOT entry stayed clean — a value declared here and not re-exported would pass it.
    const text = readFileSync(join(here, '..', 'src', 'resources', 'sources.ts'), 'utf8');
    expect(text).not.toMatch(/export const/);
  });

  it('agrees with the document about both closed vocabularies, member for member', () => {
    const types: Record<SourceType, true> = { file: true, url: true, text: true };
    const statuses: Record<SourceStatus, true> = {
      draft: true,
      queued: true,
      fetching: true,
      parsing: true,
      normalizing: true,
      chunking: true,
      embedding: true,
      indexing: true,
      ready: true,
      ready_with_warnings: true,
      failed: true,
      disabled: true,
      deleting: true,
      deleted: true,
      archived: true,
    };

    // READ OFF THE PROPERTY, not off a named component: the dumper INLINES an enum into the property
    // that carries it. A sixteenth lifecycle state is a server change first, and — with no tuple
    // sibling behind `@kb/contracts/forms` — this is the ONLY place a client finds out.
    const source = schemas['SourceResource']?.properties;
    expect(new Set(source?.['type']?.enum ?? []), 'type enum').toEqual(new Set(Object.keys(types)));
    expect(new Set(source?.['status']?.enum ?? []), 'status enum').toEqual(
      new Set(Object.keys(statuses)),
    );

    // Neither is nullable, unlike `BotResource.evidence_threshold_scale`: a source always has a kind
    // and always has a state, and a null in either slot would be a row no screen could render.
    expect(source?.['type']?.enum).not.toContain(null);
    expect(source?.['status']?.enum).not.toContain(null);

    // THE FIFTEEN ARE THE INGESTION LIFECYCLE, NOT THE TRANSITION VOCABULARY. `UpdateSourceStatusRequest`
    // accepts exactly two of them (`disabled`, `ready`) — every other move is the pipeline's, and a
    // console offering a `<Select>` over this union would be offering to publish a version nothing
    // verified. Read off the rules manifest rather than restated here, so the day the server widens the
    // transition set this comparison is what notices.
    const transition = JSON.parse(
      readFileSync(join(here, '..', 'rules', 'UpdateSourceStatusRequest.json'), 'utf8'),
    ) as { rules: Readonly<Record<string, readonly string[]>> };
    const members = (transition.rules['status'] ?? [])
      .filter((rule) => rule.startsWith('in:'))
      .flatMap((rule) => rule.slice('in:'.length).split(','))
      .map((member) => member.replace(/^"|"$/g, ''));

    expect(new Set(members)).toEqual(new Set(['disabled', 'ready']));
    for (const member of members) expect(Object.keys(statuses)).toContain(member);
  });

  it('OrgUploadLimits declares exactly the three ceilings the server publishes', () => {
    // PIN 1, TYPE-LEVEL, and it is the ONLY type-level link this shape has: it declares no nullable
    // property, so the `NULLABLE` map in section 2 has no entry for it, and the `MIRRORED` comparison
    // there is a list of STRINGS that would go on passing if the interface were renamed out from
    // under it. `Record<keyof OrgUploadLimits, true>` closes that — a renamed or added member fails
    // the TYPECHECK here, in a file a reader is looking at.
    const keys: Record<keyof OrgUploadLimits, true> = {
      max_bytes: true,
      allowed_mime: true,
      max_batch: true,
    };
    expect(Object.keys(keys)).toHaveLength(3);

    // PIN 2, WIRE-LEVEL, on the two things the property-name comparison cannot say. `max_bytes` is
    // BYTES on this wire while the server's rule is enforced in kibibytes — the conversion happens
    // once, server-side — so an `integer` here is the number a `File.size` is compared against and a
    // client that converts again is 1024× off. `allowed_mime` is an ARRAY: a string would be a
    // comma-joined list every reader would split differently, and `accept=` would be the only one
    // that happened to work.
    const wire = schemas['OrgUploadLimitsResource'];
    expect(wire?.properties?.['max_bytes']).toMatchObject({ type: 'integer' });
    expect(wire?.properties?.['max_batch']).toMatchObject({ type: 'integer' });
    expect(wire?.properties?.['allowed_mime']).toMatchObject({
      type: 'array',
      items: { type: 'string' },
    });

    // THE OPERATION ITSELF IS NOT ASSERTED HERE, and the omission is deliberate rather than an
    // oversight. `GET .../sources/upload-limits` (`admin.sources.upload-limits`) is what makes this a
    // mirror rather than an invention, but no suite in this file reads `paths` — the harness types
    // `components.schemas` and nothing else — and growing a path-item type for one operation would be
    // a second document reader that only this test uses. A component that stopped being served would
    // stop being emitted, and the set comparison in section 2 fails on it by name.
  });

  it('SourceCollectionResource wraps the array under a named key, beside the SHARED meta', () => {
    const keys: Record<keyof SourceCollectionResource, true> = { sources: true, meta: true };
    expect(Object.keys(keys).sort()).toEqual(['meta', 'sources']);

    // `meta` is a `$ref` to `ListMetaResource` — the SAME component the bot list uses — and the type
    // imports it rather than declaring a near-identical `PaginationMeta`. That duplication is what
    // this whole suite was rewritten to catch, and a second paginated list is precisely where it would
    // have happened.
    expect(schemas['SourceCollectionResource']?.properties?.['meta']?.$ref).toBe(
      '#/components/schemas/ListMetaResource',
    );
    // Present on an EMPTY page too: a client that branched on its absence would be branching on "did
    // this list have results", which is the question `total` answers.
    expect(schemas['SourceCollectionResource']?.required ?? []).toContain('meta');
  });

  /**
   * ── THE EXTENSION PIN, AND IT IS THE POINT OF THIS WHOLE PAIR OF TYPES ─────────────────────────
   *
   * `SourceDetailResource` is declared as `extends SourceResource` rather than as a second flat
   * interface, because the SERVER composes the same way: the detail resource builds on the list
   * resource's array and adds to it. The failure a transcription invites has no error and no failing
   * type — the server adds a seventeenth field, the list screen renders it, the detail screen renders
   * `undefined` — so the relationship is asserted here in both spellings that can catch it.
   *
   * This one is the TYPE-LEVEL half. A conditional type resolved to a literal and then ASSIGNED is
   * the assertion: if somebody re-declares the detail as a flat interface that happens to be missing
   * a field, the first line stops being `true` and `pnpm contracts:typecheck` fails in a test file a
   * reader is looking at. The SECOND line matters just as much and is easy to leave out: without it a
   * detail type that had drifted into being IDENTICAL to `SourceResource` — every detail-only field
   * dropped — would satisfy the first assertion perfectly.
   */
  it('SourceDetailResource IS a SourceResource, at the type level and in one direction only', () => {
    const detailIsASource: SourceDetailResource extends SourceResource ? true : false = true;
    const sourceIsNotADetail: SourceResource extends SourceDetailResource ? true : false = false;

    expect([detailIsASource, sourceIsNotADetail]).toEqual([true, false]);
  });

  it('SourceDetailResource adds twelve keys and re-declares none of the sixteen', () => {
    // `Record<keyof X, true>` again, TOTAL and CLOSED in both directions — and here it is closed over
    // the INHERITED members too, which is what makes it a check on the extension rather than on the
    // twelve: drop `extends` and this map is missing sixteen entries.
    const keys: Record<keyof SourceDetailResource, true> = {
      id: true,
      type: true,
      name: true,
      description: true,
      origin_url: true,
      status: true,
      status_permits_retrieval: true,
      status_is_processing: true,
      tags: true,
      effective_at: true,
      expires_at: true,
      created_by: true,
      created_at: true,
      updated_at: true,
      deleted_at: true,
      purged_at: true,
      item_count: true,
      active_version_count: true,
      active_version: true,
      page_count: true,
      slide_count: true,
      sheet_count: true,
      element_count: true,
      chunk_count: true,
      warnings: true,
      warnings_truncated: true,
      content_preview: true,
      content_preview_truncated: true,
    };
    expect(Object.keys(keys)).toHaveLength(28);

    // THE FOUR BANNED NAMES FROM `SourceResource` ARE STILL BANNED, and that is not automatic on this
    // shape — this is the one resource in the document that legitimately carries version information,
    // so it is exactly where `active_version_id` would land. It does not: the field is
    // `active_version`, a whole nested resource or null, and the difference is that a bare id invites
    // a client to treat "the source's version" as a scalar, which it is not for any multi-item source.
    for (const banned of [
      'organization_id',
      'active_version_id',
      'source_version_id',
      'content_hash',
    ]) {
      expect(Object.keys(keys), `${banned} must not be on this shape`).not.toContain(banned);
    }
  });

  /**
   * The WIRE-LEVEL half of the extension pin, and the half that catches the failure this side cannot
   * see: the server changing `SourceResource` and NOT carrying it into the detail projection. Read
   * out of the document rather than restated, so it needs no maintenance and cannot be satisfied by
   * updating a list here.
   */
  it('the wire composes the same way: every SourceResource property is on the detail, and required', () => {
    // Positive control — an empty base would make the loop below vacuous.
    const base = Object.keys(schemas['SourceResource']?.properties ?? {});
    expect(base.length, 'SourceResource must publish properties').toBeGreaterThan(0);

    const detail = schemas['SourceDetailResource'];
    for (const key of base) {
      expect(Object.keys(detail?.properties ?? {}), key).toContain(key);
      // `required` too: an inherited field that arrived OPTIONAL on the detail would be a type
      // promising a value the server may omit, which the `declares every property required` loop in
      // section 2 asserts globally and this states for the composition specifically.
      expect(detail?.required ?? [], key).toContain(key);
    }

    // NULLABILITY CARRIES TOO. `description` non-null on the detail and nullable on the list would be
    // one field with two contracts, and the TypeScript side cannot express the difference at all —
    // `extends` gives the inherited member exactly one type.
    const detailNullable = wireNullableKeys('SourceDetailResource');
    for (const key of wireNullableKeys('SourceResource')) {
      expect(detailNullable, `${key} is nullable on SourceResource`).toContain(key);
    }
  });

  /**
   * THE STRONGEST FORM OF THE SAME PIN, AND IT COSTS NOTHING BECAUSE IT COMPARES THE DOCUMENT WITH
   * ITSELF — which is why it is worth having where the third axis is otherwise DECLINED in this file.
   *
   * The decline is a few hundred lines below: scalar TYPE (`string` vs `integer` vs `boolean`) is
   * unasserted here because closing it needs a hand-written expected JSON type per property, i.e. a
   * third spelling of every resource that nobody updates. That argument does not reach this pair.
   * There is no third copy: `SourceResource`'s node IS the expected value for `SourceDetailResource`'s
   * node, because the server composes one from the other. So `origin_url` going from `string` to
   * `integer` on one shape and not the other fails here, on the one pair of components where the
   * question can be asked for free.
   *
   * `description` IS EXCLUDED, AND THE EXCLUSION IS SLACK RATHER THAN AN OVERSIGHT. All sixteen
   * property nodes are byte-identical today — measured, not assumed, which is itself the evidence
   * that the server composes rather than transcribes — so pinning the prose would cost nothing on
   * the day it was written. It is excluded anyway because a reworded sentence on one shape is a
   * legitimate, harmless server change, and a suite that reds for a harmless reason is a suite
   * somebody loosens in a hurry, taking the type and nullability half with it.
   */
  it('and it composes rather than transcribes: each inherited node is the list\'s node', () => {
    const withoutProse = (node: OpenApiSchemaNode | undefined): string =>
      JSON.stringify({ ...node, description: undefined });

    const base = schemas['SourceResource']?.properties ?? {};
    const detail = schemas['SourceDetailResource']?.properties ?? {};

    expect(Object.keys(base).length, 'SourceResource must publish properties').toBeGreaterThan(0);
    for (const [key, node] of Object.entries(base)) {
      expect(withoutProse(detail[key]), key).toBe(withoutProse(node));
    }
  });

  it('the detail nests its two child components by $ref rather than inlining them', () => {
    // The same property the `meta` assertions make, on the two places a copy would go instead. An
    // inlined object here would be a second declaration of a published component, invisible to the
    // `MIRRORED` comparison — which reads property NAMES and would see `active_version` either way.
    expect(schemas['SourceDetailResource']?.properties?.['active_version']?.anyOf).toEqual([
      { $ref: '#/components/schemas/SourceActiveVersionResource' },
      { type: 'null' },
    ]);

    const warnings = schemas['SourceDetailResource']?.properties?.['warnings'];
    expect(warnings?.type).toBe('array');
    expect(warnings?.items?.$ref).toBe('#/components/schemas/SourceWarningResource');
    // ALWAYS PRESENT, EMPTY WHEN THERE ARE NONE — null would be a second spelling of "no warnings",
    // which is the argument `tags` makes one suite above.
    expect(wireNullable(warnings)).toBe(false);
  });

  it('the active version reuses SourceStatus rather than publishing a second lifecycle', () => {
    // The version's state and the source's are DIFFERENT FACTS drawn from the SAME vocabulary — a
    // source reading `parsing` above a version reading `ready` is the normal state of a reprocess.
    // The type says so by reusing `SourceStatus`; this says the document agrees, member for member,
    // so a sixteenth state cannot arrive on one shape and not the other.
    const version: Pick<SourceActiveVersionResource, 'status'> = { status: 'ready_with_warnings' };
    expect(version.status).toBe('ready_with_warnings');

    expect(new Set(schemas['SourceActiveVersionResource']?.properties?.['status']?.enum ?? [])).toEqual(
      new Set(schemas['SourceResource']?.properties?.['status']?.enum ?? []),
    );
  });

  it('SourceWarningResource publishes a code and a COUNT, and never the value behind the key', () => {
    const keys: Record<keyof SourceWarningResource, true> = { code: true, versions: true };
    expect(Object.keys(keys).sort()).toEqual(['code', 'versions']);

    // OPEN VOCABULARY, NO ENUM ON EITHER SIDE — the code set belongs to the ingestion service and
    // grows with the parsers, so a union in this package would refuse to render a code shipped this
    // morning. Same shape as `tags`, and the reason `warnings_truncated` exists at all.
    expect(schemas['SourceWarningResource']?.properties?.['code']?.enum).toBeUndefined();

    // THE PAYLOAD IS NOT PUBLISHED, and asserting the absence is worth a line because it is the field
    // somebody would add as a convenience: the warning's value is unschema'd and can carry document
    // content, which is tenant text arriving on a shape with no escape story.
    for (const overshare of ['value', 'detail', 'context', 'payload', 'message']) {
      expect(Object.keys(schemas['SourceWarningResource']?.properties ?? {}), overshare).not.toContain(
        overshare,
      );
    }
  });
});

/**
 * The bot↔source grant, pinned the same three ways — and it is the first shape in this package that
 * NESTS a published resource, so it adds one assertion the others have no use for: that `source` is a
 * `$ref` to `SourceResource` rather than a copy of it.
 *
 * That is the same failure the `meta` assertions catch, arriving through the other door. The
 * `MIRRORED` comparison in section 2 reads property NAMES, so an inlined sixteen-field object under
 * `source` and a `$ref` to the component look identical to it; reading the `$ref` is the only way to
 * tell a shared declaration from a second one. A second one is what 6B found in apps/web
 * (`MemberResource`, hand-written from the PHP by eye) and it is what this whole suite exists to stop.
 */
describe('the hand-written bot↔source assignment types', () => {
  it('BotSourceAssignmentResource declares exactly seven keys, and no ownership one', () => {
    const keys: Record<keyof BotSourceAssignmentResource, true> = {
      id: true,
      source_id: true,
      priority: true,
      enabled: true,
      created_at: true,
      updated_at: true,
      source: true,
    };
    expect(Object.keys(keys)).toHaveLength(7);

    // `bot_id` IS ABSENT AND THAT IS NOT AN OVERSIGHT: the bot is in the PATH, so a row that repeated
    // it would be publishing the same fact twice and inviting a client to post it back — the argument
    // `organization_id` makes on every other resource in this document, one scope down.
    for (const banned of ['organization_id', 'bot_id', 'organization']) {
      expect(Object.keys(keys), `${banned} must not be on this shape`).not.toContain(banned);
    }
  });

  it('nests the granted source by $ref, and it is SourceResource and not the detail', () => {
    const assignment = schemas['BotSourceAssignmentResource'];

    expect(assignment?.properties?.['source']?.$ref).toBe('#/components/schemas/SourceResource');
    // NOT `SourceDetailResource`. A list of grants that carried a content excerpt and a warning list
    // per row would be shipping tenant document text into a table with nowhere to escape it, and it
    // would make one page of assignments cost a detail projection per row server-side.
    expect(assignment?.properties?.['source']?.$ref).not.toContain('SourceDetailResource');

    // NEVER NULL, so no client branches on its absence: the composite foreign key
    // `bot_source_assignments_source_same_org` refuses a grant whose source is missing or belongs to
    // another organization, which is the tenancy guarantee this nesting rests on.
    expect(wireNullable(assignment?.properties?.['source'])).toBe(false);
    expect(assignment?.required ?? []).toContain('source');

    // The TYPE side of the same claim, by assignment rather than by a string: `source` typed as
    // anything but the imported `SourceResource` fails the typecheck here.
    const nested: Pick<BotSourceAssignmentResource, 'source'>['source'] extends SourceResource
      ? true
      : false = true;
    expect(nested).toBe(true);
  });

  it('publishes `enabled` and `priority` as what they are: a filter term and a tie-break', () => {
    const assignment = schemas['BotSourceAssignmentResource'];

    // Both REQUIRED and neither nullable. "The server did not say whether this grant is live" is not
    // a state a permission may express, and a null priority would put a hole in an ordering.
    expect(assignment?.properties?.['enabled']).toMatchObject({ type: 'boolean' });
    expect(assignment?.properties?.['priority']).toMatchObject({ type: 'integer' });
    for (const key of ['enabled', 'priority']) {
      expect(assignment?.required ?? [], key).toContain(key);
      expect(wireNullable(assignment?.properties?.[key]), key).toBe(false);
    }

    // `enabled` IS ONE TERM OF FOUR (kb-tenancy-isolation NN 2), so the shape must not publish
    // anything that reads as the whole answer — the same overclaim guard `SourceResource` carries for
    // `status_permits_retrieval`. `retrievable` here would be a claim this row cannot make: the other
    // three terms are the organization, the source's status and the item's active-version pointer,
    // and only the second of those is even on the nested resource.
    for (const overclaim of ['retrievable', 'is_live', 'answering', 'searchable']) {
      expect(Object.keys(assignment?.properties ?? {}), overclaim).not.toContain(overclaim);
    }
  });

  it('BotSourceAssignmentCollectionResource wraps the array under a named key, beside the SHARED meta', () => {
    const keys: Record<keyof BotSourceAssignmentCollectionResource, true> = {
      source_assignments: true,
      meta: true,
    };
    expect(Object.keys(keys).sort()).toEqual(['meta', 'source_assignments']);

    // THE THIRD PAGINATED LIST, AND THE THIRD PLACE A NEAR-IDENTICAL `PaginationMeta` WOULD HAVE GONE.
    // `meta` is a `$ref` to `ListMetaResource` — the same component the bot and source lists use — and
    // the type imports it rather than re-declaring it. A property-NAME comparison sees `meta` either
    // way, which is why this reads the `$ref`.
    expect(schemas['BotSourceAssignmentCollectionResource']?.properties?.['meta']?.$ref).toBe(
      '#/components/schemas/ListMetaResource',
    );
    // Present on an EMPTY page too: branching on its absence is branching on "did this list have
    // results", which is the question `total` answers.
    expect(schemas['BotSourceAssignmentCollectionResource']?.required ?? []).toContain('meta');

    // The rows are the published component rather than an inlined copy, for the reason the `source`
    // nesting above is: an array of an inlined object is where a second declaration hides.
    expect(
      schemas['BotSourceAssignmentCollectionResource']?.properties?.['source_assignments']?.items
        ?.$ref,
    ).toBe('#/components/schemas/BotSourceAssignmentResource');
  });

  /**
   * `IndexBotSourceAssignmentsRequest` is NO_CLIENT_FORM in test/form-drift.test.ts — a query-string
   * manifest, the third of them, exempt on the merits for the reason the other two are. That
   * exemption leaves its SORTABLE SET compared to nothing, and this is where that is repaired: the
   * three members are read out of the dumped manifest and each is required to be a property this
   * component actually publishes.
   *
   * It is the same move the source-status suite makes — read the manifest, do not restate it — and it
   * is worth making here because the set is the one thing about a list endpoint a client genuinely
   * has to hard-code (each table declares its own, per the measurement recorded in that exemption).
   * A server that renames `priority` and updates the sort whitelist would otherwise leave every table
   * sorting by a column nothing publishes, with a 422 as the only symptom.
   */
  it('every sortable column the request accepts is a property this resource publishes', () => {
    const manifest = JSON.parse(
      readFileSync(join(here, '..', 'rules', 'IndexBotSourceAssignmentsRequest.json'), 'utf8'),
    ) as { rules: Readonly<Record<string, readonly string[]>> };

    const members = (manifest.rules['sort'] ?? [])
      .filter((rule) => rule.startsWith('in:'))
      .flatMap((rule) => rule.slice('in:'.length).split(','))
      .map((member) => member.replace(/^"|"$/g, ''));

    // Positive control: an empty set would make the loop below vacuous, which is how a renamed rule
    // key would pass in silence.
    expect(members).toEqual(['id', 'priority', 'enabled']);

    const published = Object.keys(schemas['BotSourceAssignmentResource']?.properties ?? {});
    for (const member of members) expect(published, `sortable by ${member}`).toContain(member);
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
  /**
   * `SourceResource`'s property set, LIFTED OUT OF THE REGISTER because two entries need it.
   *
   * `SourceDetailResource` publishes every one of these plus twelve of its own — the server composes
   * the detail resource from the list resource rather than transcribing it, and the TypeScript side
   * says so with `extends`. Writing the sixteen names a second time inside this object would be the
   * third copy, and it is the copy that goes stale: a field added to `SourceResource` would be added
   * to its own entry (the comparison fails by name) and silently omitted from the detail's, where
   * nothing would fail — the property-name comparison would pass against a list that had simply
   * agreed to stop looking. Spreading it makes both entries move together, which is the same property
   * `extends` gives the types.
   */
  const SOURCE_RESOURCE_KEYS = [
    'id',
    'type',
    'name',
    'description',
    'origin_url',
    'status',
    'status_permits_retrieval',
    'status_is_processing',
    'tags',
    'effective_at',
    'expires_at',
    'created_by',
    'created_at',
    'updated_at',
    'deleted_at',
    'purged_at',
  ] as const satisfies readonly (keyof SourceResource)[];

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
      'instructions_visible',
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

    // ── the knowledge-source surface, mirrored by src/resources/sources.ts ─────────────────────
    // MIRRORED RATHER THAN EXEMPTED WITH A CLAIMANT, and the decision is the same one the two child
    // collections above record, made against a screen that is one batch away rather than in
    // parallel: `/sources` is a placeholder page today and the detail screen does not exist.
    //
    // What makes it MIRRORED anyway is that a reader already exists and has ALREADY PAID the price
    // this list exists to prevent. `apps/web/src/features/sources/api.ts` resolves `void` from
    // `uploadSourceFile` with a docblock saying, in as many words, that declaring a `SourceResource`
    // locally "would start the exact chain this codebase has already paid for once — fixture ->
    // hand-written type -> PHP resource, with no assertion at any step" (the `MemberResource`
    // episode, 6B). It declined and named this package as the owner. Leaving these two exempt would
    // make that decline cost the next agent a type they need, which is the pressure that produces
    // the hand-written copy.
    //
    // THE REQUEST SIDE WENT THE OTHER WAY IN THE SAME CHANGE, and the asymmetry is deliberate rather
    // than an oversight: all five source FormRequests are NO_CLIENT_FORM in form-drift.test.ts,
    // three of them recorded as OWED. A RESPONSE type has one correct shape the moment the server
    // publishes it and is wrong the moment a client re-declares it; a REQUEST schema is only correct
    // beside the form that renders it (`rhf-zod-forms` NN3). So the response is mirrored now and the
    // schemas ship with their screens.
    //
    // `created_by` is the field worth naming twice. It is on the RESOURCE and is a member of
    // `OWNERSHIP_KEYS`, so it is readable here and unrepresentable in every form schema in this
    // package — which is the same readable-never-writable asymmetry `retrieval_configuration_version`
    // holds on `BotResource`, arrived at from the opposite direction.
    // ── the upload ceilings, mirrored by `OrgUploadLimits` in the SAME module ──────────────────
    // MIRRORED, AND FOR ONCE THE USUAL QUESTION — "is there a reader today?" — is not the one that
    // decides it. The reader has existed for batches: `uploadSchema` in src/forms/upload.ts is a
    // FACTORY over this exact shape (§8.10 makes the cap and the media types per-organization, so
    // there is no byte constant in this package to build a fixed schema from), and apps/web's
    // dropzone reads `max_bytes`, `allowed_mime` and `max_batch` off it today. What was missing was
    // the other half: nothing published the shape, so the client's copy was an INVENTION nobody could
    // compare to anything. A `NO_CLIENT_TYPE` entry would therefore have been false the moment it was
    // written — not "no client yet" but "the client got there first".
    //
    // THE NAMING COLLISION IS THE PART WORTH RECORDING. The dumper keys components by short class
    // name, so the published component is `OrgUploadLimitsResource` while the type is
    // `OrgUploadLimits` — the same asymmetry `InvitationPreviewResource` ↔ `InvitationPreview`
    // carries, and this register is keyed by WIRE name precisely so the mapping is an assertion
    // rather than a convention. The two shapes were compared field by field before this entry landed
    // and are IDENTICAL: three properties, all three required on both sides, none nullable,
    // `additionalProperties: false` against an interface with no index signature, and the two
    // `integer`s are `number` because TypeScript has no narrower spelling. Had they differed by one
    // optionality, the finding would have been that `uploadSchema` was validating against a shape the
    // server does not send — which is worse than a missing mirror, and is why the comparison happened
    // before the entry rather than after it.
    //
    // The type MOVED for this entry, from src/forms/upload.ts to src/resources/sources.ts, and the
    // move is what makes "one shape, one definition" true rather than asserted. It sat behind the Zod
    // subpath while it was a factory PARAMETER the client had invented; it is a published response
    // now, so it belongs where the other mirrors are and where these three pins can reach it.
    // src/forms/upload.ts re-exports it — erased, so no bundle moves — and every existing
    // `import type { OrgUploadLimits } from '@kb/contracts/forms'` is unaffected.
    //
    // THREE PROPERTIES, AND THE SERVER'S ADMISSION RULE HAS FOUR TERMS. There is no
    // `allowed_extensions` here, so a picker built from `allowed_mime` alone over-accepts wherever
    // one sniffed type covers several extensions. That is recorded where a reader building the picker
    // will hit it (the `OrgUploadLimits` docblock and `uploadSchema`'s), NOT asserted here as a banned
    // field: the extension list is one the server could legitimately publish tomorrow, and a
    // `not.toContain` would fail that day with a message saying the opposite of what was wrong.
    OrgUploadLimitsResource: ['max_bytes', 'allowed_mime', 'max_batch'],

    SourceResource: SOURCE_RESOURCE_KEYS,
    SourceCollectionResource: ['sources', 'meta'],

    // ── the source DETAIL projection, mirrored by `SourceDetailResource` in the same module ────
    // MIRRORED, and here the usual question — "is there a reader today?" — answers no in a way that
    // is about to change rather than in the way that argues for an exemption: `GET .../sources/{source}`
    // is served, this component is what it returns, and the detail screen is the next task. A
    // NO_CLIENT_TYPE entry would be false the week it was written, which is the test the
    // `OrgUploadLimitsResource` note states and the one this fails.
    //
    // THE ENTRY IS A SPREAD, NOT A LIST, and that is the register's half of the composition pin. The
    // type's half is `extends SourceResource`; the wire's half is `the wire composes the same way`
    // above, which reads both components out of the document and requires every base property to be
    // published AND required here. Between the three, a field added to `SourceResource` tomorrow
    // cannot go missing from the detail on any of the three sides without a red suite naming it.
    //
    // `active_version` IS THE FIELD WORTH NAMING TWICE, because registering it makes a contradiction
    // visible from this side: `SourceResource`'s docblock says there is no active-version pointer on
    // that shape "and there never will be one", and this shape carries one. Nothing was added to
    // `SourceResource` — the type-level pin proves that — and the server's reconciliation is narrow:
    // populated only when the source has exactly one item, null for every crawl and multi-file
    // upload, with `active_version_count` answering the general question. Whether the absolute
    // sentence should be softened is the control plane's call; it is recorded in src/resources/sources.ts
    // rather than ruled on here.
    SourceDetailResource: [
      ...SOURCE_RESOURCE_KEYS,
      'item_count',
      'active_version_count',
      'active_version',
      'page_count',
      'slide_count',
      'sheet_count',
      'element_count',
      'chunk_count',
      'warnings',
      'warnings_truncated',
      'content_preview',
      'content_preview_truncated',
    ],
    SourceActiveVersionResource: [
      'id',
      'source_item_id',
      'version_number',
      'status',
      'activated_at',
      'parser_cfg_version',
      'ocr_cfg_version',
      'chunker_cfg_version',
      'embedding_model_version',
    ],
    SourceWarningResource: ['code', 'versions'],

    // ── the bot↔source grant, mirrored by src/resources/bot-source-assignments.ts ──────────────
    // MIRRORED for the reason the two bot child collections are, and the pressure here is higher than
    // it was for either: this resource NESTS a published component. An unmirrored response type whose
    // body contains a sixteen-field source object is not a type somebody declines to write — it is a
    // type somebody writes locally, and the copy they write is of `SourceResource`, which is the
    // exact shape 6B already found hand-written in apps/web once (`MemberResource`). The assignment
    // UI is the next task's; leaving these exempt would make the decline cost that agent two types
    // rather than one.
    //
    // THE REQUEST SIDE WENT THE OTHER WAY IN THE SAME CHANGE, as it did for sources: both assignment
    // FormRequests are NO_CLIENT_FORM in test/form-drift.test.ts, one exempt on the merits and one
    // OWED. `rhf-zod-forms` NN3 is the asymmetry — a response type is correct the moment the server
    // publishes it and wrong the moment a client re-declares it, while a request schema is only
    // correct beside the form that renders it.
    BotSourceAssignmentResource: [
      'id',
      'source_id',
      'priority',
      'enabled',
      'created_at',
      'updated_at',
      'source',
    ],
    BotSourceAssignmentCollectionResource: ['source_assignments', 'meta'],
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

      /**
       * NINE OF SIXTEEN, and unlike `BotResource`'s fifteen these are not mostly "not configured".
       * Four of them are the two-phase removal and the retrieval window, and each carries a distinct
       * claim that a non-nullable type would collapse:
       *
       *   `deleted_at` and `purged_at` are "we removed it" and "we PROVED we removed it"
       *   (kb-deletion-and-verification). Non-null deleted with a null purge is a purge in flight,
       *   which is a state the list renders; two non-nulls is the pair a retention obligation is
       *   measured against. One nullable timestamp cannot say both things.
       *
       *   `effective_at`/`expires_at` are "from always" and "until further notice". A default date
       *   would be a window somebody chose, rendered identically to one nobody did.
       *
       *   `origin_url` is null EXACTLY WHEN `type` is not `url`, enforced in both directions by a
       *   check constraint — so it is a discriminated absence rather than an unset field, and the
       *   nullability is what makes the union expressible at all.
       *
       * `tags` IS DELIBERATELY NOT HERE and the omission is the assertion: an empty array is the
       * untagged state, so a null would be a second spelling of it.
       */
      SourceResource: {
        description: true,
        origin_url: true,
        effective_at: true,
        expires_at: true,
        created_by: true,
        created_at: true,
        updated_at: true,
        deleted_at: true,
        purged_at: true,
      } satisfies Record<NullableKeys<SourceResource>, true>,

      /**
       * ELEVEN, AND NINE OF THEM ARE `SourceResource`'S — inherited through `extends`, so this map is
       * not free to disagree with the one above even if somebody wanted it to: `NullableKeys` reads
       * the resolved interface, and a base field that gained or lost a `| null` moves both maps in
       * the same commit or fails the typecheck in this file.
       *
       * The two that are this shape's own carry distinct claims:
       *
       *   `active_version` is null for a source with more than one item — a four-hundred-page crawl
       *   with every page serving reports null here — so the null is "there is no scalar answer",
       *   NOT "nothing is live". `active_version_count` is the field that answers the second, and it
       *   is a number precisely so it cannot be confused with this one.
       *
       *   `content_preview` is null when nothing has been extracted yet, never `''`. An empty string
       *   is a document whose first element is blank, which is a different fact and a different
       *   rendering; collapsing them is how a panel shows "no content" for a page of whitespace.
       *
       * `warnings` IS DELIBERATELY NOT HERE and the omission is the assertion, exactly as `tags`'
       * is on the base shape: the empty array is the no-warnings state, so a null would be a second
       * spelling of it. So are the eight counts — a nullable integer here would be a count no pager,
       * badge or delete confirmation could do arithmetic with.
       */
      SourceDetailResource: {
        description: true,
        origin_url: true,
        effective_at: true,
        expires_at: true,
        created_by: true,
        created_at: true,
        updated_at: true,
        deleted_at: true,
        purged_at: true,
        active_version: true,
        content_preview: true,
      } satisfies Record<NullableKeys<SourceDetailResource>, true>,

      // ONE, and it is nullable only because the column is. A version an item POINTS AT always has
      // it: the pointer switch and this timestamp are written in one transaction, so a null here
      // would be a row claiming to serve without being able to say since when. The four
      // `*_cfg_version` strings are NOT nullable and that is load-bearing — they are ingest-key
      // components, and an absent one would make a reprocess's dedupe decision unanswerable.
      SourceActiveVersionResource: {
        activated_at: true,
      } satisfies Record<NullableKeys<SourceActiveVersionResource>, true>,

      // `SourceWarningResource` IS ABSENT ON PURPOSE, which the loop below reads as "no nullable
      // property": a null `code` is a warning nobody can look up and a null `versions` is a count,
      // and the whole shape is two required scalars.

      // The same nullable pair every other row in this document carries, and nothing else. `enabled`,
      // `priority` and `source` are all non-nullable, and each for its own reason: a permission with
      // no answer is not a state, a null position puts a hole in an ordering, and the composite
      // foreign key makes a grant without a source unrepresentable in the database.
      BotSourceAssignmentResource: {
        created_at: true,
        updated_at: true,
      } satisfies Record<NullableKeys<BotSourceAssignmentResource>, true>,
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
    // And the source surface, whose second string is chosen for the reason that module holds its two
    // vocabularies ONCE — as unions, with no tuple sibling behind `@kb/contracts/forms`. That makes
    // the pressure to declare `SOURCE_STATUSES` here rather than there strictly higher than it was
    // for `BOT_STATUSES`, since there is no other file it obviously belongs in; `ready_with_warnings`
    // in the ROOT entry is what a tuple written in `src/resources/sources.ts` would look like, and it
    // is a widget app-shell regression that compiles, typechecks and passes review.
    expect(emitted).not.toContain('resources/sources');
    expect(emitted).not.toContain('ready_with_warnings');
    // The detail projection's second string is chosen the same way, against the value that module is
    // most likely to grow: a preview character cap or an element cap declared here would be a client
    // restating a bound the server enforces — and it would arrive as the first `export const` in
    // `src/resources/sources.ts`, which the tags suite above also refuses by reading the module text.
    expect(emitted).not.toContain('content_preview');
    // And the grant surface. It declares no closed vocabulary at all — `priority` is an integer and
    // `enabled` a boolean — so there is no enum member to use as the second string; the collection's
    // response key is the next best thing, because a runtime value in that module would most
    // plausibly be an envelope-key constant or a default sort, and either would emit this literal.
    expect(emitted).not.toContain('resources/bot-source-assignments');
    expect(emitted).not.toContain('source_assignments');
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
