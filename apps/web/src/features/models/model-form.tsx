'use client';

import type { ProviderModelResource } from '@kb/contracts';
import {
  providerModelCreateDefaults,
  providerModelCreateSchema,
  providerModelEditDefaults,
  providerModelEditSchema,
  type ProviderModelCreateIn,
  type ProviderModelCreateOut,
  type ProviderModelEditIn,
  type ProviderModelEditOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm, type UseFormReturn } from 'react-hook-form';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { applyAuthError } from '@/features/auth/auth-error';

import {
  CAPABILITY_FAMILIES,
  PROVIDER_MODEL_EDIT_KNOWN_PATHS,
  PROVIDER_MODEL_KNOWN_PATHS,
  capabilitiesInFamily,
  capabilityLabel,
  createModel,
  familyOfRow,
  flagsRefusedOn,
  updateModel,
  type CapabilityFamily,
} from './api';

/**
 * The two forms that write a catalog row: register one, and replace one.
 *
 * ── THE SHAPE OF THIS FILE IS THE SHAPE OF THE ENDPOINTS ────────────────────────────────────────
 * They are NOT one form with a boolean. `POST` rules `enabled` and the three pricing fields
 * `sometimes`/`nullable`, so a create body may omit them; `PUT` rules them `required`/`present`, so an
 * edit body may not — and the edit request declares no `model` field at all, because the identifier
 * is immutable. Two `strictObject` schemas in `@kb/contracts/forms` express exactly that, probed
 * against the server's own dumped `rules()`, and one `.partial()`-ed schema would express none of it.
 *
 * ── A BODY THAT CHANGES NOTHING IS A 422, SO THE EDIT FORM POSTS EVERY FIELD ────────────────────
 * `PUT …/models/{model}` requires the full attribute set, because "must change something" is
 * expressible in `rules()` only as `required_without_all` naming six siblings on each of seven
 * fields, and the readable alternative — an `after()` closure — is invisible to `kb:dump-form-rules`.
 * `providerModelEditDefaults(row)` seeds all eight controls from the loaded row and returns the
 * schema's OUTPUT type, so the same value is both `defaultValues` here and a complete replacement
 * body for the inline `enabled` toggle in the table. A form that seeded four fields and sent four
 * fields would 422 on a rule nobody rendered.
 *
 * ── THE TASK SELECTOR IS NOT A SERVER FIELD, AND MUST NOT BECOME ONE ───────────────────────────
 * ROWS ARE TASK-EXCLUSIVE: no row carries both `embedding` and `rerank`, and neither of those sits
 * beside `text`, `tool_use` or `reasoning`. Expressing that as a CHOICE — one radio group, one
 * family of checkboxes — makes the incoherent row hard to build here rather than merely discouraged
 * by a tooltip.
 *
 * ── NOTHING REFUSES AN INCOHERENT ROW AT SAVE TIME, AND THIS HEADER USED TO SAY IT DID ─────────
 * The old wording was "the data plane refuses an incoherent row with `row_incoherent` in the
 * readiness rejections", read as a save-time check. It is not one. `assert_row_coherent` is never
 * reached from the model-catalogue write path — `ProviderModelService` imports no internal client
 * and `ProviderModelController` never crosses the Laravel↔FastAPI seam — so a POST or PUT of
 * `openrouter` + `["rerank"]` returns 201/200 and stores exactly what it was sent.
 *
 * THE REFUSAL IS DEFERRED TO READINESS AND COVERS ONLY EMBEDDING. The one reachable caller is
 * `ineligibility()` in `app/providers/embedding_selection.py`, which catches the error and turns it
 * into the `row_incoherent` rejection the embedding-designation screen renders — over EMBEDDING
 * candidates and nothing else. A mis-flagged rerank row is refused by nothing, anywhere: it saves
 * silently, appears in no `rejected[]` list, and simply never reranks.
 *
 * So this radio group is not a mirror of a server check. For the rerank branch it is the only check
 * there is, which is why `CapabilityGroup` also RENDERS an incoherent stored row rather than
 * assuming one cannot exist, and why the narrowing below had to be exact.
 *
 * ── AND THE NARROWING: `flagsRefusedOn`, NOT "the other families" ──────────────────────────────
 * `changeFamily` used to strip every known flag outside the target family, which made this console
 * STRICTER than the data plane over eight flags it accepts — `stream_usage` and `prompt_caching` on
 * an embedding row are true statements about a real deployment. An operator who touched the task
 * radio for any reason lost them, with nothing reported. It now strips exactly what
 * `assert_row_coherent` refuses (`features/models/api.ts` → `flagsRefusedOn`), and everything else
 * is carried through AND shown, because a flag kept in the payload while invisible on screen is the
 * same silence one step further along.
 *
 * It is `useState`, deliberately, and not a form field: `test/form-drift.test.ts` asserts SET
 * EQUALITY between a schema's paths and its manifest's keys, so a ninth path would turn that suite
 * red — which would be the correct outcome, because the server has no such column and posting one
 * would be a 422 on a key the operator cannot see.
 *
 * ── AN UNSET FLAG SILENTLY DISABLES A DOWNSTREAM STAGE, SAID AT THE POINT OF ENTRY ─────────────
 * Reranking is capability-gated: a model that can rerank but is not flagged for it simply never
 * reranks, with no error anywhere and answer quality drifting for as long as nobody looks. That
 * sentence is in the family description, in the form, beside the checkboxes — not in a tooltip
 * somebody has to hover, and not only in documentation.
 *
 * ── AND THE OTHER DIRECTION, WHICH IS FINDING J1 ───────────────────────────────────────────────
 * A flag SET that the vendor cannot honour is equally silent, and worse, because the operator has
 * done something and been told nothing. This console checks the two coherence rules it can compute
 * from the flags themselves (`flagsRefusedOn`), and neither it nor the save path asks the third
 * question — whether the provider family publishes the surface. For the embedding family the answer
 * arrives later, by name, in the readiness verdict's `rejected[]`. For the rerank family it arrives
 * nowhere. So the rerank task carries a standing sentence saying so; see `CapabilityGroup`.
 */

/** `max:200` on both identifiers, from the FormRequest. An affordance on the control, never the authority. */
const NAME_MAX = 200;

/**
 * ONE TYPE FOR THE SHARED HALF OF BOTH FORMS.
 *
 * `ProviderModelCreateIn` is `ProviderModelEditIn` plus `model`, so the eight controls below are
 * identical on both — but RHF's `UseFormReturn` is not co-variant in its value type, so the create
 * form's instance is not assignable to this one without a cast. There is exactly ONE cast, at the
 * create form's call site, and it is narrower than it looks: the two schemas are `strictObject`s
 * whose shared fields are the SAME schema objects (`displayName`, `supported`, `tokenCount`, `price`,
 * `currency` in `packages/contracts/src/forms/provider-model.ts`), and the drift suite asserts each
 * schema's path set against its own manifest. If the two ever stop sharing a field, that suite is red
 * before this file is.
 *
 * The alternative was duplicating nine controls, and the labels are load-bearing: a 422 is keyed to a
 * control by name and a spec locates one the same way, so two copies drifting by one word is two
 * screens where the server's message lands somewhere different.
 */
type SharedForm = UseFormReturn<ProviderModelEditIn, unknown, ProviderModelEditOut>;

/**
 * The capability group. One `Controller` over `supported`, and one `<Checkbox>` per flag in the
 * selected family.
 *
 * ── WHY THE VALUE IS THE WHOLE `string[]` AND NOT A SET OF BOOLEANS ────────────────────────────
 * Because of the flags this build has never heard of. The wire vocabulary is OPEN — the closed list
 * is the data plane's, the control plane validates `string|max:64|regex:/^[a-z][a-z0-9_]*$/`
 * (spelling, not membership), and the OpenAPI document publishes `array<string>` with no enum — so a
 * row may legitimately carry a member this console cannot render. Holding the array verbatim means an
 * unrecognised flag survives an edit untouched; a checkbox-per-known-flag model would drop it on
 * save, stripping a capability the platform depends on with a 200 on the request that did it.
 *
 * ── EVERY HELD FLAG WITH NO CHECKBOX IS SHOWN, AND THAT IS NOW MORE THAN THE UNKNOWN ONES ──────
 * It used to be exactly the unknown ones, because `changeFamily` stripped every KNOWN flag outside
 * the family, so nothing else could be held without a control. Narrowing that strip to what the data
 * plane actually refuses means a `stream_usage` can now ride along on an embedding row — correctly —
 * and a flag held in the payload with nothing on screen is the same silent-loss failure one step
 * further along. So the badge list is "everything this task does not offer", and it names the two
 * reasons a flag lands there.
 *
 * The stored row may also be INCOHERENT: nothing refuses one at save time (see the file header), so
 * one really can be loaded. That case gets its own line rather than a badge, because "kept exactly as
 * they are" is the wrong promise for a combination the platform will later reject.
 */
function CapabilityGroup({
  value,
  onChange,
  family,
  disabled,
}: {
  readonly value: readonly string[];
  readonly onChange: (next: string[]) => void;
  readonly family: CapabilityFamily;
  readonly disabled: boolean;
}) {
  const offered = capabilitiesInFamily(family);
  const hasControl = new Set<string>(offered);
  /** Held, but with no checkbox in this task: another family's flag, or one this build never heard of. */
  const carried = value.filter((flag) => !hasControl.has(flag));
  const refused = flagsRefusedOn(family);
  const incoherent = carried.filter((flag) => refused.has(flag));

  return (
    <div className="flex flex-col gap-3">
      <div className="grid gap-2 sm:grid-cols-2">
        {offered.map((flag) => {
          const id = `capability-${flag}`;

          return (
            <div key={flag} className="flex items-center gap-2">
              <Checkbox
                id={id}
                checked={value.includes(flag)}
                disabled={disabled}
                onCheckedChange={(next) => {
                  // A REBUILT array, never a splice. The seeded value is a copy of the cached row's
                  // array (`providerModelEditDefaults` copies it for exactly this reason), and
                  // mutating form state in place is how a re-render decides nothing changed.
                  onChange(
                    next === true
                      ? [...value.filter((held) => held !== flag), flag]
                      : value.filter((held) => held !== flag),
                  );
                }}
              />
              {/* A real `<label htmlFor>`: a Radix checkbox is a button, so its accessible name has
                  to come from somewhere. It is also the second half of the pointer target and what
                  lets a spec — and a person — address the control by the words beside it. */}
              <Label htmlFor={id} className="font-normal">
                {capabilityLabel(flag)}
              </Label>
            </div>
          );
        })}
      </div>

      {family !== 'rerank' ? null : (
        // ── FINDING J1, SAID AT THE POINT OF ENTRY ────────────────────────────────────────────
        // This is NOT the `incoherent` alert below. That one fires on a row whose flags contradict
        // each other, which this console can detect from the flags alone. This one fires on a row
        // that is perfectly coherent and still unusable: whether the CONNECTION'S VENDOR can rerank
        // at all is a third question, asked by `capabilities.can_rerank` in the data plane, and
        // asked on NO path this form can reach. `openrouter` + `rerank` saves with a 200, appears
        // in no readiness rejection, and never reranks.
        //
        // Standing text rather than a conditional warning, deliberately: naming the vendors that
        // cannot rerank would put a fourth copy of the provider x task matrix in apps/web, and
        // ADR-051 keeps that vocabulary out of the client — the control plane constrains SPELLING,
        // not membership. So the console says what it knows (that the question exists and that
        // nothing here answers it) and points at the one surface that does answer, rather than
        // answering it wrongly the day a cell moves.
        <p className="text-sm text-muted-foreground">
          Whether this connection&apos;s provider can rerank at all is not checked here, and is not
          checked when you save. A rerank flag the vendor cannot honour is accepted, shows no error,
          and simply never reranks. Confirm the provider supports reranking before you rely on it.
        </p>
      )}

      {carried.length === 0 ? null : (
        <div className="flex flex-col gap-1">
          <p className="text-sm text-muted-foreground">
            This row also claims flags this task does not offer — either they belong to another task,
            or this console does not recognise them. They are kept exactly as they are when you save.
          </p>
          <div className="flex flex-wrap gap-1">
            {carried.map((flag) => (
              // Text from the wire, interpolated as a JSX child and never as HTML.
              <Badge key={flag} variant="outline" className="font-mono">
                {flag}
              </Badge>
            ))}
          </div>
        </div>
      )}

      {incoherent.length === 0 ? null : (
        // Reachable ONLY from a stored row: `changeFamily` strips exactly this set, and nothing
        // refuses an incoherent row at save time, so one that already exists has to be renderable.
        // `warning`, not `destructive`: the row is saved and saveable — it is a condition to repair,
        // not an action that failed.
        <Alert variant="warning">
          <AlertTitle>This row claims a flag that contradicts its task</AlertTitle>
          <AlertDescription>
            {/* The flag names come off the wire and are interpolated as JSX children, never HTML. */}
            <span className="font-mono">{incoherent.join(', ')}</span> cannot sit beside the{' '}
            {familyLabel(family)} task on one row. Nothing rejects this when you save it: an
            embedding row is refused later, when the embedding designation is checked for readiness,
            and a rerank row is refused nowhere at all — it simply never reranks. Pick the task this
            row is really for; the flags that contradict it are dropped when you do.
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
}

/** The words beside the task's radio button, so the sentence above names what the operator picked. */
const familyLabel = (family: CapabilityFamily): string =>
  CAPABILITY_FAMILIES.find(([member]) => member === family)?.[1] ?? family;

/**
 * The eight controls both forms share, plus the task chooser that arranges them.
 *
 * The family radio group is `<fieldset>`/`<legend>` and native `<input type="radio">` rather than a
 * `<Select>`, because the choice changes WHICH controls exist below it: a native radio group
 * announces the grouping and its arrow-key semantics without any code, and a select does not.
 */
function SharedModelFields({
  form,
  family,
  onFamilyChange,
  pending,
}: {
  readonly form: SharedForm;
  readonly family: CapabilityFamily;
  readonly onFamilyChange: (next: CapabilityFamily) => void;
  readonly pending: boolean;
}) {
  /**
   * Switching task drops EXACTLY THE FLAGS THE DATA PLANE REFUSES on the new task, and nothing else.
   *
   * It used to drop every known flag outside the target family, and the family is a UI arrangement
   * rather than a server rule: `assert_row_coherent` forbids `embedding` + `rerank`, and either of
   * those beside `text`/`tool_use`/`reasoning`. It permits the other eight chat flags on an embedding
   * or rerank row, and some are true there — a provider that reports usage on an embedding response
   * really does have `stream_usage`. Stripping them made this console stricter than the server and
   * removed functionality with nothing reported, which is the invisible half of the drift asymmetry
   * (`rhf-zod-forms` NN3). `flagsRefusedOn` is the exact set, derived in `./api` from those two rules
   * and cross-checked against `capabilities.py` by `tests/unit/model-catalogue.test.ts`.
   *
   * UNKNOWN FLAGS ARE NEVER DROPPED: they belong to a vocabulary this console does not own, and the
   * refusal set contains only names it does. That falls out of the filter rather than needing a
   * second clause.
   */
  const changeFamily = (next: CapabilityFamily): void => {
    const refused = flagsRefusedOn(next);
    form.setValue(
      'supported',
      (form.getValues('supported') ?? []).filter((flag) => !refused.has(flag)),
    );
    onFamilyChange(next);
  };

  return (
    <>
      <FormField
        control={form.control}
        name="display_name"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Display name</FormLabel>
            <FormControl>
              <Input {...field} autoComplete="off" maxLength={NAME_MAX} placeholder="GPT-5.6 Sol" />
            </FormControl>
            <FormDescription>
              How this model is named in the console and in a bot&apos;s model picker.
            </FormDescription>
            <FormMessage />
          </FormItem>
        )}
      />

      <fieldset className="flex flex-col gap-2" disabled={pending}>
        <legend className="text-base font-medium">What this model is for</legend>
        <p className="text-sm text-muted-foreground">
          A row describes ONE task. An embedding model and a chat model are different products on
          different endpoints, and a row claiming both is refused before it is ever used.
        </p>
        {CAPABILITY_FAMILIES.map(([member, label, description]) => (
          <div key={member} className="flex items-start gap-2">
            <input
              type="radio"
              id={`task-${member}`}
              name="model-task"
              className="mt-1 size-4 accent-primary"
              checked={family === member}
              onChange={() => changeFamily(member)}
            />
            <div className="flex flex-col">
              <Label htmlFor={`task-${member}`} className="font-normal">
                {label}
              </Label>
              <span className="text-sm text-muted-foreground">{description}</span>
            </div>
          </div>
        ))}
      </fieldset>

      <FormField
        control={form.control}
        name="supported"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Capabilities</FormLabel>
            <FormControl>
              <div>
                <CapabilityGroup
                  value={field.value}
                  onChange={field.onChange}
                  family={family}
                  disabled={pending}
                />
              </div>
            </FormControl>
            <FormDescription>
              A claim, not a guarantee: a flag is honoured only if the vendor publishes the surface
              too. Leaving one unset does not fail — the stage it gates simply never runs.
            </FormDescription>
            <FormMessage />
          </FormItem>
        )}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField
          control={form.control}
          name="context_window"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Context window</FormLabel>
              <FormControl>
                {/* `valueAsNumber` OFF THE DOM NODE, not `z.coerce.number()`. Coercion turns `null`
                    and `''` into 0, so an explicitly-null field the server rejects would be
                    accepted here as a valid zero. An empty control is `NaN`, which fails on the
                    field the operator is looking at rather than being saved as a limit of nothing. */}
                <Input
                  type="number"
                  inputMode="numeric"
                  min={0}
                  step={1}
                  name={field.name}
                  ref={field.ref}
                  onBlur={field.onBlur}
                  value={Number.isNaN(field.value) ? '' : String(field.value)}
                  onChange={(event) => field.onChange(event.currentTarget.valueAsNumber)}
                />
              </FormControl>
              <FormDescription>
                Tokens. 0 means the vendor&apos;s figure is not recorded.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="max_output_tokens"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Max output tokens</FormLabel>
              <FormControl>
                <Input
                  type="number"
                  inputMode="numeric"
                  min={0}
                  step={1}
                  name={field.name}
                  ref={field.ref}
                  onBlur={field.onBlur}
                  value={Number.isNaN(field.value) ? '' : String(field.value)}
                  onChange={(event) => field.onChange(event.currentTarget.valueAsNumber)}
                />
              </FormControl>
              <FormDescription>
                The model&apos;s ceiling, not a bot&apos;s budget. 0 means not recorded.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <FormField
          control={form.control}
          name="input_price_per_million"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Input price</FormLabel>
              <FormControl>
                {/* A TEXT INPUT, AND THAT IS THE PRICING RULE EXPRESSED AS A CONTROL. `type="number"`
                    hands the browser a float: `'0.020000'` becomes `0.02` becomes `'0.02'` — the same
                    amount, a different string, in an audit row and in a diff. What the operator typed
                    is what is submitted, and what the server returns is what is displayed. */}
                <Input
                  {...field}
                  value={field.value === null ? '' : String(field.value)}
                  type="text"
                  inputMode="decimal"
                  autoComplete="off"
                  placeholder="1.25"
                />
              </FormControl>
              <FormDescription>Per million tokens. Leave empty if unpublished.</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="output_price_per_million"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Output price</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  value={field.value === null ? '' : String(field.value)}
                  type="text"
                  inputMode="decimal"
                  autoComplete="off"
                  placeholder="10"
                />
              </FormControl>
              <FormDescription>Per million tokens.</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="price_currency"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Currency</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  value={field.value ?? ''}
                  autoComplete="off"
                  autoCapitalize="characters"
                  maxLength={3}
                  placeholder="USD"
                />
              </FormControl>
              <FormDescription>
                Three upper-case letters. A price needs one; a currency on its own is fine.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </div>

      <FormField
        control={form.control}
        name="enabled"
        render={({ field }) => (
          <FormItem className="flex flex-row items-center gap-3">
            <FormControl>
              {/* The primitive is a Radix switch: `role="switch"` with `aria-checked`, operable with
                  Space, and it takes both its id and its `aria-describedby` from `<FormControl>` —
                  which is why neither is set by hand here and why `<FormLabel>` needs no `htmlFor`. */}
              <Switch
                checked={field.value === true}
                onCheckedChange={field.onChange}
                disabled={pending}
              />
            </FormControl>
            <div className="flex flex-col">
              <FormLabel>Available to bots</FormLabel>
              <FormDescription>
                A disabled row stays in this catalogue and stays listed — it is simply not offered.
              </FormDescription>
            </div>
            <FormMessage />
          </FormItem>
        )}
      />
    </>
  );
}

/**
 * Register a model under this connection — A BUTTON AND A DIALOG, not an inline card.
 *
 * ── WHY THIS IS A DIALOG WHEN THE SIBLING SCREEN'S CREATE FORM IS INLINE ───────────────────────
 * The accessible-name namespace, which is A3's rule applied structurally rather than by renaming.
 * Role- and label-name matching is a case-insensitive SUBSTRING in both Playwright and
 * vitest-browser, so two controls named "Display name" on one screen resolve to two elements and
 * every locator for either fails on strict mode — and a screen-reader user hits the same ambiguity
 * one control at a time. The connections screen solved its version of this by RENAMING the dialog's
 * field ("Connection label" against the create form's "Label"), which works for one field.
 *
 * This form has EIGHT fields in common with the edit dialog, and they are literally the same
 * component (`SharedModelFields`) because two copies of eight labelled controls is eight chances for
 * the create and edit screens to drift by a word — and the words are load-bearing, since a 422 is
 * keyed to a control by name. Renaming eight fields in one of the two copies would undo that.
 *
 * So the collision is removed by never mounting both at once: the create form is a dialog, the edit
 * form is a dialog, and at most one is open. The trigger, the two titles and the two submit buttons
 * are named so that no one of them CONTAINS another ("Register model" / "Register a model" /
 * "Save model" against "Edit model" / "Save changes").
 *
 * NOTHING HERE IS OPTIMISTIC and nothing writes the cache. The row the list must show is the one the
 * server derived: `id` and `created_at` are its, and the prices come back at the COLUMN's scale
 * (`'0.02'` in, `'0.020000'` out) rather than at the operator's.
 */
export function CreateModelDialog({
  orgId,
  connectionId,
  modelsKey,
}: {
  readonly orgId: string;
  readonly connectionId: string;
  /** The list's org-namespaced, connection-scoped key, built by `useOrgKey()` in the parent and
   *  passed down as an ARRAY — so this component holds no call site that could throw on a null
   *  organization, and so its invalidation cannot address a different cache entry from the one the
   *  list reads. */
  readonly modelsKey: readonly unknown[];
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [family, setFamily] = useState<CapabilityFamily>('chat');

  // THREE GENERICS, and here they genuinely differ: `ProviderModelCreateIn` is what the controls hold
  // (a price is a typed string) and `ProviderModelCreateOut` is what `handleSubmit` receives (an
  // exact decimal string, or null for an empty input). Writing the pair out is what keeps the submit
  // callback's type from being a lie.
  const form = useForm<ProviderModelCreateIn, unknown, ProviderModelCreateOut>({
    resolver: zodResolver(providerModelCreateSchema),
    defaultValues: providerModelCreateDefaults(),
    mode: 'onTouched',
  });

  const create = useMutation({
    mutationFn: (values: ProviderModelCreateOut) => createModel(orgId, connectionId, values),
    onSuccess: () => {
      // RESET BEFORE CLOSING, so re-opening starts empty rather than holding the row that was just
      // registered — the next act after registering one model is usually registering another, and a
      // pre-filled identifier is a duplicate 422 waiting to happen.
      form.reset(providerModelCreateDefaults());
      setFamily('chat');
      setOpen(false);
    },
    onError: (error) => {
      // A duplicate `(connection, model)` is a 422 KEYED ON `model` — an org-scoped pre-flight for
      // the readable message plus a catch of SQLSTATE 23505 for the race it cannot win — so it lands
      // under the input the operator just typed into. A 422 on `supported.3` has no control to land
      // on and reaches the banner; see `PROVIDER_MODEL_KNOWN_PATHS`.
      applyAuthError(form, PROVIDER_MODEL_KNOWN_PATHS, error);
    },
    onSettled: () => {
      // `onSettled` rather than `onSuccess`: a 422 for a duplicate identifier means the list in front
      // of the operator is ALSO out of date — somebody else registered that model.
      void queryClient.invalidateQueries({ queryKey: modelsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <>
      {/* The trigger's name CONTAINS neither submit button's and is contained by neither: "Register
          model" against "Save model" and "Save changes". See the docblock. */}
      <Button type="button" onClick={() => setOpen(true)}>
        Register model
      </Button>

      {/* THE RECEIPT LIVES OUTSIDE THE DIALOG, because the dialog closes on success and a
          confirmation inside it would render for no frames at all. It is INLINE and
          `aria-live="polite"` rather than a toast: a message that disappears is the wrong surface
          for one an administrator may want to re-read, and the polite region is what announces the
          write to a screen reader — the table below it is not a live region and its new row is
          silent. */}
      {create.isSuccess ? (
        <Alert aria-live="polite">
          <AlertDescription>Registered {create.data.model}. It is listed below.</AlertDescription>
        </Alert>
      ) : null}

      {/* Mounted only while open, so the screen carries no eight-field form state — and no dialog
          holding a half-typed identifier — while it is closed. */}
      {open ? (
        <Dialog open={open} onOpenChange={setOpen}>
          {/* Focus trapping, Escape, scroll lock, `inert` on the background and focus return to the
              trigger all come from the primitive. The scroll is on the CONTENT rather than the page,
              because nine fields exceed a 768px viewport. */}
          <DialogContent className="max-h-[85vh] overflow-y-auto">
            <Form {...form}>
              <form
                // POST, never the browser's default GET. Asserted for every form by
                // tests/unit/form-method.test.ts.
                method="post"
                onSubmit={form.handleSubmit((values) => {
                  create.mutate(values);
                })}
                // The browser's own validation bubbles would pre-empt the server's messages and
                // cannot be styled or read consistently by a screen reader.
                noValidate
                className="space-y-4"
              >
                <DialogHeader>
                  <DialogTitle>Register a model</DialogTitle>
                  <DialogDescription>
                    A catalogue row records what one model can do and what it costs. It designates
                    nothing and enables nothing on its own.
                  </DialogDescription>
                </DialogHeader>

                {rootError === undefined ? null : (
                  <Alert variant="destructive">
                    <AlertDescription>{rootError}</AlertDescription>
                  </Alert>
                )}

                <FormField
                  control={form.control}
                  name="model"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Model identifier</FormLabel>
                      <FormControl>
                        <Input
                          {...field}
                          autoComplete="off"
                          autoCapitalize="none"
                          spellCheck={false}
                          maxLength={NAME_MAX}
                          className="font-mono"
                          placeholder="gpt-5.6-sol"
                        />
                      </FormControl>
                      <FormDescription>
                        Exactly as the vendor publishes it. It cannot be changed later: it is half of
                        the vector-space identity for everything embedded through this row, so a
                        mistyped id is deleted and re-created.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                {/* THE ONE CAST IN THIS FILE — see `SharedForm`. */}
                <SharedModelFields
                  form={form as unknown as SharedForm}
                  family={family}
                  onFamilyChange={setFamily}
                  pending={create.isPending}
                />

                <DialogFooter>
                  <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={create.isPending}>
                    {create.isPending ? 'Registering…' : 'Save model'}
                  </Button>
                </DialogFooter>
              </form>
            </Form>
          </DialogContent>
        </Dialog>
      ) : null}
    </>
  );
}

/**
 * Replace one row's mutable attributes.
 *
 * A DIALOG rather than an inline row editor: eight controls, three of them needing a sentence of
 * explanation, do not fit in a table cell at 768px — and a `<fieldset>` inside a `<td>` is a control
 * nobody can hit.
 */
export function EditModelDialog({
  orgId,
  connectionId,
  modelsKey,
  row,
  open,
  onOpenChange,
}: {
  readonly orgId: string;
  readonly connectionId: string;
  readonly modelsKey: readonly unknown[];
  readonly row: ProviderModelResource;
  readonly open: boolean;
  readonly onOpenChange: (open: boolean) => void;
}) {
  const queryClient = useQueryClient();
  // Derived from the row's own flags, once, when the dialog mounts. An INCOHERENT row still resolves
  // to something rather than throwing, because the console has to be able to open and repair one.
  const [family, setFamily] = useState<CapabilityFamily>(() => familyOfRow(row.supported));

  const form = useForm<ProviderModelEditIn, unknown, ProviderModelEditOut>({
    resolver: zodResolver(providerModelEditSchema),
    // THE ONLY PATH FROM SERVER DATA INTO THIS FORM'S STATE, and it reaches exactly eight fields.
    // NOT `{...row}`: `reset`/`defaultValues` keep every key they are handed, so a spread would put
    // `id`, `connection_id`, `created_at` and — worse — `model` into form state, and a form holding
    // the identifier is one input away from offering to rename the vector space everything under
    // this row was embedded into. The factory's parameter type has eight members, so the spread does
    // not compile.
    defaultValues: providerModelEditDefaults(row),
    mode: 'onTouched',
  });

  const edit = useMutation({
    mutationFn: (values: ProviderModelEditOut) => updateModel(orgId, connectionId, row.id, values),
    onSuccess: () => {
      onOpenChange(false);
    },
    onError: (error) => {
      applyAuthError(form, PROVIDER_MODEL_EDIT_KNOWN_PATHS, error);
    },
    onSettled: () => {
      // `onSettled`, not `onSuccess`: a 422 or a 409 means the row in front of the operator is
      // stale, which is often the whole reason the server refused.
      void queryClient.invalidateQueries({ queryKey: modelsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Focus trapping, Escape, scroll lock, `inert` on the background and focus return to the
          trigger all come from the primitive. The scroll is on the CONTENT rather than the page,
          because eight fields exceed a 768px viewport and a dialog that scrolls the page behind it
          loses the operator's place. */}
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        <Form {...form}>
          <form
            method="post"
            onSubmit={form.handleSubmit((values) => {
              edit.mutate(values);
            })}
            noValidate
            className="space-y-4"
          >
            <DialogHeader>
              <DialogTitle>Edit model</DialogTitle>
              <DialogDescription>
                Every field is sent on save, because this endpoint replaces the row rather than
                patching it. The model identifier is not editable.
              </DialogDescription>
            </DialogHeader>

            {rootError === undefined ? null : (
              <Alert variant="destructive">
                <AlertDescription>{rootError}</AlertDescription>
              </Alert>
            )}

            {/* THE IMMUTABLE IDENTIFIER, RENDERED AS TEXT AND NEVER AS AN INPUT — structurally the
                same arrangement `masked_key` gets on the connections screen, for a different reason:
                it is not a secret, it is a value that must not change, and the way to make that true
                is for there to be no control for it. It is not in the schema, not in the defaults
                factory, and not in the request body. */}
            <Alert>
              <AlertTitle className="font-mono">{row.model}</AlertTitle>
              <AlertDescription>
                Fixed. Everything already embedded through this row is indexed under this identifier.
              </AlertDescription>
            </Alert>

            <SharedModelFields
              form={form}
              family={family}
              onFamilyChange={setFamily}
              pending={edit.isPending}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={edit.isPending}>
                {edit.isPending ? 'Saving…' : 'Save changes'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
