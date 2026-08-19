import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { KbError } from '@kb/contracts';
import { describe, expect, it } from 'vitest';

import {
  EMBEDDING_DESIGNATION_KNOWN_PATHS,
  NO_DESIGNATION,
  candidateKey,
  describeCandidate,
  describeDesignation,
  designationState,
  embeddingConfigurationPath,
  rejectionReasonSentence,
  resolverRefusalMessage,
} from '@/features/embedding/api';
import { deleteConflictMessage } from '@/features/providers/api';

/**
 * The REACT-FREE half of the embedding designation screen: the request path, the 422 field
 * vocabulary, the composite control key, and the two mappings that decide what an operator reads.
 *
 * It runs in the `unit` project, which is `node` and installs no react plugin — so every module it
 * reaches transitively must be JSX-free. `features/embedding/api.ts` is, deliberately, and so is
 * `features/providers/api.ts`, which it imports for `organizationPath` and `deleteConflictMessage`.
 *
 * WHAT THIS SPEC MAY NOT CLAIM: anything about isolation. It exercises pure functions. That two
 * organizations never see each other's verdict is Playwright's, and is recorded as unproven here.
 */

const ORG = '01JORGAAAAAAAAAAAAAAAAAAAA';
const CONNECTION = '01JCONNAAAAAAAAAAAAAAAAAAA';

describe('the request path carries the organization', () => {
  it('mounts the configuration under the organization, as one endpoint for both verbs', () => {
    // The segment is a ROUTING HINT resolved by route binding; the SCOPE is the session, and
    // `TenantContext` re-reads the membership row per request. GET and PUT are the SAME path — the
    // designation is replaced wholesale and cleared through the same URL, never through a DELETE.
    expect(embeddingConfigurationPath(ORG)).toBe(
      `/api/v1/organizations/${ORG}/embedding-configuration`,
    );
  });

  it('encodes the interpolated segment, because it comes off a server response', () => {
    // A no-op on a ULID today. The habit is what keeps the day it stops being a ULID from being an
    // injected path segment.
    expect(embeddingConfigurationPath('a/b')).toContain('a%2Fb');
  });
});

describe('the 422 vocabulary is the server’s own, never typed out', () => {
  it('derives both paths from the committed manifest and subtracts nothing', () => {
    // The manifest is dumped from `DesignateEmbeddingConnectionRequest::rules()` by
    // `php artisan kb:dump-form-rules`. Read independently off disk here, so a hand-typed list in
    // the feature would fail rather than a comment asking somebody to remember.
    const manifest = JSON.parse(
      readFileSync(
        join(
          import.meta.dirname,
          '..',
          '..',
          '..',
          '..',
          'packages',
          'contracts',
          'rules',
          'DesignateEmbeddingConnectionRequest.json',
        ),
        'utf8',
      ),
    ) as { rules: Record<string, unknown> };

    expect([...EMBEDDING_DESIGNATION_KNOWN_PATHS].sort()).toEqual(
      Object.keys(manifest.rules).sort(),
    );
    // Both halves are rendered — `connection_id` under the radio group and `model` under its own
    // message slot — so neither is subtracted. A 422 on either lands on a control the operator can
    // see, rather than on a field that displays nowhere.
    expect(EMBEDDING_DESIGNATION_KNOWN_PATHS).toContain('connection_id');
    expect(EMBEDDING_DESIGNATION_KNOWN_PATHS).toContain('model');
  });
});

describe('the composite control key is the PAIR, never the connection', () => {
  it('distinguishes two embedding models on ONE connection', () => {
    // The case a `connection_id`-keyed control would make unreachable: one credential can carry two
    // embedding models, and those are two different vector spaces.
    const small = candidateKey({ connection_id: CONNECTION, model: 'text-embedding-3-small' });
    const large = candidateKey({ connection_id: CONNECTION, model: 'text-embedding-3-large' });

    expect(small).not.toBe(large);
  });

  it('cannot be made ambiguous by a model id containing the separator', () => {
    // Real vendor ids contain both: `meta/llama-4-70b`, `qwen:7b`. The key is never PARSED — the
    // caller compares keys — and `encodeURIComponent` means two different pairs cannot collide even
    // if somebody later writes a parser.
    expect(candidateKey({ connection_id: 'a', model: 'b:c' })).not.toBe(
      candidateKey({ connection_id: 'a:b', model: 'c' }),
    );
    expect(candidateKey({ connection_id: 'a', model: 'x/y' })).toContain('x%2Fy');
  });

  it('cannot collide with the clear-designation sentinel', () => {
    // Every real key contains a `:`; the sentinel does not. So no vendor model id can ever be
    // mistaken for "no designation", which is the one option that posts two nulls.
    expect(NO_DESIGNATION).not.toContain(':');
    expect(candidateKey({ connection_id: CONNECTION, model: NO_DESIGNATION })).toContain(':');
  });
});

describe('the pair is rendered as the data plane spells it', () => {
  it('joins provider and model verbatim, with no prettifying of the vendor name', () => {
    // `EmbeddingCandidate.provider` is a BARE STRING on the wire — relayed from the data plane and
    // validated by nobody on the way through — so `providerLabel`'s closed `switch` must not be
    // applied to it. This pair IS the vector space, and an operator compares it character by
    // character against a vendor dashboard.
    expect(
      describeCandidate({
        connection_id: CONNECTION,
        provider: 'openai',
        model: 'text-embedding-3-large',
      }),
    ).toBe('openai/text-embedding-3-large');
  });

  it('does not choke on a vendor this build has never heard of', () => {
    expect(
      describeCandidate({ connection_id: CONNECTION, provider: 'somevendor', model: 'embed-v9' }),
    ).toBe('somevendor/embed-v9');
  });
});

/**
 * WHAT WAS STORED vs WHAT RESOLVED — the four-way branch that replaced one rendering.
 *
 * A null `selected` was "nothing is resolved" and nothing more, and it covers two situations with
 * two different next actions: nothing was ever designated, or a designation IS stored and stopped
 * resolving. `EmbeddingReadinessResource.designated` is the field that makes it a branch; before it,
 * the stored pair reached the client only inside the free-text `explanation`.
 */
describe('the designation state is a branch over two fields, not a reading of the explanation', () => {
  const DESIGNATION = { connection_id: CONNECTION, model: 'text-embedding-3-large' } as const;
  const SELECTED = {
    connection_id: CONNECTION,
    provider: 'openai',
    model: 'text-embedding-3-large',
  } as const;

  it('separates the two states a null `selected` used to collapse', () => {
    // THE WHOLE POINT. Same `selected`, different `designated`, different next action: re-designate
    // a working pair, versus choose one for the first time.
    expect(designationState({ selected: null, designated: DESIGNATION })).toBe(
      'designated_but_unresolved',
    );
    expect(designationState({ selected: null, designated: null })).toBe('nothing_designated');
  });

  it('separates a pinned ready pair from one that merely happens to resolve', () => {
    // `resolved_by_rule` is the more fragile state: one more eligible connection naming a different
    // pair turns it into the ADR-031 ambiguity with no edit to this organization.
    expect(designationState({ selected: SELECTED, designated: DESIGNATION })).toBe(
      'designated_and_resolved',
    );
    expect(designationState({ selected: SELECTED, designated: null })).toBe('resolved_by_rule');
  });

  it('reads `ready`, `explanation` and `rejected` for nothing at all', () => {
    // It is not a verdict and must never become one: `ready` is the verdict, `explanation` is the
    // reason, both the server's. The parameter type is a two-field `Pick`, so this function CANNOT
    // reach the fields a second implementation of the resolution rule would need — the typecheck is
    // the real assertion and this one states the intent beside it.
    const state = designationState({ selected: null, designated: DESIGNATION });
    expect(state).toBe('designated_but_unresolved');
  });
});

describe('the STORED pair is rendered without a provider, because it has none', () => {
  it('returns the model alone', () => {
    // `EmbeddingDesignation` carries `connection_id` and `model` and nothing else — the vendor is a
    // property of the connection, resolved at read time. `describeCandidate` applied to it would
    // need a cast and would print `undefined/text-embedding-3-large` on the one screen whose entire
    // job is to name the pair exactly, so the two formatters are deliberately separate.
    expect(describeDesignation({ connection_id: CONNECTION, model: 'text-embedding-3-large' })).toBe(
      'text-embedding-3-large',
    );
  });

  it('is verbatim, including a model id that contains a separator', () => {
    // An alias or a shortened form names a different vector space that silently finds nothing, so
    // nothing about this string is normalized.
    expect(describeDesignation({ connection_id: CONNECTION, model: 'meta/embed:v9' })).toBe(
      'meta/embed:v9',
    );
  });
});

describe('the rejection reason maps through a switch, and says so when it cannot', () => {
  it('has a sentence for each of the three closed members', () => {
    // `EmbeddingIneligibility` in services/ai-service/app/providers/embedding_selection.py. Every
    // member is decidable BEFORE any request goes out, and there is deliberately no member meaning
    // "the provider errored" — so each of these describes a configuration, and each sentence names
    // whether it is fixable from this console.
    for (const reason of [
      'vendor_has_no_endpoint',
      'row_lacks_embedding_flag',
      'row_incoherent',
    ]) {
      const sentence = rejectionReasonSentence(reason);
      expect(sentence, reason).not.toBeNull();
      expect(sentence, reason).not.toBe('');
    }
  });

  it('says the vendor case is NOT fixable by editing the row, and the flag case is', () => {
    // The distinction is the whole value of the mapping: axis 1 needs a different vendor, axis 2 is
    // one checkbox on the connection's model catalogue.
    expect(rejectionReasonSentence('vendor_has_no_endpoint')).toMatch(/cannot change that/i);
    expect(rejectionReasonSentence('row_lacks_embedding_flag')).toMatch(/embedding flag/i);
    expect(rejectionReasonSentence('row_incoherent')).toMatch(/more than one task/i);
  });

  it('returns null for a member this build has never heard of, rather than guessing', () => {
    // `reason` is typed `string` in @kb/contracts on purpose: the closed set is the DATA PLANE's and
    // neither Laravel nor that package validates it, so exhaustiveness is not available. Null lets
    // the panel render the raw token beside the server's `detail`, which is strictly more
    // information than a sentence this build invented.
    expect(rejectionReasonSentence('quota_exhausted')).toBeNull();
    expect(rejectionReasonSentence('')).toBeNull();
    // Not an object lookup, so no prototype key resolves to anything.
    expect(rejectionReasonSentence('constructor')).toBeNull();
    expect(rejectionReasonSentence('__proto__')).toBeNull();
    expect(rejectionReasonSentence('toString')).toBeNull();
  });
});

/**
 * The 422 that is not a field error.
 *
 * `EmbeddingConfigurationController::update`: "422 is both the FormRequest's half-designation rule
 * and the resolver's refusal, which share an `error_class` of `validation` and differ only in
 * whether `errors` is present." `bootstrap/app.php:446` states the invariant the discriminator rests
 * on: "`errors` is a SUPERSET present only on `validation` — never null, never {} elsewhere."
 */
describe('the resolver refusal is discriminated structurally, never by wording', () => {
  /** The ADR-031 paragraph, abridged only in this fixture's comment — the assertion uses it whole. */
  const AMBIGUOUS =
    'This organization has 2 embedding-capable connections that do not agree on (provider, model): ' +
    '01JCONNAAAAAAAAAAAAAAAAAAA -> openai/text-embedding-3-large; ' +
    '01JCONNBBBBBBBBBBBBBBBBBBB -> openai/text-embedding-3-small. Which one embeds is not a ' +
    'preference — that pair IS the vector space (EmbeddingSpace derives the Qdrant collection name ' +
    'from it), so breaking the tie by convention would let an unrelated connection edit move the ' +
    'space a corpus was indexed under. Cosine distance is defined between any two vectors of equal ' +
    'width, so nothing would raise and only ranking would change. Designate one connection and ' +
    'model explicitly.';

  it('returns the paragraph WHOLE for a validation envelope carrying no errors map', () => {
    const error = new KbError('validation', false, null, '01JREQ', AMBIGUOUS, null);

    // VERBATIM, byte for byte. The ingestion path raises this same string, so a paraphrase here
    // would be a second copy that drifts from the one the operator meets on a failed upload.
    expect(resolverRefusalMessage(error)).toBe(AMBIGUOUS);
  });

  it('returns null when the 422 carries a field map, because that is applyServerErrors’ job', () => {
    const error = new KbError('validation', false, null, '01JREQ', 'The given data was invalid.', {
      model: ['The model field is required when connection id is present.'],
    });

    // Routing this to the guidance panel would put "The model field is required…" in a paragraph
    // with no control, while the field it names rendered nothing.
    expect(resolverRefusalMessage(error)).toBeNull();
  });

  it('returns null for every other class, including the 409 shape', () => {
    // A deliberate 4xx our own code raised renders as `internal_dependency` / retryable false, which
    // is A3's sentinel's territory and not this one's.
    expect(
      resolverRefusalMessage(new KbError('internal_dependency', false, null, '01JREQ', 'nope')),
    ).toBeNull();
    expect(resolverRefusalMessage(new KbError('authorization', false))).toBeNull();
    // `error_class: null` means no envelope parsed: unknown, and unknown is permanently
    // non-retryable. No class is ever invented to fill the slot.
    expect(resolverRefusalMessage(new KbError(null, false, null, null, 'boom'))).toBeNull();
    expect(resolverRefusalMessage(new Error('boom'))).toBeNull();
    expect(resolverRefusalMessage(null)).toBeNull();
  });

  it('returns null for a validation envelope with neither a map nor a sentence', () => {
    // A server contract violation rather than guidance. Falling through to the class-mapped copy is
    // better than rendering an empty panel the user cannot act on.
    expect(resolverRefusalMessage(new KbError('validation', false, null, '01JREQ', '   '))).toBeNull();
  });
});

/**
 * A3's 409 discriminator, PINNED against the shape this endpoint's 409 actually has.
 *
 * BOTH HALVES OF THIS BLOCK HAVE SINCE FLIPPED, WHICH IS WHY IT WAS WRITTEN. It used to record
 * that the suspended-organization abort carried an EMPTY message and that `deleteConflictMessage`
 * therefore bought nothing here, "so that the day the control plane gives that `abort` a sentence,
 * this assertion flips and names the reason". Two things have happened since:
 *
 *   1. every suspension abort now carries `OrganizationStatus::SUSPENDED_REFUSAL`;
 *   2. finding J2 replaced the message-comparison sentinel with the envelope's `actionable` flag,
 *      so a client no longer infers a status from a string at all.
 */
describe('the suspended-organization 409 now carries a sentence, and it renders', () => {
  it('renders the server sentence when the envelope says it was written for a person', () => {
    // `abort_unless($organization->status === Active, 409, OrganizationStatus::SUSPENDED_REFUSAL)`.
    // `bootstrap/app.php` renders an unclassified 4xx as `internal_dependency` / `retryable: false`
    // — the same pair a genuine 500 arrives with — and marks it `actionable`, which is the ONLY
    // thing separating the two.
    const suspended = new KbError(
      'internal_dependency',
      false,
      null,
      '01JREQ',
      'This organization is suspended, so its configuration is read-only.',
      null,
      true,
    );

    expect(deleteConflictMessage(suspended)).toBe(
      'This organization is suspended, so its configuration is read-only.',
    );
  });

  it('renders nothing for the same class and message when the envelope did NOT say so', () => {
    // The whole point of the flag. Identical `(error_class, retryable)`, identical non-empty
    // message, opposite verdict — and the verdict is the SERVER's, not an inference from the text.
    // A defect falls through to the class-mapped sentence, which is what `actionErrorCopy` is for.
    expect(
      deleteConflictMessage(
        new KbError('internal_dependency', false, null, '01JREQ', 'api-7.internal: ECONNRESET'),
      ),
    ).toBeNull();
  });

  it('defaults to not-actionable, so an envelope that never carried the field renders nothing', () => {
    // FAIL CLOSED. `KbError`'s parameter defaults to false and `toKbError` reads `=== true`, so an
    // SSE `error` frame (which omits the field) and a body from something that is not our server
    // both land here. The cost is a blander sentence; the cost of the other default is an internal
    // hostname on screen.
    expect(deleteConflictMessage(new KbError('internal_dependency', false, null, '01JREQ', 'x')))
      .toBeNull();
  });

  it('still refuses the retryable and wrong-class cases before it ever looks at the flag', () => {
    // `actionable` narrows; it does not replace the two checks in front of it. A retryable
    // `internal_dependency` is a downstream brownout, and its message is not this screen's to show.
    expect(
      deleteConflictMessage(new KbError('internal_dependency', true, null, '01JREQ', 'x', null, true)),
    ).toBeNull();
    expect(
      deleteConflictMessage(new KbError('validation', false, null, '01JREQ', 'x', null, true)),
    ).toBeNull();
  });
});
