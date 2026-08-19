'use client';

import type { ProviderConnectionResource, ProviderKey } from '@kb/contracts';
import {
  PROVIDER_CONNECTION_STATUSES,
  providerConnectionEditDefaults,
  providerConnectionEditSchema,
  type ProviderConnectionEditIn,
  type ProviderConnectionEditOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef } from 'react';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { applyAuthError } from '@/features/auth/auth-error';
import {
  PROVIDER_CONNECTION_EDIT_KNOWN_PATHS,
  PROVIDER_CONNECTION_KNOWN_PATHS,
} from '@/features/auth/known-paths';
import { useCooldown } from '@/features/auth/use-cooldown';

import {
  connectionStatusLabel,
  createConnection,
  embeddingBlockedExplanation,
  providerLabel,
  updateConnection,
} from './api';

/**
 * The two forms that change a connection WITHOUT replacing its key. Rotation is a third form and lives
 * in `rotate-credential-dialog.tsx`, because it re-authenticates and its failure modes are its own.
 *
 * ── THE CREDENTIAL FIELD, WHICH IS THE REASON THIS SCREEN NEEDS CARE ─────────────────────────────
 * `masked_key` is `…` plus the last four characters of the stored key. It renders as TEXT, in the
 * table, and it is NEVER an input value. Three properties keep it that way, and none of them is
 * "remember not to":
 *
 *   1. THE CREATE FORM HAS NOTHING TO SEED FROM. It creates; there is no resource in scope.
 *   2. `credential` IS ABSENT FROM `defaultValues`. The input is `register`ed and uncontrolled, so
 *      form state acquires the value only when the user types it, and `reset()` clears it.
 *   2b. `credential` IS NOT A MUTATION VARIABLE EITHER, which is a separate cache from the one
 *      properties 1–3 are about and was the gap in this list. `mutate(values)` writes its argument to
 *      `Mutation.state.variables`; on @tanstack/react-query 5.101.4 a settled mutation survives its
 *      `gcTime` (5 minutes by default) after the last observer detaches, and this form stays mounted
 *      on the connections screen, so the key was retained for as long as the page was open. The
 *      plaintext key is one of the two things an XSS on `app.<domain>` does NOT otherwise get — no
 *      endpoint returns it — and `getMutationCache().getAll()[i].state.variables` handed it over.
 *      The credential now travels in a `useRef` read inside `mutationFn` and cleared in `onSettled`;
 *      the mutation's variable is the PROVIDER, which is a `<Select>` value and not a secret.
 *   3. THE EDIT FORM CANNOT EXPRESS THE FIELD AT ALL. Its resolver is
 *      `providerConnectionEditSchema` — a `strictObject` over `{label, status}` — and its defaults
 *      come from `providerConnectionEditDefaults`, whose parameter type has exactly two members. A
 *      `reset({...connection})` is a TYPECHECK FAILURE rather than a review question, which is the
 *      only version of this rule that survives a hurried edit.
 *
 * The failure it prevents: prefilling the input with `…4a91` posts that string as the new key, the
 * server stores it, and every provider call afterwards fails `provider_auth` — with a 200 on the
 * request that caused it. `RotateProviderCredentialRequest` carries a `not_regex:/^\x{2026}/u` rule
 * precisely because that is the obvious way to build this screen; nothing here relies on it.
 *
 * ── NO RESOLVER ON THE CREATE FORM, AND THAT IS THE SAME RULE THE INVITE FORM SHIPPED UNDER ──────
 * rhf-zod-forms NN3: a schema ships WITH a committed manifest and a drift test, or it does not ship.
 * `StoreProviderConnectionRequest` is a `NO_CLIENT_FORM` entry in `packages/contracts` — its body
 * carries the plaintext key, and an exported schema naming that field is one `defaults(resource)` away
 * from the bug above — so there is no shared schema to resolve against, and writing a LOCAL Zod copy
 * here would be a second, unmirrored, undrift-tested spelling of the server's rules. The controls carry
 * their own affordances instead (`maxLength`, a `<Select>` that cannot emit a value outside the
 * catalogue), and the FormRequest remains the authority it always was.
 *
 * The EDIT form does have a resolver, because its request is the one of the three that carries no
 * credential and therefore the one that could have a shared, drift-tested schema.
 */

/**
 * What the create form holds. `credential` is typed as a string and is absent from `defaultValues` —
 * see property 2 above. LOCAL to apps/web on purpose; `@kb/contracts` exports no type or schema that
 * names this field.
 */
interface CreateConnectionValues {
  readonly provider: ProviderKey;
  readonly label: string;
  readonly credential: string;
}

/**
 * The vendor list the `<Select>` offers. It is the `ProviderKey` union spelled as a tuple, and it is
 * DECLARED HERE rather than exported from `@kb/contracts` for the reason `src/resources/providers.ts`
 * gives: the root entry is types-only (a <=1 kB brotli budget inside apps/widget's app shell), so a
 * runtime vendor list would have to live behind `./forms` — and the only schema that would justify it
 * is the create schema, which does not exist and must not.
 *
 * `satisfies` rather than an annotation, so a member that is not a `ProviderKey` is a typecheck failure
 * here, and `PROVIDERS.length` is checked against the union's size by the component spec. A sixth
 * vendor added server-side lands in `ProviderKey` first (the OpenAPI drift suite in
 * `packages/contracts` is what catches THAT), and this list is where the console offers it.
 */
const PROVIDERS = [
  'openai',
  'anthropic',
  'deepseek',
  'nvidia_nim',
  'openrouter',
] as const satisfies readonly ProviderKey[];

/** `max:512` and `min:8` from the FormRequest — affordances on the control, never the authority. */
const CREDENTIAL_MIN = 8;
const CREDENTIAL_MAX = 512;
const LABEL_MAX = 120;

export function CreateConnectionForm({
  orgId,
  connectionsKey,
}: {
  /** The organization the POST addresses. A ROUTING HINT in the path — `TenantContext` re-reads the
   *  membership row per request and would ignore a client-supplied organization. */
  readonly orgId: string;
  /** The list's org-namespaced key, built by `useOrgKey()` in the parent. Passed down rather than
   *  rebuilt so the invalidation cannot address a different cache entry from the one the list reads,
   *  and so this component holds no second call site that could throw on a null organization. */
  readonly connectionsKey: readonly unknown[];
}) {
  const queryClient = useQueryClient();
  const cooldown = useCooldown();

  const form = useForm<CreateConnectionValues>({
    // `credential` IS DELIBERATELY ABSENT. Two fields, both non-secret. The credential input is
    // `register`ed below and starts empty because nothing seeded it — which is the whole rule,
    // expressed as the shape of this object rather than as a comment.
    defaultValues: { provider: 'openai', label: '' },
    mode: 'onTouched',
  });

  /**
   * THE ONE PLACE THE PLAINTEXT KEY LIVES BETWEEN SUBMIT AND RESPONSE — see property 2b in the file
   * header. A ref rather than the mutation variable, so nothing on the `QueryClient` ever holds it;
   * a ref rather than `useState`, so writing it schedules no render.
   *
   * `label` rides along because it is submitted in the same breath, not because it is sensitive.
   */
  const pending = useRef<CreateConnectionValues | null>(null);

  const create = useMutation({
    /**
     * THE VARIABLE IS THE PROVIDER AND NOTHING ELSE. It is a `<Select>` value from a five-member
     * union, it is not a secret, and `onSuccess` genuinely needs it — the vendor is the one field
     * kept across a save. Everything else is read off the ref.
     */
    mutationFn: (_provider: ProviderKey) => {
      const held = pending.current;
      // Unreachable: the submit handler writes the ref and calls `mutate` in the same synchronous
      // block, and `retry: false` is a client-wide default (lib/query/client.ts). Thrown rather than
      // asserted away, because a future retry would otherwise POST `undefined` as a credential and
      // store garbage under a label that looks correct. A non-`KbError` renders as the null-class
      // sentence, which is the honest reading of "we do not know what happened".
      if (held === null) throw new Error('connection submitted with no credential held');

      return createConnection(orgId, {
        provider: held.provider,
        label: held.label.trim(),
        // Read out of the ref and handed to `fetch`. It is never written back to form state, never
        // logged, never a mutation variable, and never read again.
        credential: held.credential,
      });
    },
    onSuccess: (_created, provider) => {
      // Clear the KEY and the LABEL, keep the vendor: adding two keys for one provider is one action
      // repeated. `reset` clears `credential` because it is registered — a field absent from
      // `defaultValues` resets to empty rather than to a remembered secret.
      form.reset({ provider, label: '' });
    },
    onError: (error) => {
      // The SHARED handler. `provider`, `label` and `credential` are all rendered, so a 422 on any of
      // them lands on its field — including `credential.min`/`max`, which is the one an operator most
      // needs beside the input they just pasted into.
      //
      // A 422 keyed on `models.*` cannot happen from this form (it posts `[]`) but would reach the
      // BANNER if it did: `PROVIDER_CONNECTION_KNOWN_PATHS` subtracts that sub-tree, because a message
      // written to a field that displays nowhere is a submit that changes nothing on screen.
      //
      // A 409 (the organization is suspended) has no class of its own and renders through
      // `endUserCopy` as the `internal_dependency` sentence. That is the known rough edge of an
      // unclassified 4xx and it is accepted here rather than papered over with a status branch: this
      // form never branches on a status, only on `error_class`.
      applyAuthError(form, PROVIDER_CONNECTION_KNOWN_PATHS, error, {
        onRateLimit: (seconds) => cooldown.start(seconds),
      });
    },
    onSettled: () => {
      // BOTH OUTCOMES: a 422 on the label leaves the form up with the key still typed into an
      // uncontrolled input, and there is nothing to gain by also holding it here. The submit handler
      // writes the ref again on the next attempt.
      pending.current = null;

      // NOT OPTIMISTIC, and not `setQueryData` either. The row the list must show is the one the
      // server derived: `masked_key` is computed from the sealed key and `created_at` is the
      // database's. `onSettled` rather than `onSuccess` because a 422 for a duplicate label means the
      // list is ALSO out of date.
      void queryClient.invalidateQueries({ queryKey: connectionsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;
  // The verdict that rides along on the 201. Null when the organization can embed.
  const blocked = create.data ? embeddingBlockedExplanation(create.data.embedding_readiness) : null;

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET. A GET fallback would put the CREDENTIAL in a query
        // string — in the address bar, in history, and in every access log between here and Laravel.
        // Asserted for every form by tests/unit/form-method.test.ts.
        method="post"
        // `handleSubmit` is INVOKED INSIDE the event handler rather than called during render, and
        // that is not a style choice: `react-hooks/refs` rejects a render-time closure that writes a
        // ref, because it cannot tell the closure will only ever run from an event. This shape puts
        // the write where it demonstrably belongs.
        onSubmit={(event) => {
          void form.handleSubmit((values) => {
            // The handoff: the ref, then the PROVIDER as the variable. Passing `values` to `mutate`
            // here is the one-word change that puts the plaintext key back in the mutation cache.
            pending.current = values;
            create.mutate(values.provider);
          })(event);
        }}
        // The browser's own validation bubbles would pre-empt the server's messages and cannot be
        // styled or read consistently by a screen reader.
        noValidate
        className="space-y-4"
      >
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        {/* The confirmation is INLINE and `aria-live="polite"`, not a toast: a message that
            disappears is the wrong surface for one an administrator may want to re-read — and this
            one carries the mask, which is the only receipt they will ever get for the key they just
            pasted. */}
        {create.isSuccess ? (
          <Alert aria-live="polite">
            <AlertDescription>
              Stored {providerLabel(create.data.data.provider)} key{' '}
              <span className="font-mono">{create.data.data.masked_key}</span> as{' '}
              {create.data.data.label}.
            </AlertDescription>
          </Alert>
        ) : null}

        {/* ── THE READINESS VERDICT, RENDERED VERBATIM ───────────────────────────────────────────
            `POST /provider-connections` returns the organization's embedding readiness AFTER the
            write, as a second top-level key, and this is what it is for: a green save on an
            organization that still cannot ingest anything is a state the operator finds out about at
            their first upload otherwise.

            `explanation` is the DATA PLANE'S OWN WORDS and the upload path raises the same string, so
            the banner and the eventual error say the same thing. It is not an error envelope and it
            arrives on a 201 — there is no `error_class` to map and nothing to paraphrase. */}
        {blocked === null ? null : (
          <Alert variant="warning" aria-live="polite">
            <AlertTitle>This organization still cannot index documents</AlertTitle>
            <AlertDescription>{blocked}</AlertDescription>
          </Alert>
        )}

        <FormField
          control={form.control}
          name="provider"
          render={({ field }) => (
            <FormItem>
              <FormLabel htmlFor="connection-provider">Provider</FormLabel>
              {/* A Radix Select has no `register` ref, so `applyServerErrors`' `hasFocusableRef`
                  returns false for this path and never spends `shouldFocus` on it — the message still
                  renders through FormMessage below. */}
              <Select value={field.value} onValueChange={field.onChange}>
                <FormControl>
                  <SelectTrigger id="connection-provider" aria-label="Provider" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  {PROVIDERS.map((provider) => (
                    <SelectItem key={provider} value={provider}>
                      {providerLabel(provider)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FormDescription>
                The vendor is fixed once the connection exists: it is half of the vector-space
                identity for everything embedded through it.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="label"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Label</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  autoComplete="off"
                  // Mirrors `max:120`. An affordance: the control refuses the 121st character rather
                  // than the form refusing the submit.
                  maxLength={LABEL_MAX}
                  placeholder="Production key"
                />
              </FormControl>
              <FormDescription>
                How this key is named in the console. Visible to everyone in the organization.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="credential"
          render={({ field }) => (
            <FormItem>
              <FormLabel>API key</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  // `undefined` -> ''. `credential` is absent from `defaultValues` (that is the
                  // rule), so `field.value` starts undefined and React would treat the input as
                  // UNCONTROLLED, then warn and re-mount it on the first keystroke. An empty string is
                  // not a seeded secret; it is what "nothing has been typed" looks like to React.
                  value={field.value ?? ''}
                  // WRITE-ONLY. `type="password"` keeps it out of a shoulder-surfer's view and out of
                  // most screen-recording tools; `autoComplete="new-password"` is what stops a
                  // password manager offering to fill — or to SAVE — a provider key under this
                  // origin, which `off` alone does not reliably do in Chrome.
                  type="password"
                  autoComplete="new-password"
                  autoCapitalize="none"
                  spellCheck={false}
                  // `data-1p-ignore`/`data-lpignore` ask 1Password and LastPass not to inject an
                  // icon into a field that is not an account credential. Ignored where unsupported.
                  data-1p-ignore
                  data-lpignore="true"
                  minLength={CREDENTIAL_MIN}
                  maxLength={CREDENTIAL_MAX}
                  placeholder="Paste the key from the provider"
                />
              </FormControl>
              <FormDescription>
                Encrypted before it is stored. Afterwards only the last four characters are ever shown
                — paste the whole key, never the masked value.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={create.isPending || cooling}>
          {create.isPending ? 'Storing…' : 'Store connection'}
        </Button>

        {/* polite, not assertive: it updates once a second and must not interrupt whatever the user is
            doing. `retry_after` is seconds off the `Retry-After` RESPONSE HEADER; a 429 without it
            degrades this to no cooldown rather than to a guessed one. */}
        <p aria-live="polite" className="text-muted-foreground text-sm">
          {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
        </p>
      </form>
    </Form>
  );
}

/**
 * Rename a connection, or move its lifecycle status. NO CREDENTIAL — see the module docblock, and note
 * that this component could not send one if it wanted to: the schema is a `strictObject` over two keys
 * and the endpoint declares no such rule.
 *
 * A DIALOG rather than an inline row editor, because the status field needs its own explanation and a
 * `<Select>` inside a table cell on a 768px viewport is a control nobody can hit.
 */
export function EditConnectionDialog({
  orgId,
  connectionsKey,
  connection,
  open,
  onOpenChange,
}: {
  readonly orgId: string;
  readonly connectionsKey: readonly unknown[];
  readonly connection: ProviderConnectionResource;
  readonly open: boolean;
  readonly onOpenChange: (open: boolean) => void;
}) {
  const queryClient = useQueryClient();

  // THREE GENERICS: `ProviderConnectionEditIn` is what the controls hold and
  // `ProviderConnectionEditOut` is what `handleSubmit` receives. They are structurally the same here
  // (no preprocess on either field), and the pair is written out anyway so a future `z.preprocess`
  // cannot silently make the submit callback's type a lie.
  const form = useForm<ProviderConnectionEditIn, unknown, ProviderConnectionEditOut>({
    resolver: zodResolver(providerConnectionEditSchema),
    // THE ONLY PATH FROM SERVER DATA INTO THIS FORM'S STATE, and it reaches exactly two fields. Not
    // `{...connection}`: `reset`/`defaultValues` keep every key they are handed, so a spread would put
    // `masked_key` in form state — and the day this form grows a third input, the shape it would fill
    // it from is already there. The factory's parameter type has two members, so the spread does not
    // compile.
    defaultValues: providerConnectionEditDefaults(connection),
    mode: 'onTouched',
  });

  const edit = useMutation({
    mutationFn: (values: ProviderConnectionEditOut) =>
      updateConnection(orgId, connection.id, values),
    onSuccess: () => {
      onOpenChange(false);
    },
    onError: (error) => {
      applyAuthError(form, PROVIDER_CONNECTION_EDIT_KNOWN_PATHS, error);
    },
    onSettled: () => {
      // `onSettled`, not `onSuccess`: a 422 or a 409 means the row in front of the user is stale,
      // which is often the whole reason the server refused.
      void queryClient.invalidateQueries({ queryKey: connectionsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Focus trapping, Escape, scroll lock, `inert` on the background and focus return to the
          trigger all come from the primitive. */}
      <DialogContent>
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
              <DialogTitle>Edit connection</DialogTitle>
              <DialogDescription>
                The vendor and the key itself are not editable here. Replacing the key is a separate
                action that asks for your password.
              </DialogDescription>
            </DialogHeader>

            {rootError === undefined ? null : (
              <Alert variant="destructive">
                <AlertDescription>{rootError}</AlertDescription>
              </Alert>
            )}

            <FormField
              control={form.control}
              name="label"
              render={({ field }) => (
                <FormItem>
                  {/* "Connection label" and not "Label", and the reason is an accessible-name
                      NAMESPACE rather than copy taste: this dialog opens over the create form, which
                      has its own "Label" input, and accessible-name matching is a case-insensitive
                      SUBSTRING in both Playwright and vitest-browser — so two controls named "Label"
                      on one screen resolve to two elements and every locator for either fails on
                      strict mode. A screen reader user hits the same ambiguity, one control at a
                      time. The same rule produced "Resend to …" on the members screen. */}
                  <FormLabel>Connection label</FormLabel>
                  <FormControl>
                    <Input
                      {...field}
                      // `string | undefined` -> string. The field is optional in the schema because
                      // the endpoint is a PATCH, and an `undefined` value on an <input> makes it
                      // uncontrolled — React then warns on the first keystroke and the value is lost.
                      value={field.value ?? ''}
                      autoComplete="off"
                      maxLength={LABEL_MAX}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="status"
              render={({ field }) => (
                <FormItem>
                  <FormLabel htmlFor="connection-status">Status</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger id="connection-status" aria-label="Status" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {/* From `@kb/contracts/forms`, which is the same tuple the resolver's enum is
                          built from — two spellings of one list is how an option that cannot be
                          submitted gets rendered. */}
                      {PROVIDER_CONNECTION_STATUSES.map((status) => (
                        <SelectItem key={status} value={status}>
                          {connectionStatusLabel(status)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription>
                    A declared intent, not a test result: marking a key active does not check it, and
                    marking it revoked does not revoke it at the provider.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
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
