import { ERROR_CLASSES, STREAM_LOST, isKbValidationEnvelope } from '@kb/contracts';
import { createFormControl } from 'react-hook-form';
import type { FieldErrors, Path, UseFormReturn } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { ERROR_COPY, applyServerErrors, endUserCopy } from '@/lib/forms/apply-server-errors';

/**
 * Laravel's 422 -> react-hook-form, in the `unit` project (node, no DOM).
 *
 * `createFormControl` is RHF's own headless entry point — the same control object `useForm` builds,
 * without React. That matters here: every behaviour under test is about RHF's error store and its
 * field registry, and none of it is about rendering. Driving it through a component would add a
 * browser, a render loop and a `waitFor` to assert `control._formState.errors`.
 *
 * Refs are installed by CALLING the callback `register` returns, exactly as React does. A field that
 * is registered and never handed a ref is not an artificial case — it is every shadcn `Select` and
 * `Switch`, and every `Controller` whose `field.ref` was not forwarded.
 */

interface BotForm {
  name: string;
  welcome_message: string;
  retrieval: { top_k: number };
  starter_questions: string[];
}

/** The server's own field vocabulary, as `php artisan kb:dump-form-rules` writes it. */
const KNOWN_PATHS = [
  'name',
  'welcome_message',
  'retrieval',
  'retrieval.top_k',
  'starter_questions',
  // WILDCARD, not an index. The manifest is dumped from `rules()`, where an array member is
  // `starter_questions.*`; Laravel's 422 keys the same rule positionally as `starter_questions.2`.
  'starter_questions.*',
];

interface Harness {
  form: UseFormReturn<BotForm, unknown, BotForm>;
  errors: () => FieldErrors<BotForm>;
  focused: string[];
}

function harness(options: { readonly refless?: readonly string[] } = {}): Harness {
  const control = createFormControl<BotForm>({
    defaultValues: {
      name: '',
      welcome_message: '',
      retrieval: { top_k: 5 },
      starter_questions: ['a', 'b', 'c'],
    },
  });
  const form = control as unknown as UseFormReturn<BotForm, unknown, BotForm>;
  const focused: string[] = [];
  const refless = new Set(options.refless ?? []);

  for (const name of [
    'name',
    'welcome_message',
    'retrieval.top_k',
    'starter_questions.0',
    'starter_questions.1',
    'starter_questions.2',
  ]) {
    const field = form.register(name as Path<BotForm>);
    // A registered field with NO ref: mounted, unfocusable, and invisible to any check that only
    // asks whether the name is known to RHF.
    if (refless.has(name)) continue;
    field.ref({
      name,
      type: 'text',
      value: '',
      focus: () => focused.push(name),
    } as unknown as HTMLInputElement);
  }

  return { form, errors: () => form.control._formState.errors, focused };
}

describe('applyServerErrors', () => {
  it("maps a dot path straight onto the field, typed 'server'", () => {
    const { form, errors } = harness();

    // Laravel already emits valid RHF names — RHF rejects bracket syntax and Laravel never produces
    // it — so nothing is translated on the way to setError.
    applyServerErrors(form, KNOWN_PATHS, {
      name: ['The name field is required.'],
      'retrieval.top_k': ['The retrieval.top_k must not be greater than 50.'],
    });

    expect(errors().name?.message).toBe('The name field is required.');
    expect(errors().retrieval?.top_k?.message).toBe(
      'The retrieval.top_k must not be greater than 50.',
    );
    // `type` is MANDATORY on every write. RHF's setError merges over the existing node, and an
    // entry with no discriminator is one an untyped write elsewhere in the tree can quietly replace.
    expect(errors().name?.type).toBe('server');
    expect(errors().retrieval?.top_k?.type).toBe('server');
  });

  it('keeps a child error when a parent path is also in the same 422', () => {
    const { form, errors } = harness();

    applyServerErrors(form, KNOWN_PATHS, {
      'retrieval.top_k': ['top_k is too large.'],
      retrieval: ['The retrieval object is malformed.'],
    });

    // Both survive. A write on "retrieval" that replaced the subtree would take "retrieval.top_k"
    // with it and the field error would vanish with nothing logged.
    expect(errors().retrieval?.top_k?.message).toBe('top_k is too large.');
    expect((errors().retrieval as { message?: string } | undefined)?.message).toBe(
      'The retrieval object is malformed.',
    );
  });

  it('folds the numeric index to `*` for the LOOKUP and keeps the positional path for the error', () => {
    const { form, errors } = harness();

    applyServerErrors(form, KNOWN_PATHS, {
      'starter_questions.2': ['Each starter question must be under 200 characters.'],
    });

    // The manifest has no key `starter_questions.2` — only `starter_questions.*` — so a literal
    // membership test would route this to root and the user would never see which question is wrong.
    expect(errors().starter_questions?.[2]?.message).toBe(
      'Each starter question must be under 200 characters.',
    );
    expect(errors().root).toBeUndefined();
  });

  it('routes a key this form does not render to root.serverError', () => {
    const { form, errors } = harness();

    // `$validator->after()`, `withValidator`, and a service-layer ValidationException all emit keys
    // no rule set lists. setError on an unregistered name displays NOWHERE and does not even
    // persist — the next handleSubmit replaces formState.errors wholesale — so the user clicks Save
    // into a silent loop they drive by hand.
    applyServerErrors(form, KNOWN_PATHS, {
      'billing.plan': ['Your plan does not include this feature.'],
    });

    expect(errors().root?.serverError?.message).toBe('Your plan does not include this feature.');
    expect(errors().root?.serverError?.type).toBe('server');
  });

  it('writes root.serverError EXACTLY ONCE, with every orphan joined', () => {
    const { form, errors } = harness();

    applyServerErrors(form, KNOWN_PATHS, {
      'billing.plan': ['Your plan does not include this feature.'],
      'quota.sources': ['This organization is at its source limit.'],
      'legal.terms': ['Accept the updated terms first.'],
    });

    // ONE SLOT. A setError per orphan means the last write wins: a user with three problems is told
    // about one, fixes it, submits, and is told about the next — three round trips for one 422.
    const message = errors().root?.serverError?.message ?? '';
    expect(message).toContain('Your plan does not include this feature.');
    expect(message).toContain('This organization is at its source limit.');
    expect(message).toContain('Accept the updated terms first.');
  });

  it('partitions a mixed 422: known keys onto fields, unknown onto root', () => {
    const { form, errors } = harness();

    applyServerErrors(form, KNOWN_PATHS, {
      name: ['The name field is required.'],
      'billing.plan': ['Your plan does not include this feature.'],
    });

    expect(errors().name?.message).toBe('The name field is required.');
    expect(errors().root?.serverError?.message).toBe('Your plan does not include this feature.');
  });

  it('leaves root untouched when every key landed on a field', () => {
    const { form, errors } = harness();

    applyServerErrors(form, KNOWN_PATHS, { name: ['The name field is required.'] });

    // A stale root banner over a form whose fields already say what is wrong reads as a second,
    // unrelated failure.
    expect(errors().root).toBeUndefined();
  });

  it('does nothing at all for an empty errors map', () => {
    const { form, errors } = harness();

    applyServerErrors(form, KNOWN_PATHS, {});

    expect(errors()).toEqual({});
  });
});

describe('applyServerErrors focus handling', () => {
  it('focuses the FIRST field only, never every field with an error', () => {
    const { form, focused } = harness();

    applyServerErrors(form, KNOWN_PATHS, {
      name: ['required'],
      welcome_message: ['too long'],
      'retrieval.top_k': ['too large'],
    });

    expect(focused).toEqual(['name']);
  });

  it('skips a registered-but-refless field and focuses the next one that has a ref', () => {
    // `name` here stands in for a shadcn Select or a Controller whose field.ref was never attached:
    // RHF knows the name, and RHF's own focusFieldBy skips it silently because there is no
    // `_f.ref.focus`.
    const { form, focused } = harness({ refless: ['name'] });

    applyServerErrors(form, KNOWN_PATHS, {
      name: ['required'],
      welcome_message: ['too long'],
    });

    // Spending the one focus on an unfocusable field is a page that does not scroll and an error
    // the user never sees below the fold — which looks exactly like the form ignoring the click.
    expect(focused).toEqual(['welcome_message']);
  });

  it('focuses nothing when no errored field is focusable', () => {
    const { form, focused, errors } = harness({ refless: ['name', 'welcome_message'] });

    applyServerErrors(form, KNOWN_PATHS, { name: ['required'], welcome_message: ['too long'] });

    expect(focused).toEqual([]);
    // The errors still land — focus is an affordance, not the mechanism.
    expect(errors().name?.message).toBe('required');
    expect(errors().welcome_message?.message).toBe('too long');
  });
});

describe('end-user copy', () => {
  it('has a sentence for all 18 classes, the client-local sentinel, and unknown', () => {
    for (const name of ERROR_CLASSES) {
      expect(ERROR_COPY[name], `no copy for ${name}`).toBeTypeOf('string');
    }
    expect(ERROR_COPY[STREAM_LOST]).toBeTypeOf('string');
    expect(ERROR_COPY['unknown']).toBeTypeOf('string');
  });

  it('renders the class-mapped sentence plus the request_id, never the envelope message', () => {
    const rendered = endUserCopy({ error_class: 'rate_limit', request_id: '01JREQ' });

    expect(rendered).toBe('Too many requests just now. Wait a moment and try again. (ref 01JREQ)');
    // The envelope's `message` is operator-facing: an internal hostname, raw upstream provider text,
    // or an identifier with no business in a tenant's UI. It is logged, never rendered.
    expect(rendered).not.toContain('api-7.internal');
  });

  it('falls back to the unknown sentence for a null class', () => {
    // Null means no envelope parsed. There is no class name to render and none is invented.
    // Asserted against the CONSTANT rather than against a second copy of the sentence: a wording
    // change should not break eight tests that were never about the wording.
    expect(endUserCopy({ error_class: null, request_id: null })).toBe(ERROR_COPY.unknown);
  });

  /**
   * 5B-S4. `apps/web` used to carry its own `hasFieldErrors`, and it did not guard: it tested only
   * `typeof envelope.errors === 'object'`, `typeof null` IS `'object'`, so a null errors map
   * narrowed to `Record<string, string[]>` and `Object.entries(null)` threw inside
   * `applyServerErrors`. The one in `@kb/contracts` is the same narrowing done correctly, and it is
   * the ONLY one now — a second copy of a guard is a second copy of a rule.
   */
  describe('the narrowing guard for the one class that carries an errors map', () => {
    it('accepts a real validation envelope', () => {
      expect(
        isKbValidationEnvelope({
          error_class: 'validation',
          message: 'The given data was invalid.',
          retryable: false,
          errors: { name: ['required'] },
        }),
      ).toBe(true);
    });

    it('rejects another class that happens to render 422', () => {
      // A 422 is not always a validation failure — an idempotency conflict is also a 422, carries
      // an `error_class` and NO errors map, and a handler branching on the status treats it as one.
      expect(
        isKbValidationEnvelope({
          error_class: 'tenant_quota',
          message: 'over limit',
          retryable: false,
        }),
      ).toBe(false);
    });

    it('rejects a NULL errors map — the case the deleted local copy accepted', () => {
      // THE REGRESSION. `typeof null === 'object'`, so the old guard returned true here and the
      // caller then ran `Object.entries(null)`: a TypeError thrown by the thing that exists to
      // stop it. Unreachable from a correct server, which is exactly why it survived review.
      expect(
        isKbValidationEnvelope({
          error_class: 'validation',
          message: 'The given data was invalid.',
          retryable: false,
          errors: null,
        }),
      ).toBe(false);
    });

    it('rejects an omitted errors map, and a non-envelope, without the caller pre-validating', () => {
      // It takes `unknown`, so it can be the FIRST guard applied to a parsed body. The deleted copy
      // took a `KbErrorEnvelope`, so it could only run after something else had already narrowed —
      // two guards, the second of them weaker.
      expect(
        isKbValidationEnvelope({
          error_class: 'validation',
          message: 'The given data was invalid.',
          retryable: false,
        }),
      ).toBe(false);
      expect(isKbValidationEnvelope(null)).toBe(false);
      expect(isKbValidationEnvelope({ errors: { name: ['required'] } })).toBe(false);
    });

    it('feeds applyServerErrors safely: a null errors map never reaches Object.entries', () => {
      // End to end, because the defect was not the guard's return value — it was what the caller
      // did with it one line later.
      const envelope: unknown = {
        error_class: 'validation',
        message: 'The given data was invalid.',
        retryable: false,
        errors: null,
      };

      const { form } = harness();
      expect(isKbValidationEnvelope(envelope)).toBe(false);
      // And if a caller ignored the guard, this is the throw it was hiding.
      expect(() =>
        applyServerErrors(form, KNOWN_PATHS, null as unknown as Record<string, string[]>),
      ).toThrow(TypeError);
    });
  });
});
