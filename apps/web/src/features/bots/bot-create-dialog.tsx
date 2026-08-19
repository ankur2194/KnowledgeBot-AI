'use client';

import type { Role } from '@kb/contracts';
import {
  botCreateDefaults,
  botCreateSchema,
  type BotCreateIn,
  type BotCreateOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { PlusIcon } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import { applyAuthError } from '@/features/auth/auth-error';
import { useCurrentOrgId, useSession } from '@/features/auth/session-context';
import { asText } from '@/lib/forms/as-text';
import { orgKey } from '@/lib/query/client';

import { BOT_CREATE_KNOWN_PATHS, canManageBots, createBot } from './api';

/**
 * CREATE ONE BOT — a button and a dialog, mounted twice on `/bots` and never open twice.
 *
 * ── WHY THIS COMPONENT RESOLVES ITS OWN ORGANIZATION INSTEAD OF TAKING IT AS A PROP ─────────────
 * Its two mount points are on opposite sides of the RSC boundary. The page header's action is a prop
 * of a SERVER component (`app/(admin)/bots/page.tsx`), which has no organization and must never
 * acquire one — the org lives in the session cookie and is in none of Next's five cache keys, so a
 * server component that read it would be producing an organization-scoped byte. The first-run empty
 * state's action is deep inside a client subtree that already has it. A prop would work for one and
 * be unavailable to the other, so both mount the same self-contained component and it reads the
 * session itself.
 *
 * The consequence is the guard below: the org can be `null` — login succeeds with
 * `current_organization_id: null` for a user whose memberships are all `invited` or `suspended` — so
 * the whole control is gated on a mount condition rather than on an `enabled` flag. `orgKey` would
 * otherwise be handed `undefined` and produce ONE cache namespace shared by every org-less state on
 * the platform.
 *
 * ── TWO TRIGGERS, TWO NAMES, ONE DIALOG SHAPE ───────────────────────────────────────────────────
 * When the list is empty BOTH triggers are on screen at once: "Add bot" in the page header and
 * "Create your first bot" in the empty state. Neither name CONTAINS the other, and neither contains
 * the dialog's submit ("Save bot") or its title ("Create a bot"). Role- and label-name matching is a
 * case-insensitive SUBSTRING in both Playwright and vitest-browser, so two controls whose names
 * overlap resolve to two elements and every locator for either fails on strict mode — and a
 * screen-reader user meets the same ambiguity one control at a time. The two dialogs cannot collide
 * because each is mounted only while ITS trigger opened it, and only one can be open.
 *
 * Both are `--primary`, which reads as a violation of "at most one primary action" and is not: that
 * rule is about a page HEADER's action cluster, and the first-run empty state's primary action is
 * `references/states.md`'s own prescription for an onboarding moment. They never sit adjacent — one
 * is in the header plane, the other is centred inside the table card.
 *
 * ── THREE CONTROLS, TWENTY-FIVE FIELDS ON THE WIRE ──────────────────────────────────────────────
 * The body is `botCreateDefaults()` under whatever the operator typed. Re-sending a value the server
 * would have defaulted to is free — `retrieval_configuration_version` moves only when a knob's VALUE
 * changes — and everything past name, slug and description is the EDITOR's business: three tabs on
 * `/bots/{id}`, which is where this dialog navigates on success. A create form carrying the model
 * selector and four retrieval depths would be a worse version of a screen that already exists.
 *
 * NO `status` CONTROL, and none is possible: a bot is created `draft`, always, `StoreBotRequest`
 * declares no rule for the field, and `botCreateSchema` is a `strictObject` — so sending one is a
 * parse failure here rather than a silent strip.
 *
 * ── SLUG UNIQUENESS IS A 422 KEYED `slug`, AND IT MUST LAND ON THE FIELD ────────────────────────
 * It is enforced in `BotService`, not in `rules()`, because it is per ORGANIZATION and an unscoped
 * `unique:` rule would be an existence oracle over the whole platform rendered as a validation error
 * on a form. It still arrives as an ordinary per-field validation message, so `slug` is in
 * `BOT_CREATE_KNOWN_PATHS` and `applyAuthError` writes it under the input. Routed to the banner it
 * would read as a general failure of the create, and the operator would resubmit the same handle.
 */
export function CreateBotDialog({ triggerLabel }: { readonly triggerLabel: string }) {
  const session = useSession();
  const orgId = useCurrentOrgId();

  // Nothing renders while identity is unknown, for a signed-out visitor, or with no current
  // organization: a create control that cannot address a tenant is a control whose every use 404s.
  if (session.status !== 'authenticated' || orgId === null) return null;

  // The role the VIEWER holds HERE, read from the membership list the server itself handed us. Per
  // organization, always: the same person can be an owner in one tenant and an analyst in another.
  const viewerRole: Role | null =
    session.organizations.find((organization) => organization.id === orgId)?.role ?? null;

  // AN AFFORDANCE, NEVER AUTHORIZATION. `bots.view` is held by all four roles and `bots.manage` by
  // owner and admin (ADR-056), so an analyst can read this list and can create nothing on it.
  // Laravel answers 403 whatever this line renders; hiding the control removes an action that would
  // always be refused, and the 403 path below still exists for a role that changed under us.
  if (!canManageBots(viewerRole)) return null;

  return <CreateBotForOrganization orgId={orgId} triggerLabel={triggerLabel} />;
}

/** `max:120` and `max:64` from `StoreBotRequest`. Affordances on the controls, never the authority. */
const NAME_MAX = 120;
const SLUG_MAX = 64;
const DESCRIPTION_MAX = 2000;

function CreateBotForOrganization({
  orgId,
  triggerLabel,
}: {
  readonly orgId: string;
  readonly triggerLabel: string;
}) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const [open, setOpen] = useState(false);

  /**
   * THE LIST PREFIX, not one page's key. `['org', orgId, 'bots']` prefix-matches every entry the
   * table wrote — one per page, sort and filter combination — and a new bot can land on any of them
   * under the default `id` ascending ordering. Invalidating the single key the operator happens to be
   * looking at would leave four stale entries behind for the moment they page or search.
   *
   * Built with `orgKey` rather than `useOrgKey()` because this component has already established a
   * non-null organization by a mount condition, and the hook's throw is for the case that condition
   * exists to prevent.
   */
  const botsListKey = orgKey(orgId, 'bots');

  // THREE GENERICS, and the first and third genuinely differ: `BotCreateIn` is what the controls hold
  // (`description` is a `z.preprocess`, so its INPUT type is `unknown`) while `BotCreateOut` is what
  // `handleSubmit` receives after trimming and after a blank textarea has become `null`.
  const form = useForm<BotCreateIn, unknown, BotCreateOut>({
    resolver: zodResolver(botCreateSchema),
    // FROM THE SCHEMA'S OWN FACTORY, never a local literal. It seeds the four retrieval depths with
    // `NewBot`'s defaults, and `rerank_retain`'s band starts at 6 rather than at 1 — so a blank
    // control is not merely unhelpful, it is outside the accepted range, on a field this dialog does
    // not render and the operator cannot fix.
    defaultValues: botCreateDefaults(),
    mode: 'onTouched',
  });

  const create = useMutation({
    mutationFn: (values: BotCreateOut) => createBot(orgId, values),
    onSuccess: (bot) => {
      // RESET BEFORE CLOSING, so a re-open starts empty rather than holding the bot that was just
      // created — a pre-filled slug is a duplicate 422 waiting to happen.
      form.reset(botCreateDefaults());
      setOpen(false);
      // THE POINT OF THE CREATE PATH. A new bot is a draft with no model, no sources and no voice;
      // the next act is always configuring it, so this lands on the editor rather than on a list row
      // the operator then has to find. `push`, not `replace`: back returns to the list they came from.
      router.push(`/bots/${bot.id}`);
    },
    onError: (error) => {
      // The SHARED handler, branching on `error_class` and never on an HTTP status. `validation`
      // returns early inside it, so a 422 on `name` or `slug` lands under its control; a 422 on any
      // of the twenty-two fields this dialog does not render has nowhere to land and reaches the
      // banner with Laravel's own translated sentence. `authorization` (a role that changed under a
      // cached session) and `tenant_quota` (a plan bot limit) both fall through to the banner with a
      // class-mapped sentence plus the request_id — the envelope's own `message` reaches no rendered
      // string, because it is operator-facing and can carry an internal hostname.
      applyAuthError(form, BOT_CREATE_KNOWN_PATHS, error);
    },
    onSettled: () => {
      // `onSettled` rather than `onSuccess`: a 422 for a duplicate slug means the list behind this
      // dialog is ALSO out of date — there is a bot there we are not rendering.
      void queryClient.invalidateQueries({ queryKey: botsListKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <>
      <Button type="button" onClick={() => setOpen(true)}>
        <PlusIcon aria-hidden />
        {triggerLabel}
      </Button>

      {/* Mounted only while open, so the screen carries no form state — and no dialog holding a
          half-typed handle — while it is closed. There is deliberately no success receipt outside the
          dialog: the navigation IS the confirmation, and an alert rendered on a page we are leaving
          shows for no frames at all. */}
      {open ? (
        <Dialog open={open} onOpenChange={setOpen}>
          {/* Focus trapping, Escape, scroll lock, `inert` on the background and focus return to the
              trigger all come from the primitive. */}
          <DialogContent className="max-h-[85vh] overflow-y-auto">
            <Form {...form}>
              <form
                // POST, never the browser's default GET — this form sits behind a session, so its
                // native fallback would put form values in an admin's history and in a referer.
                // Asserted for every form by tests/unit/form-method.test.ts.
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
                  <DialogTitle>Create a bot</DialogTitle>
                  <DialogDescription>
                    A bot starts as a draft: it answers nobody until you give it a model, its
                    sources and a voice. You can change every one of those afterwards.
                  </DialogDescription>
                </DialogHeader>

                {rootError === undefined ? null : (
                  <Alert variant="destructive">
                    <AlertDescription>{rootError}</AlertDescription>
                  </Alert>
                )}

                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Name</FormLabel>
                      <FormControl>
                        <Input
                          {...field}
                          value={asText(field.value)}
                          autoComplete="off"
                          maxLength={NAME_MAX}
                          placeholder="Support bot"
                        />
                      </FormControl>
                      <FormDescription>
                        What your team calls it. Only people in this organization see it.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="slug"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Handle</FormLabel>
                      <FormControl>
                        <Input
                          {...field}
                          value={asText(field.value)}
                          autoComplete="off"
                          autoCapitalize="none"
                          spellCheck={false}
                          maxLength={SLUG_MAX}
                          className="font-mono"
                          placeholder="support-bot"
                        />
                      </FormControl>
                      {/* NOT DERIVED FROM THE NAME, deliberately. A slugifier here would be a fourth
                          spelling of `bots_slug_shape` — after the CHECK constraint, the
                          FormRequest's `regex:` and `botCreateSchema`'s mirror of it — and the one
                          spelling nothing compares against anything. The schema's own message says
                          what a handle is; typing it is one field. */}
                      <FormDescription>
                        Lower-case letters, digits and internal hyphens. Unique within this
                        organization only — another organization using the same handle is not a
                        conflict.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="description"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Description</FormLabel>
                      <FormControl>
                        {/* `unknown` -> string: `clearableText` is a `z.preprocess`, so this field's
                            INPUT type is `unknown` while its output is `string | null`. The `''`
                            fallback is also what keeps the control CONTROLLED across the reset that
                            runs after a successful create. */}
                        <Textarea
                          {...field}
                          value={asText(field.value)}
                          rows={3}
                          maxLength={DESCRIPTION_MAX}
                        />
                      </FormControl>
                      <FormDescription>
                        Optional. For your team, not for the people talking to it.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <DialogFooter>
                  <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                    Cancel
                  </Button>
                  {/* Disabled on `isPending`: this POST carries no `Idempotency-Key`, so nothing may
                      replay it — including a double-click. */}
                  <Button type="submit" disabled={create.isPending}>
                    {create.isPending ? 'Creating…' : 'Save bot'}
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
