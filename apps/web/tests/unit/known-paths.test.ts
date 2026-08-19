import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import * as knownPathsModule from '@/features/auth/known-paths';
import {
  FORGOT_PASSWORD_KNOWN_PATHS,
  INVITE_KNOWN_PATHS,
  LOGIN_KNOWN_PATHS,
  PROVIDER_CONNECTION_EDIT_KNOWN_PATHS,
  PROVIDER_CONNECTION_KNOWN_PATHS,
  REGISTER_KNOWN_PATHS,
  RESET_PASSWORD_KNOWN_PATHS,
  ROTATE_CREDENTIAL_KNOWN_PATHS,
} from '@/features/auth/known-paths';
// MOVED OUT OF THE MODULE ABOVE, at that module's own instruction, when `features/models` became the
// fourth consumer of the derivation. The per-form constants stayed; the shared helper did not, and
// no re-export shim was left behind — so this import naming a different path is the assertion that
// the move actually happened.
import {
  HIDDEN_PATHS,
  knownPathsFromRules,
  type FormRulesManifest,
} from '@/lib/forms/known-paths';

/**
 * `knownPaths` is "the paths this form RENDERS", which is a different set from "the paths the
 * FormRequest validates" — and `token` is the field that proves it. A key routed to a hidden input has
 * no focusable ref, so the message is written to a field that displays nowhere: the user submits, the
 * server rejects, the screen does not change, and they submit again.
 */

const RULES_DIR = new URL('../../../../packages/contracts/rules/', import.meta.url).pathname;

describe('the subtraction: token is validated by the server and rendered by nobody', () => {
  it('drops token from a manifest that declares it', () => {
    const manifest: FormRulesManifest = {
      class: 'App\\Http\\Requests\\ResetPasswordRequest',
      rules: {
        token: ['bail', 'required', 'string', 'max:255'],
        email: ['bail', 'required', 'string', 'email:rfc,strict', 'max:254'],
        password: ['bail', 'required', 'string', 'min:12'],
        password_confirmation: ['bail', 'required', 'string'],
      },
    };

    expect(knownPathsFromRules(manifest)).toEqual(['email', 'password', 'password_confirmation']);
  });

  it('keeps every other path, because an unknown key becomes a banner nobody can act on', () => {
    const manifest: FormRulesManifest = {
      class: 'App\\Http\\Requests\\LoginRequest',
      rules: { email: ['required'], password: ['required'] },
    };

    expect(knownPathsFromRules(manifest)).toEqual(['email', 'password']);
  });

  it('hides exactly one path, and the set is stated rather than inferred', () => {
    expect([...HIDDEN_PATHS]).toEqual(['token']);
  });
});

describe('the login form knows the two fields it renders', () => {
  it('is email and password, derived from the manifest rather than typed out', () => {
    expect([...LOGIN_KNOWN_PATHS].sort()).toEqual(['email', 'password']);
  });

  it('has no `remember` path (decision D4) and no ownership column', () => {
    expect(LOGIN_KNOWN_PATHS).not.toContain('remember');
    expect(LOGIN_KNOWN_PATHS).not.toContain('organization_id');
  });
});

/**
 * WHAT REPLACED THE SELF-EXPIRING PIN THAT USED TO BE HERE.
 *
 * The pin failed the day `rules/LoginRequest.json` appeared and its failure message carried the
 * three-line repair; `known-paths.ts` now imports both manifests and derives from
 * `knownPathsFromRules`. A pin whose condition has been met is deleted, not skipped — but deleting it
 * without putting an assertion in its place would leave the rewire unprotected, and the failure mode
 * it protects against is silent: somebody types the list out (or leaves a stale one) and the form's
 * 422 partition drifts from the server's vocabulary with nothing red.
 *
 * So the manifests are read INDEPENDENTLY off disk here, with `fs` rather than the bundler's JSON
 * import, and the module's exported arrays are compared against them. Re-deriving the expectation with
 * `knownPathsFromRules` would assert only that a pure function is pure — the value of these three
 * specs is that the expectation is computed a DIFFERENT WAY from the code under test.
 */
describe('the exported sets come from the committed manifests', () => {
  const manifestOf = (file: string): FormRulesManifest =>
    JSON.parse(readFileSync(join(RULES_DIR, file), 'utf8')) as FormRulesManifest;

  it('LOGIN_KNOWN_PATHS is exactly LoginRequest.json minus the hidden paths', () => {
    const rules = manifestOf('LoginRequest.json');

    expect(rules.class).toBe('App\\Http\\Requests\\LoginRequest');
    expect([...LOGIN_KNOWN_PATHS].sort()).toEqual(
      Object.keys(rules.rules)
        .filter((path) => !HIDDEN_PATHS.has(path))
        .sort(),
    );
  });

  it('REGISTER_KNOWN_PATHS is RegisterRequest.json minus `token`, which that manifest DOES declare', () => {
    const rules = manifestOf('RegisterRequest.json');

    // THE POSITIVE CONTROL, and it is the line that makes the next assertion mean anything: a
    // subtraction asserted against a manifest that never declared the key would pass with
    // HIDDEN_PATHS emptied.
    expect(Object.keys(rules.rules)).toContain('token');

    expect([...REGISTER_KNOWN_PATHS].sort()).toEqual(['name', 'password', 'password_confirmation']);
    expect(REGISTER_KNOWN_PATHS).not.toContain('token');
    // `email` is absent from the manifest itself — the invitation pins the address, so the server
    // validates no `email` and the one deliberate disclosure ("an account already exists") lands in
    // the banner rather than on a field this form does not render.
    expect(Object.keys(rules.rules)).not.toContain('email');
  });

  it('INVITE_KNOWN_PATHS is StoreInvitationRequest.json, with NOTHING subtracted', () => {
    const rules = manifestOf('StoreInvitationRequest.json');

    expect(rules.class).toBe('App\\Http\\Requests\\StoreInvitationRequest');
    // The one manifest in this set whose every key is a rendered control, which is why it is the one
    // that would look identical whether it was derived or typed out. The assertion that distinguishes
    // them is the equality with the manifest, not the literal — a server-side field added tomorrow
    // fails here, where the hand-typed `['email', 'role']` it replaced would have stayed green.
    expect([...INVITE_KNOWN_PATHS].sort()).toEqual(
      Object.keys(rules.rules)
        .filter((path) => !HIDDEN_PATHS.has(path))
        .sort(),
    );
    expect([...INVITE_KNOWN_PATHS].sort()).toEqual(['email', 'role']);
    // `role` SURVIVES the subtraction even though a Radix `SelectTrigger` has no `register` ref:
    // `applyServerErrors` declines to spend `shouldFocus` on it and `FormMessage` still renders the
    // message. Unfocusable is not the same as unrendered, and only the latter belongs in HIDDEN_PATHS.
    expect(INVITE_KNOWN_PATHS).toContain('role');
    // `organization` is NOT a path here, deliberately: a suspended organization answers 422 keyed to it
    // (the status that used to be a classless 409), and that sentence belongs on the banner.
    expect(INVITE_KNOWN_PATHS).not.toContain('organization');
    expect(INVITE_KNOWN_PATHS).not.toContain('organization_id');
  });

  it('FORGOT_PASSWORD_KNOWN_PATHS is ForgotPasswordRequest.json, nothing subtracted', () => {
    const rules = manifestOf('ForgotPasswordRequest.json');

    expect(rules.class).toBe('App\\Http\\Requests\\ForgotPasswordRequest');
    expect([...FORGOT_PASSWORD_KNOWN_PATHS].sort()).toEqual(
      Object.keys(rules.rules)
        .filter((path) => !HIDDEN_PATHS.has(path))
        .sort(),
    );
    expect([...FORGOT_PASSWORD_KNOWN_PATHS]).toEqual(['email']);
  });

  it('RESET_PASSWORD_KNOWN_PATHS is ResetPasswordRequest.json MINUS token', () => {
    const rules = manifestOf('ResetPasswordRequest.json');

    // POSITIVE CONTROL, same as the register case: the subtraction means nothing unless the manifest
    // really declares the key being subtracted.
    expect(Object.keys(rules.rules)).toContain('token');

    expect([...RESET_PASSWORD_KNOWN_PATHS].sort()).toEqual(
      Object.keys(rules.rules)
        .filter((path) => !HIDDEN_PATHS.has(path))
        .sort(),
    );
    expect(RESET_PASSWORD_KNOWN_PATHS).not.toContain('token');
    // `email` IS rendered here (read-only, paired with the token by the broker), unlike on register
    // where the invitation pins the address and the manifest declares no `email` at all.
    expect(RESET_PASSWORD_KNOWN_PATHS).toContain('email');
  });

  /**
   * ── THE PROVIDER SETS, AND THE ONE SUBTRACTION THIS FILE HAS THAT IS NOT `HIDDEN_PATHS` ────────
   *
   * `PROVIDER_CONNECTION_KNOWN_PATHS` drops the whole `models` sub-tree, and the reason is the same
   * one `token` is dropped: the create form renders no control for it. The catalogue is its own screen
   * (A4a), the form posts `models: []` because the rule is `present`, and a 422 keyed on
   * `models.0.model` therefore belongs on the banner rather than written to a field that displays
   * nowhere.
   *
   * A POSITIVE CONTROL FIRST in both directions, because a subtraction asserted against a manifest
   * that does not declare the key is a subtraction asserting nothing.
   */
  it('PROVIDER_CONNECTION_KNOWN_PATHS is StoreProviderConnectionRequest.json MINUS the models sub-tree', () => {
    const rules = manifestOf('StoreProviderConnectionRequest.json');

    expect(rules.class).toBe('App\\Http\\Requests\\StoreProviderConnectionRequest');
    // The controls this form actually renders.
    expect([...PROVIDER_CONNECTION_KNOWN_PATHS].sort()).toEqual([
      'credential',
      'label',
      'provider',
    ]);

    // POSITIVE CONTROL: the manifest really does declare what is being subtracted, and more than one
    // level of it.
    expect(Object.keys(rules.rules)).toContain('models');
    expect(Object.keys(rules.rules).some((path) => path.startsWith('models.'))).toBe(true);
    expect(PROVIDER_CONNECTION_KNOWN_PATHS.some((path) => path.startsWith('models'))).toBe(false);

    // `credential` IS in the set: `min:8`/`max:512` are per-field 422s that belong under the input the
    // operator just pasted into. Membership says the form renders a CONTROL with that name; it says
    // nothing about the value, which is never read back out of form state.
    expect(PROVIDER_CONNECTION_KNOWN_PATHS).toContain('credential');
    // …and no ownership column, ever.
    expect(PROVIDER_CONNECTION_KNOWN_PATHS).not.toContain('organization_id');
  });

  it('PROVIDER_CONNECTION_EDIT_KNOWN_PATHS is UpdateProviderConnectionRequest.json, nothing subtracted', () => {
    const rules = manifestOf('UpdateProviderConnectionRequest.json');

    expect(rules.class).toBe('App\\Http\\Requests\\UpdateProviderConnectionRequest');
    expect([...PROVIDER_CONNECTION_EDIT_KNOWN_PATHS].sort()).toEqual(
      Object.keys(rules.rules)
        .filter((path) => !HIDDEN_PATHS.has(path))
        .sort(),
    );
    expect([...PROVIDER_CONNECTION_EDIT_KNOWN_PATHS].sort()).toEqual(['label', 'status']);
    // THE EDIT ENDPOINT MAY NEVER ACCEPT A CREDENTIAL, and the absence is asserted against the
    // SERVER's own vocabulary rather than against this app's intent: if the FormRequest ever grew the
    // field, this set would grow it too and this line is where that shows up.
    expect(PROVIDER_CONNECTION_EDIT_KNOWN_PATHS).not.toContain('credential');
  });

  it('ROTATE_CREDENTIAL_KNOWN_PATHS carries BOTH fields, including the re-authentication', () => {
    const rules = manifestOf('RotateProviderCredentialRequest.json');

    expect(rules.class).toBe('App\\Http\\Requests\\RotateProviderCredentialRequest');
    expect([...ROTATE_CREDENTIAL_KNOWN_PATHS].sort()).toEqual(
      Object.keys(rules.rules)
        .filter((path) => !HIDDEN_PATHS.has(path))
        .sort(),
    );
    // `current_password` is the §18.3 re-authentication and is RENDERED, so "that password is not
    // correct" lands under the password input. On the banner it would read as a general failure of the
    // rotation and the operator would retype the same password.
    expect(ROTATE_CREDENTIAL_KNOWN_PATHS).toContain('current_password');
    expect(ROTATE_CREDENTIAL_KNOWN_PATHS).toContain('credential');
  });

  it('every exported set is manifest-derived, so none can silently become a typed list', () => {
    // THE CLOSURE OVER THIS MODULE'S OWN EXPORTS. Each assertion above names one constant; this one
    // says there are no OTHERS — a sixth `*_KNOWN_PATHS` added without a spec fails here rather than
    // being the one nobody checked. It is the same argument as form-drift's manifest closure.
    // `Object.entries`, NOT `Object.keys` plus an index. Indexing a module namespace by a loop variable
    // is an object-injection sink that eslint-plugin-security reports as a warning nobody can act on —
    // and this file is a test, so the value is right there in the same tuple.
    const exported = Object.entries(knownPathsModule)
      .filter(([name]) => name.endsWith('_KNOWN_PATHS'))
      .sort(([a], [b]) => a.localeCompare(b));

    expect(exported.map(([name]) => name)).toEqual([
      'FORGOT_PASSWORD_KNOWN_PATHS',
      'INVITE_KNOWN_PATHS',
      'LOGIN_KNOWN_PATHS',
      'PROVIDER_CONNECTION_EDIT_KNOWN_PATHS',
      'PROVIDER_CONNECTION_KNOWN_PATHS',
      'REGISTER_KNOWN_PATHS',
      'RESET_PASSWORD_KNOWN_PATHS',
      'ROTATE_CREDENTIAL_KNOWN_PATHS',
    ]);

    // And every one of them is a non-empty array of strings — an accidental `undefined` export would
    // satisfy the name check above and then route every 422 key to the banner at runtime.
    for (const [name, value] of exported) {
      expect(Array.isArray(value), `${name} must be an array`).toBe(true);
      expect((value as readonly string[]).length, `${name} must not be empty`).toBeGreaterThan(0);
    }
  });

  it('reads the directory the app imports from, so no assertion can pass by pointing nowhere', () => {
    expect(existsSync(RULES_DIR)).toBe(true);
    const present = readdirSync(RULES_DIR);
    expect(present).toContain('LoginRequest.json');
    expect(present).toContain('RegisterRequest.json');
    expect(present).toContain('StoreInvitationRequest.json');
    expect(present).toContain('ForgotPasswordRequest.json');
    expect(present).toContain('ResetPasswordRequest.json');
    expect(present).toContain('StoreProviderConnectionRequest.json');
    expect(present).toContain('UpdateProviderConnectionRequest.json');
    expect(present).toContain('RotateProviderCredentialRequest.json');
  });
});

/**
 * WHAT THE SELF-EXPIRING PIN THAT USED TO BE HERE WAS WATCHING FOR — AND WHAT IT CAUGHT.
 *
 * The pin failed the moment `@kb/contracts/forms` exported `inviteMemberSchema`, and its failure
 * message carried the four-step rewire: move the class from NO_CLIENT_FORM to MIRRORS, derive
 * INVITE_KNOWN_PATHS from the manifest, attach the resolver and take ORG_ROLES from the package, delete
 * the pin. All four are done; the derivation is asserted above.
 *
 * What is left here is the half a pin could never assert: that the invite form's catalog and its
 * DEFAULT come from the shared package rather than from a second local copy. `apps/web` carried both as
 * declared stopgaps while the manifest did not exist, and a stopgap that outlives its reason is the
 * drift `packages/contracts` exists to prevent — silent in exactly one direction, the server ADDING a
 * role, which the local `readonly Role[]` annotation could not see.
 */
describe('the invite form takes its catalog from the shared package', () => {
  it('@kb/contracts/forms exports the schema, its defaults factory, and ORG_ROLES', async () => {
    const forms: Record<string, unknown> = await import('@kb/contracts/forms');

    for (const name of ['inviteMemberSchema', 'inviteMemberFormDefaults', 'ORG_ROLES']) {
      expect(Object.keys(forms), `${name} must be exported from the ./forms subpath`).toContain(name);
    }
    // THE POSITIVE CONTROL the pin needed and this suite still needs: a renamed subpath, a build that
    // did not run, or a broken export map would make `Object.keys(forms)` empty, and an empty list would
    // fail the loop above for the wrong reason. Assert a schema that has been there all along.
    expect(Object.keys(forms)).toContain('registerSchema');
  });

  it('ORG_ROLES is the four-member catalog, most- to least-privileged', async () => {
    const { ORG_ROLES } = await import('@kb/contracts/forms');

    // ORDER, not just membership: the select renders it verbatim and reads as a ladder, so a shuffled
    // tuple invites a mis-click on `owner` — the one grant the role performing it cannot undo.
    expect([...ORG_ROLES]).toEqual(['owner', 'admin', 'knowledge_manager', 'analyst']);
  });

  it('defaults to the least-privileged role, which is the only one never refused', async () => {
    const { inviteMemberFormDefaults } = await import('@kb/contracts/forms');

    // `owner` would 403 for every admin who is not one (`Gate::authorize('inviteOwner')`), making the
    // form's INITIAL state an error for half the people allowed to use it.
    expect(inviteMemberFormDefaults()).toEqual({ email: '', role: 'analyst' });
  });

  it('no second copy of the catalog survives in apps/web', () => {
    // The stopgap this replaced lived in src/features/auth/roles.ts. That file keeps the COPY —
    // `roleLabel`, `OWNER_INVITE_NOTE` — and must no longer declare a catalog or a default, or the two
    // spellings can disagree with nothing red.
    const roles = readFileSync(
      new URL('../../src/features/auth/roles.ts', import.meta.url).pathname,
      'utf8',
    );

    expect(roles).not.toMatch(/^export const ORG_ROLES/m);
    expect(roles).not.toMatch(/^export const DEFAULT_INVITE_ROLE/m);
    // And the label map is still there, so this is a check on WHAT MOVED rather than on the file being
    // gone — `roleLabel` is exhaustive over `Role` and is the reason a fifth role fails the typecheck.
    expect(roles).toMatch(/export function roleLabel/);
  });
});
