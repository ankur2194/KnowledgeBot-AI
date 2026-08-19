'use client';

import type { ProviderConnectionResource } from '@kb/contracts';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef } from 'react';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { applyAuthError } from '@/features/auth/auth-error';
import { ROTATE_CREDENTIAL_KNOWN_PATHS } from '@/features/auth/known-paths';
import { useCooldown } from '@/features/auth/use-cooldown';

import { rotateCredential } from './api';

/**
 * Replace the secret stored on one connection — `PUT …/provider-connections/{id}/credential`.
 *
 * ── IT IS ITS OWN DIALOG BECAUSE IT IS ITS OWN ENDPOINT, AND THAT IS A SECURITY CONTROL ─────────
 * Folding rotation into the edit form would mean one route, one permission and one re-authentication
 * policy covering both a relabel and a credential replacement — and the weaker of each pair would win.
 * The server split them for that reason; the console renders the split rather than hiding it.
 *
 * ── §18.3: A DESTRUCTIVE ACTION RE-AUTHENTICATES ─────────────────────────────────────────────────
 * Rotation breaks every live bot on this provider the instant it commits, so the server demands the
 * actor's CURRENT PASSWORD rather than the age of a session. `current_password:web` is a VALIDATION
 * rule, so it runs before any row is read: a wrong password is a 422 keyed on `current_password` and
 * the stored credential is untouched — by construction, not by ordering discipline.
 *
 * That field is why this dialog is not `<ConfirmDestructiveDialog>`. Typing a resource name is the
 * confirmation gate for an irreversible action the SERVER cannot re-check; here the server performs a
 * real re-authentication, and asking for the connection's name as well would be a second speed bump in
 * front of a real gate.
 *
 * ── NO RESOLVER, AND NO SHARED SCHEMA, DELIBERATELY ─────────────────────────────────────────────
 * `RotateProviderCredentialRequest` is a `NO_CLIENT_FORM` entry in `packages/contracts`: its body
 * carries the plaintext key AND the actor's password, and an exported schema naming both is a shape
 * apps/mobile and apps/widget would import by default for no benefit. Of its four mirrorable rules,
 * `current_password:web` is SERVER_ONLY (it needs the session and a stored hash) and
 * `not_regex:/^\x{2026}/u` guards a mistake this dialog cannot make — nothing seeds either input, and
 * `masked_key` is rendered as text OUTSIDE the form. What is left is two length bounds, which the
 * controls carry as `minLength`/`maxLength`.
 *
 * ── NEITHER FIELD IS IN `defaultValues`, AND NEITHER IS A MUTATION VARIABLE ─────────────────────
 * `useForm` is given NO `defaultValues` at all: both inputs are registered and uncontrolled, so form
 * state acquires a value only when the user types one, and the dialog resets on close.
 *
 * THIS BLOCK USED TO SAY "Nothing here reaches `queryClient`, so no secret enters the query cache",
 * AND THAT WAS TRUE OF THE WRONG CACHE. There are two on one `QueryClient`. Nothing reaches the QUERY
 * cache — the response carries no part of either key and `onSettled` invalidates rather than patches.
 * But `mutate(values)` writes its argument to `Mutation.state.variables` in the MUTATION cache, which
 * lives on the same client and is reachable from the same handle. On @tanstack/react-query 5.101.4 a
 * settled mutation is removed only after `gcTime` (5 minutes by default) once its last observer
 * detaches — and while this dialog stays mounted the observer never detaches, so it is retained for as
 * long as the row is on screen. `form.reset()` clears RHF's `_formValues` and does nothing to it.
 *
 * WHY THAT MATTERS EVEN THOUGH AN XSS ALREADY OWNS THE SESSION: an attacker who can run script on
 * `app.<domain>` can already drive the API as this admin, but that does NOT normally yield the
 * plaintext provider key or the actor's account password, because no endpoint returns either. Walking
 * `queryClient.getMutationCache().getAll()[i].state.variables` would have yielded both. There is no
 * devtools and no persister in this tree today; adding either later would have moved the pair to
 * disk with nothing objecting.
 *
 * SO THE SECRETS ARE NOT THE MUTATION VARIABLE. They are held in a `useRef` for the duration of one
 * in-flight request, read inside `mutationFn`, and dropped in `onSettled`. The mutation's variable
 * type is `void`, so there is nothing for the cache to keep. `gcTime: 0` would have shortened the
 * window rather than closing it — the value is still written, still readable while the request is in
 * flight, and still one config edit away from coming back — so it is not the fix and is not used here.
 */
interface RotationValues {
  readonly credential: string;
  readonly current_password: string;
}

const CREDENTIAL_MIN = 8;
const CREDENTIAL_MAX = 512;

export function RotateCredentialDialog({
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
  const cooldown = useCooldown();

  const form = useForm<RotationValues>({ mode: 'onTouched' });

  /**
   * THE ONE PLACE EITHER SECRET LIVES BETWEEN SUBMIT AND RESPONSE. A ref and not the mutation
   * variable, for the reason the file header gives; a ref and not `useState` because writing it must
   * not schedule a render, and because its lifetime is one request rather than one frame.
   */
  const secrets = useRef<RotationValues | null>(null);

  const rotate = useMutation({
    // NO PARAMETER, which is the whole control: `mutate()` is called with nothing, so
    // `Mutation.state.variables` is `undefined` and the mutation cache has no secret to retain.
    mutationFn: () => {
      const held = secrets.current;
      // Unreachable: the submit handler writes the ref and calls `mutate()` in the same synchronous
      // block, and `retry: false` is a client-wide default (lib/query/client.ts), so `mutationFn`
      // never runs a second time against a cleared ref. Thrown rather than asserted away, because a
      // future retry would otherwise send `undefined` as a credential and rotate the key to garbage.
      // A non-`KbError` lands on the banner as the null-class sentence, which is the honest rendering
      // of "we do not know what happened" (kb-error-taxonomy).
      if (held === null) throw new Error('rotation submitted with no credential held');

      return rotateCredential(orgId, connection.id, {
        credential: held.credential,
        current_password: held.current_password,
      });
    },
    onSuccess: () => {
      // RESET BEFORE CLOSING, and the order is the point: `reset()` drops both secrets out of form
      // state, and closing the dialog unmounts the inputs. A dialog closed without a reset keeps the
      // password in `_formValues` for as long as the row is mounted.
      form.reset({ credential: '', current_password: '' });
      onOpenChange(false);
    },
    onError: (error) => {
      // Both fields are rendered, so both 422s land where they belong: "That password is not correct"
      // under the password input, and "That looks like the masked display value" under the key. Routed
      // to a banner instead, the first reads as a general failure and the operator retypes the same
      // password.
      //
      // `throttle:credential-rotation` is a SECOND limiter on this endpoint — the group's 120/min
      // would otherwise be 120 password guesses a minute against a `current_password` rule, which is
      // what makes the re-authentication mean anything. So a 429 here is expected and gets the
      // cooldown.
      applyAuthError(form, ROTATE_CREDENTIAL_KNOWN_PATHS, error, {
        onRateLimit: (seconds) => cooldown.start(seconds),
      });
    },
    onSettled: () => {
      // BOTH OUTCOMES, which is why it is here and not in `onSuccess`: a 422 on the password leaves
      // the dialog open and the request finished, and holding either secret past that point buys
      // nothing. The user retypes and the submit handler writes the ref again.
      secrets.current = null;

      // The only visible change is the last four characters of `masked_key`, and it is re-read rather
      // than patched: the response carries no part of either key, and the row the list must show is
      // the one the server derived.
      void queryClient.invalidateQueries({ queryKey: connectionsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Reset on close so a re-opened dialog never arrives holding a secret from an abandoned
        // attempt — including one abandoned with Escape. The ref is cleared alongside form state.
        // `onSettled` is what normally empties it and this is belt-and-braces: it covers a dialog
        // dismissed between submit and response, where the request settles into an unmounted tree.
        if (!next) {
          secrets.current = null;
          form.reset({ credential: '', current_password: '' });
        }
        onOpenChange(next);
      }}
    >
      <DialogContent>
        <Form {...form}>
          <form
            method="post"
            // `handleSubmit` is INVOKED INSIDE the event handler rather than called during render,
            // and that is not a style choice: `react-hooks/refs` rejects a render-time closure that
            // writes a ref, because it cannot tell the closure will only ever run from an event. This
            // shape puts the write where it demonstrably belongs.
            onSubmit={(event) => {
              void form.handleSubmit((values) => {
                // The handoff: the ref, then `mutate()` with NO argument. Passing `values` here is
                // the one-word change that puts both secrets back in the mutation cache.
                secrets.current = values;
                rotate.mutate();
              })(event);
            }}
            noValidate
            className="space-y-4"
          >
            <DialogHeader>
              <DialogTitle>Replace the key for {connection.label}</DialogTitle>
              <DialogDescription>
                Every bot using this connection switches to the new key the moment this is saved. The
                old key is gone and cannot be recovered from here.
              </DialogDescription>
            </DialogHeader>

            {rootError === undefined ? null : (
              <Alert variant="destructive">
                <AlertDescription>{rootError}</AlertDescription>
              </Alert>
            )}

            {/* THE MASK IS RENDERED AS TEXT, OUTSIDE THE FORM, and this is the one place on the screen
                where it sits next to a credential input — which is exactly the arrangement that
                produces the seeding bug. It is a <p>, not a value: there is no code path from this
                string into `credential`, because nothing in this component reads it. */}
            <p className="text-sm text-muted-foreground">
              Currently ending in <span className="font-mono">{connection.masked_key}</span>. Paste the
              new key in full — the masked value cannot authenticate anything.
            </p>

            <FormField
              control={form.control}
              name="credential"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>New API key</FormLabel>
                  <FormControl>
                    <Input
                      {...field}
                      value={field.value ?? ''}
                      type="password"
                      autoComplete="new-password"
                      autoCapitalize="none"
                      spellCheck={false}
                      data-1p-ignore
                      data-lpignore="true"
                      minLength={CREDENTIAL_MIN}
                      maxLength={CREDENTIAL_MAX}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="current_password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Your password</FormLabel>
                  <FormControl>
                    <Input
                      {...field}
                      value={field.value ?? ''}
                      type="password"
                      // `current-password` HERE and `new-password` above, and the difference is not
                      // cosmetic: this one IS the actor's account password, so a manager filling it is
                      // correct and helpful, while filling the provider key from a saved password
                      // would be silently wrong.
                      autoComplete="current-password"
                      autoCapitalize="none"
                      spellCheck={false}
                    />
                  </FormControl>
                  <FormDescription>
                    Replacing a provider key breaks every bot using it, so it needs your password and
                    not just a signed-in session.
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              {/* The verb in the button matches the verb in the title. Not "OK", not "Confirm". */}
              <Button type="submit" variant="destructive" disabled={rotate.isPending || cooling}>
                {rotate.isPending ? 'Replacing…' : 'Replace key'}
              </Button>
            </DialogFooter>

            <p aria-live="polite" className="text-muted-foreground text-sm">
              {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
            </p>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
