import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

/**
 * ═══ THE MINT ENVELOPE, PINNED WHERE THE AUTHORITY IS ══════════════════════════════════════
 *
 * `docs/22` § T51. Two clients hold a chat-session bearer and neither can see the other:
 * `apps/widget/src/loader/bridge.ts` (`readSessionGrant`) for the embedded widget, and
 * `apps/web/src/features/bots/playground-session.ts` (`mintPlaygroundSession`, through
 * `browserFetchData`) for the admin playground. Both unwrap a `data` key. Both were WRONG about it
 * at some point on 2026-08-27 — the widget shipped reading the body unwrapped and dropped every
 * grant it was handed, silently, one layer before the bearer was built (§ T48).
 *
 * WHAT WAS MISSING WAS NOT A TEST ON EITHER CLIENT. Both have one now, and two clients agreeing
 * with their own documentation and disagreeing with the server is exactly the failure
 * `tests/contract/test_object_key_cross_language.py` exists for on the storage seam: each side
 * passes its own suite and the bytes do not match. THIS file is the server's half — it reads the
 * PHP-generated document rather than anything a client believes — and
 * `services/core-api/tests/Contract/OpenApiDocumentTest.php`'s "keeps the committed document
 * current" is what makes reading the committed artifact the same as reading the code.
 *
 * WHY THE BOUNDS ARE ASSERTED AND NOT ONLY THE TYPES. `readSessionGrant` refuses `token: ''` and
 * `expires_in <= 0`, and until 2026-08-31 the published schema permitted both — so a server could
 * satisfy the contract and be refused by the client, which is a disagreement no amount of type
 * checking finds. The bounds were added to `ChatSessionResource::openApiSchemas()` so the client's
 * strictness is the contract rather than a client being defensive on its own.
 */
const here = dirname(fileURLToPath(import.meta.url));
const openapiPath = join(here, '..', 'openapi', 'core-api.openapi.json');

type Json = Record<string, unknown>;

/**
 * Narrow an index lookup, and turn a missing key into a sentence.
 *
 * `noUncheckedIndexedAccess` is on, so every lookup into the document is `T | undefined` and a
 * cast would silence it. A cast is the wrong tool twice over here: the whole subject of this file
 * is the document not containing what a reader assumed, so "absent" is a RESULT, and it deserves
 * to say which key rather than surfacing three lines later as `undefined is not an object`.
 */
function must<T>(value: T | undefined, what: string): T {
  if (value === undefined) {
    throw new Error(`the published document has no ${what}`);
  }

  return value;
}

const document = JSON.parse(readFileSync(openapiPath, 'utf8')) as {
  paths: Record<string, Record<string, Json>>;
  components: { schemas: Record<string, Json> };
};

/**
 * Every operation that hands a client a chat-session bearer.
 *
 * BOTH, AND THE SECOND IS THE ONE THAT WOULD HAVE BEEN FORGOTTEN. The playground mint was added
 * three phases after the widget's and returns the SAME component, so a change made for one client
 * silently reaches the other — which is an argument for pinning them together rather than beside
 * their own features.
 */
const MINTS: ReadonlyArray<{ path: string; operationId: string; status: string }> = [
  { path: '/sdk/v1/session', operationId: 'sdk.session.store', status: '201' },
  {
    path: '/api/v1/organizations/{organization}/bots/{bot}/playground-session',
    operationId: 'admin.bots.playground-session.store',
    status: '201',
  },
];

describe('the chat-session mint envelope', () => {
  it.each(MINTS)('wraps $operationId in a required `data` key', ({ path, operationId, status }) => {
    const operation = must(
      document.paths[path]?.post,
      `POST ${path} — a renamed or removed mint route`,
    );

    expect(operation.operationId).toBe(operationId);

    const responses = must(operation.responses as Record<string, Json> | undefined, `${operationId} responses`);
    const response = must(responses[status], `${operationId} ${status}`);
    const content = must((response.content as Record<string, Json> | undefined)?.['application/json'], `${operationId} ${status} JSON content`);
    const schema = must(content.schema as Json | undefined, `${operationId} ${status} schema`);

    // THE ENVELOPE ITSELF. `required: ['data']` is the half that matters: a `data` key that is
    // merely OPTIONAL would let a server answer the bare object, which is the shape both clients
    // were once written for and the shape neither now accepts.
    expect(schema.type).toBe('object');
    expect(schema.required).toEqual(['data']);
    expect(must((schema.properties as Json | undefined)?.data, `${operationId} data property`)).toEqual({
      $ref: '#/components/schemas/ChatSessionResource',
    });

    // `additionalProperties: false` is not cosmetic here — it is what stops a sibling key being
    // added beside `data` and read by one client as the payload.
    expect(schema.additionalProperties).toBe(false);
  });

  it('publishes exactly the two fields the clients read, with the bounds they enforce', () => {
    const resource = must(document.components.schemas.ChatSessionResource, 'ChatSessionResource');
    const properties = must(resource.properties as Record<string, Json> | undefined, 'ChatSessionResource.properties');
    const token = must(properties.token, 'ChatSessionResource.token');
    const expiresIn = must(properties.expires_in, 'ChatSessionResource.expires_in');

    expect(resource.required).toEqual(['token', 'expires_in']);
    expect(Object.keys(properties).sort()).toEqual(['expires_in', 'token']);

    // `typeof grant.token !== 'string' || grant.token === ''` in the loader.
    expect(token.type).toBe('string');
    expect(token.minLength).toBe(1);

    // `typeof grant.expires_in !== 'number' || !Number.isFinite(...) || grant.expires_in <= 0`.
    // `integer` rather than `number` is stricter than the client and that direction is fine: a
    // client accepting more than the server promises cannot be surprised by the server.
    expect(expiresIn.type).toBe('integer');
    expect(expiresIn.minimum).toBe(1);
  });

  it('never lets the token appear anywhere a client would log or route it', () => {
    /*
     * THE NON-NEGOTIABLE THIS COMPONENT IS ONE STEP FROM VIOLATING. Non-negotiable 9 is about
     * PROVIDER credentials; this is a session credential and the same rule of thumb applies for
     * the same reason — a bearer in a URL reaches the browser's history, the Referer header of
     * every subsequent request, and the access log of anything in front of the app.
     *
     * So: this component may be the response BODY and may never be a parameter. Asserted over the
     * whole document rather than over the two mint operations, because the failure this catches is
     * a THIRD operation added later that takes a session token in its path or query.
     */
    for (const [path, methods] of Object.entries(document.paths)) {
      for (const [method, operation] of Object.entries(methods)) {
        const parameters = (operation as Json).parameters as Json[] | undefined;

        for (const parameter of parameters ?? []) {
          const schema = JSON.stringify(parameter.schema ?? {});

          expect(
            schema.includes('ChatSessionResource'),
            `${method.toUpperCase()} ${path} takes a chat-session token as a parameter`,
          ).toBe(false);
        }
      }
    }
  });
});
