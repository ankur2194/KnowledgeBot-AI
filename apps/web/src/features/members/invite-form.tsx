'use client';

import type { InvitationResource, Role } from '@kb/contracts';
import {
  inviteMemberFormDefaults,
  inviteMemberSchema,
  ORG_ROLES,
  type InviteMemberIn,
  type InviteMemberOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { INVITE_KNOWN_PATHS } from '@/features/auth/known-paths';
import { OWNER_INVITE_NOTE, roleLabel } from '@/features/auth/roles';
import { SESSION_KEY } from '@/features/auth/session';
import { useCooldown } from '@/features/auth/use-cooldown';
import { asText } from '@/lib/forms/as-text';

import { createInvitation } from './api';

/**
 * Invite somebody into the current organization. `{email, role}`, mirroring
 * `App\Http\Requests\StoreInvitationRequest`.
 *
 * ── IT SHIPPED WITHOUT A SCHEMA ONCE, AND THE RULE THAT FORBADE ONE IS WHY IT HAS ONE NOW ─────────
 * `rhf-zod-forms` NN3 is "a schema ships with a committed rule manifest and a drift test, or it does not
 * ship". `packages/contracts/rules/StoreInvitationRequest.json` had not been dumped when this form was
 * written, so a Zod copy written HERE could satisfy neither half — it would have been a second,
 * unmirrored, undrift-tested spelling of the server's rules, which is exactly what the manifest exists
 * to prevent. So the form shipped with NO RESOLVER and reported the seam.
 *
 * Both halves have since landed, in the order the rule requires: the manifest was dumped, then
 * `@kb/contracts/forms` gained `inviteMemberSchema` and `ORG_ROLES`, then `form-drift.test.ts` gained
 * the `MIRRORS` entry that compares them. Mutation-verified in both directions — dropping a role from
 * the tuple and narrowing `max:254` to 200 each fail the contracts suite by name — so the resolver
 * below is a mirror the suite proves rather than a second opinion.
 *
 * The schema does NOT become the control. Client validation is a UX affordance (NN2); the FormRequest
 * remains the only authority, and the affordances a control carries on its own are kept alongside it:
 * `type="email"`, `maxLength`, and a `<Select>` that cannot emit a value outside the catalog.
 *
 * ONE RULE IS STILL THE SERVER'S ALONE, and it is the interesting one: `email` is VALUE_EXEMPT in the
 * drift harness because Laravel's `email:rfc,strict` is egulias/EmailValidator and `z.email()` is a
 * regex, and the two will never agree on the edge corpus. So an address both accept is caught here, an
 * address only egulias rejects still comes back as a 422 on this field, and `max:254` is probed against
 * the manifest's own number rather than trusted. The exemption covers the FORMAT, never the length.
 *
 * ── THE ONE SERVER BEHAVIOUR THAT IS INVISIBLE IN A MANIFEST ─────────────────────────────────────
 * `prepareForValidation()` lowercases AND trims `email`, and it must: `organization_invitations_email_lowercase`
 * is a database CHECK, so a mixed-case address would fail the INSERT with a constraint name and the
 * administrator would see a 500 for typing `Bob@x.com`. It is not a `lowercase` VALIDATION rule on
 * purpose — that rule REJECTS a mixed-case address instead of accepting it.
 *
 * Note the disagreement between two docblocks, resolved here in favour of the second:
 * `StoreInvitationRequest` says "the Zod mirror must lowercase and trim on its own side", while
 * `packages/contracts/src/forms/auth.ts`'s `emailField` deliberately does NOT lowercase, because
 * "normalisation is not validation: the server does it unconditionally, so a mixed-case address submitted
 * verbatim works, and lowercasing client-side would only mean the user watches their own input change."
 * This form trims (parity with the body the server will validate) and does not lowercase (nothing
 * observable changes, and the server is unconditional). Flagged rather than settled silently.
 */
export function InviteForm({
  orgId,
  invitationsKey,
  viewerRole,
}: {
  /** The organization the POST addresses. A ROUTING HINT in the path — `TenantContext` re-reads the
   *  membership row per request and would ignore a client-supplied organization. */
  readonly orgId: string;
  /** The invitations list's org-namespaced key, built by `useOrgKey()` in the parent. Passed down rather
   *  than rebuilt so the invalidation cannot address a different cache entry from the one the list reads,
   *  and so this component holds no second call site that could throw on a null organization. */
  readonly invitationsKey: readonly unknown[];
  /** The role the VIEWER holds here, used to hide one option. An affordance, never authorization. */
  readonly viewerRole: Role | null;
}) {
  const queryClient = useQueryClient();
  const cooldown = useCooldown();

  // THREE GENERICS, and now the first and third genuinely differ: `InviteMemberIn` is what the controls
  // hold, `InviteMemberOut` is what `handleSubmit` receives after `emailField`'s `z.preprocess` trim has
  // run. Writing `InviteMemberIn` in the third slot would compile and would quietly claim the submit
  // callback sees untrimmed input.
  const form = useForm<InviteMemberIn, unknown, InviteMemberOut>({
    resolver: zodResolver(inviteMemberSchema),
    // From the SCHEMA's factory, not a local literal: `analyst` is the least-privileged role and the
    // only default that is never refused (`owner` 403s for every admin who is not one), and that
    // reasoning now lives beside the enum it picks from rather than in two files.
    defaultValues: inviteMemberFormDefaults(),
    mode: 'onTouched',
  });

  const invite = useMutation<InvitationResource, Error, InviteMemberOut>({
    mutationFn: (values) =>
      createInvitation(orgId, {
        // NO `.trim()` HERE ANY MORE, and its absence is the schema doing the job: `emailField`'s
        // `z.preprocess` trims before the check, so `InviteMemberOut.email` is already trimmed and a
        // second trim would be a silent claim that it is not. NOT lowercased — see the class docblock.
        email: values.email,
        role: values.role,
      }),
    onSuccess: (_invitation, values) => {
      // Clear the address, KEEP the role: inviting three analysts is one action repeated, and re-picking
      // the role each time is the kind of friction that gets somebody promoted to admin by accident.
      form.reset({ email: '', role: values.role });
    },
    onError: (error) => {
      // The SHARED handler. `email` and `role` are both rendered, so a 422 on either lands on the field:
      // "An invitation for this address is already pending. Resend it instead." and "This person is
      // already a member of this organization." are both keyed `email` and are both Laravel-translated
      // end-user copy, shown verbatim.
      //
      // A 403 is the owner-escalation guard (`Gate::authorize('inviteOwner')`) or a role that changed
      // under us, and it falls through to the banner with a class-mapped sentence: branching on the
      // STATUS would be branching on the wrong variable, since one class renders several statuses.
      //
      // A SUSPENDED ORGANIZATION IS A 422 ON `organization`, NOT A 409. It used to be a 409, which has no
      // class of its own and rendered as "Something on our side is unavailable. Try again shortly." —
      // false twice: nothing is unavailable, and retrying never works while the org is suspended. The
      // key is `organization`, which is not in INVITE_KNOWN_PATHS, so the server's true sentence reaches
      // the banner through `root.serverError` instead of a class-mapped guess.
      applyAuthError(form, INVITE_KNOWN_PATHS, error, {
        onRateLimit: (seconds) => cooldown.start(seconds),
        onAuthorization: () => {
          // The stale-role case: the select offered `owner` because the cached session said we are one.
          // Refetching identity is what stops the next attempt from failing the same way. It is a no-op
          // when the 403 really was the escalation guard, and one `/me` is cheap next to a control that
          // keeps refusing for a reason the screen cannot see.
          void queryClient.invalidateQueries({ queryKey: SESSION_KEY });
        },
      });
    },
    onSettled: () => {
      // NOT OPTIMISTIC, and not `setQueryData` either. The row the list must show is the one the server
      // derived — `status` is computed from three timestamps and `expires_at` comes from
      // `config('kb.invitation_ttl_hours')`, neither of which the browser can compute. `onSettled` rather
      // than `onSuccess` because a 422 for "already pending" means the list is ALSO out of date: there is
      // a row there we are not rendering.
      void queryClient.invalidateQueries({ queryKey: invitationsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;
  const cooling = cooldown.remaining > 0;
  // Server-verified, not inferred: `Gate::authorize('inviteOwner')` is a SECOND permission
  // (`members.manage_owner`) that only `owner` holds, because creating another owner is the one act the
  // role performing it cannot undo. Hiding the option removes a choice that would 403; it does not make
  // the decision, which is why the class-mapped 403 path above still exists.
  const offerableRoles = viewerRole === 'owner' ? ORG_ROLES : ORG_ROLES.filter((r) => r !== 'owner');

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET — see features/auth/login-form.tsx for the full
        // account. This form sits behind a session, so its native fallback would put a colleague's
        // address in an admin's history. Asserted for every form by tests/unit/form-method.test.ts.
        method="post"
        onSubmit={form.handleSubmit((values) => {
          invite.mutate(values);
        })}
        // The browser's own validation bubbles would pre-empt the server's messages and cannot be styled
        // or read consistently by a screen reader. `type="email"` still contributes the right keyboard on
        // a phone, which is the half of that attribute worth having.
        noValidate
        className="space-y-4"
      >
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        {/* The confirmation is INLINE and `aria-live="polite"`, not a toast: `sonner` is a real new
            runtime dependency this workspace does not carry, and a message that disappears is the wrong
            surface for one an administrator may want to re-read. It shows the address the SERVER
            normalised, which is how "Bob@X.com" visibly becomes the row that will be listed. */}
        {invite.isSuccess ? (
          <Alert aria-live="polite">
            <AlertDescription>Invitation sent to {invite.data.email}.</AlertDescription>
          </Alert>
        ) : null}

        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  // `unknown` -> string. `emailField` is a `z.preprocess`, so the schema's INPUT type
                  // for this field is `unknown` while its output is a string; the `''` fallback is also
                  // what keeps the input CONTROLLED across the `form.reset()` that runs after a
                  // successful send. See @/lib/forms/as-text.
                  value={asText(field.value)}
                  type="email"
                  autoComplete="off"
                  autoCapitalize="none"
                  spellCheck={false}
                  // Mirrors `max:254`, which is the RFC 5321 maximum for a forward path. An affordance:
                  // the control refuses the 255th character rather than the form refusing the submit.
                  maxLength={254}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="role"
          render={({ field }) => (
            <FormItem>
              <FormLabel htmlFor="invite-role">Role</FormLabel>
              {/* A Radix Select has no `register` ref, so `applyServerErrors`' `hasFocusableRef` returns
                  false for this path and never spends `shouldFocus` on it — the message still renders
                  through FormMessage below. That is the documented reason the check exists. */}
              <Select value={field.value} onValueChange={field.onChange}>
                <FormControl>
                  <SelectTrigger id="invite-role" aria-label="Role" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  {offerableRoles.map((role) => (
                    <SelectItem key={role} value={role}>
                      {roleLabel(role)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FormDescription>{OWNER_INVITE_NOTE}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={invite.isPending || cooling}>
          {invite.isPending ? 'Sending…' : 'Send invitation'}
        </Button>

        {/* polite, not assertive: it updates once a second and must not interrupt whatever the user is
            doing. `retry_after` is seconds off the `Retry-After` RESPONSE HEADER; a 429 without it
            degrades this to no cooldown rather than to a guessed one, because submitting inside the
            window we were told to wait keeps the limiter rejecting. */}
        <p aria-live="polite" className="text-muted-foreground text-sm">
          {cooling ? `Try again in ${cooldown.remaining}s.` : ''}
        </p>
      </form>
    </Form>
  );
}

/*
 * `InviteValues` USED TO BE DECLARED HERE, and `INVITE_KNOWN_PATHS` used to be the hand-typed
 * `['email', 'role']` beneath it. Both are gone to their real sources: the field types are
 * `InviteMemberIn`/`InviteMemberOut` off the schema, and the path set is
 * `knownPathsFromRules(StoreInvitationRequest.json)` in `@/features/auth/known-paths`. Neither can now
 * disagree with the server without something going red — which the typed pair could, silently, in the
 * one direction that matters (the server ADDING a field this form does not render).
 */
