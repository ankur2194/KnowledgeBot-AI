import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

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
  readonly enum?: readonly string[];
}

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

    // The provider-connection surface has no client in this workspace yet, and the reason it has no
    // schema is the reason it has no type: its request body carries `credential`, the PLAINTEXT
    // provider key, and a shared importable declaration naming that field is one defaults-factory away
    // from seeding a masked value back as the new key. See NO_CLIENT_FORM in form-drift.test.ts.
    ProviderConnectionResource: 'no client yet; the credential-field decision is recorded in form-drift',
    EmbeddingReadinessResource: 'read by no client yet — the designation screen is not built',
    EmbeddingCandidate: 'nested inside EmbeddingReadinessResource; same reason',
    EmbeddingRejection: 'nested inside EmbeddingReadinessResource; same reason',
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
