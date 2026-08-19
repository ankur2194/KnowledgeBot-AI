'use client';

import type { BotStarterQuestionResource } from '@kb/contracts';
import {
  starterQuestionCreateDefaults,
  starterQuestionCreateSchema,
  starterQuestionUpdateSchema,
  type StarterQuestionCreateIn,
  type StarterQuestionCreateOut,
  type StarterQuestionUpdateIn,
  type StarterQuestionUpdateOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ChevronDownIcon,
  ChevronUpIcon,
  MessageCircleQuestionIcon,
  PencilIcon,
  PlusIcon,
  Trash2Icon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';

import { EmptyState, ErrorState, SkeletonLines } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { actionErrorCopy } from '@/features/auth/action-error';
import { applyAuthError } from '@/features/auth/auth-error';
import { useOrgKey } from '@/features/auth/session-context';
import { asText } from '@/lib/forms/as-text';

import { useBotEditor } from './bot-editor-context';
import {
  createStarterQuestion,
  deleteStarterQuestion,
  fetchStarterQuestions,
  starterQuestionRenameDefaults,
  starterQuestionsKeyParts,
  updateStarterQuestion,
  STARTER_QUESTIONS_MAX,
  STARTER_QUESTION_CREATE_KNOWN_PATHS,
  STARTER_QUESTION_MAX_LENGTH,
  STARTER_QUESTION_RENAME_KNOWN_PATHS,
} from './bot-identity-starter-questions';

/**
 * THE STARTER QUESTIONS — a CHILD COLLECTION, and the distinction from a form field is the whole
 * reason this is a separate component with separate requests.
 *
 * `botSettingsSchema` has no `starter_questions` key and must not grow one: these are rows in their
 * own table with their own endpoints, their own FormRequests and their own audit trail. Editing them
 * through the bot PATCH would mean the identity tab's Save either rewrote the whole list on every
 * press or carried a diff the server has no rule for.
 *
 * ── RE-READ THE COLLECTION AFTER A MOVE OR A DELETE. THIS IS THE ONE THAT BITES ────────────────
 * `bot_starter_questions_org_bot_position` is UNIQUE per bot and deliberately not deferrable, so the
 * server cannot write one row's position in isolation. Every write takes the bot's row lock, applies
 * the change in memory and RE-SEQUENCES THE WHOLE LIST to 0..n-1 inside one transaction — a move
 * rewrites the `sort_order` of every row it passed, and a delete closes the gap behind it. The
 * response is the single edited row while the others have silently moved.
 *
 * So nothing here is optimistic and nothing splices. Every mutation invalidates the collection key
 * and the list is re-read. A client-side splice would desynchronise against a list the server has
 * already renumbered, and the symptom is a chip order that is right until the next reload — which is
 * the worst kind of bug to be handed, because the person who reports it cannot reproduce it.
 *
 * ── THE CEILING IS THE SERVER'S AND THE FLOOR DOES NOT EXIST ──────────────────────────────────
 * `kb-ai-chat-ux` asks for three to six chips. The server enforces the CEILING of six and
 * deliberately not the floor: a bot with one question is legitimate and zero is every bot's default,
 * so a client-side minimum would refuse a configuration the server accepts. Both numbers are read
 * from the dumped manifest rather than typed out (`bot-identity-starter-questions.ts`).
 *
 * ── THE FOUR STATES, AND THE TWO THAT DO NOT APPLY ────────────────────────────────────────────
 *   Loading    a skeleton at the list's shape.
 *   Empty      FIRST-RUN only. There is no filter on this collection — no search, no sort, no page —
 *              so there is no FILTERED empty to distinguish it from, and inventing one would be a
 *              component nothing can reach. The copy differs by `canManage`, because "add your first
 *              one" is the wrong sentence for a reader who may not.
 *   Error      the class-mapped sentence plus the request_id, with the retry gated on the
 *              envelope's own `retryable`.
 *   Forbidden  THE SAME RENDER as error, deliberately. `index` demands `bots.view`, which ALL FOUR
 *              roles hold, so an `authorization` failure here is never a role gap — it is a bot this
 *              session can no longer address, which 404s at binding time and renders as
 *              `authorization` by the deny split. `<ForbiddenState>` names a role and who can grant
 *              it; here that would point at the wrong door.
 */
export function BotStarterQuestions({
  onUnsavedChange,
}: {
  /**
   * Reports whether any of this collection's small forms holds unsaved text, so the identity panel
   * can fold it into its ONE `useUnsavedBotEdits` call.
   *
   * The panel is unmounted when its tab is deselected, which destroys a half-typed chip label
   * exactly as it destroys a half-typed welcome message — and the shell's guard is the only thing
   * that can ask first. Pass a `useState` setter: it is stable, so the effect below does not re-run
   * on every parent render.
   */
  readonly onUnsavedChange?: (unsaved: boolean) => void;
}) {
  const { orgId, botId, canManage } = useBotEditor();
  const orgKeyFor = useOrgKey();

  /**
   * `['org', orgId, 'bots', botId, 'starter-questions']`.
   *
   * MEMOIZED, because it is a `useQuery` key and a `useMemo` dependency in two children; a fresh
   * array each render is a fresh identity for both. Built through `useOrgKey()` like every other
   * screen rather than by hand — a key built from `undefined` is ONE namespace shared by every
   * org-less state on the platform, which is exactly the leak the prefix exists to prevent, and the
   * hook throws rather than producing one.
   *
   * IT IS A PREFIX-CHILD OF `botsListKey` (`['org', orgId, 'bots']`), so `useBotSave`'s invalidation
   * catches it too and an identity save re-reads this list. That is one extra GET per save rather
   * than a correctness problem, and the alternative — a key outside the bots namespace — would put
   * this collection somewhere an organization switch's prefix reset does not reach.
   */
  const questionsKey = useMemo(
    () => orgKeyFor(...starterQuestionsKeyParts(botId)),
    [orgKeyFor, botId],
  );

  const questions = useQuery({
    queryKey: questionsKey,
    // `signal` forwarded: `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is a step in both logout and the organization switch.
    queryFn: ({ signal }) => fetchStarterQuestions(orgId, botId, signal),
  });

  /**
   * THE UNSAVED-TEXT REGISTRY FOR THIS CARD, keyed by form rather than a single boolean.
   *
   * Two kinds of form live here — one add form and at most one open rename — and either can hold
   * text. A single boolean written by both would have the second writer clear the first's report,
   * which is the failure the shell's own `Set` exists to avoid one level up. The ref holds the
   * membership and the state holds only the derived answer, so a keystroke that does not FLIP
   * dirtiness re-renders nothing.
   */
  const dirtyForms = useRef<Set<string>>(new Set());
  const [anyDirty, setAnyDirty] = useState(false);

  const reportDirty = useCallback((formId: string, dirty: boolean) => {
    if (dirty) dirtyForms.current.add(formId);
    else dirtyForms.current.delete(formId);
    setAnyDirty(dirtyForms.current.size > 0);
  }, []);

  useEffect(() => {
    onUnsavedChange?.(anyDirty);
  }, [anyDirty, onUnsavedChange]);

  /**
   * THE OUTCOME ANNOUNCEMENT, and it is `polite` rather than a toast.
   *
   * A reorder or a delete moves rows the operator is not looking at — the server renumbers the whole
   * list — so "it worked" is not visible from the button that was pressed. A live region says what
   * happened once; a toast would disappear and be unreachable by keyboard afterwards, which is the
   * single most common accessibility complaint about dashboards.
   *
   * It updates only on a completed action. Nothing polls here, so this never becomes a region that
   * announces on its own.
   */
  const [announcement, setAnnouncement] = useState('');

  const rows = questions.data ?? [];
  const full = STARTER_QUESTIONS_MAX !== null && rows.length >= STARTER_QUESTIONS_MAX;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Starter questions</CardTitle>
        <CardDescription>
          The chips someone sees before they have typed anything. They are suggestions, not a menu —
          the bot answers anything its sources cover.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {/* Rendered unconditionally so the region exists in the accessibility tree BEFORE it has
            anything to say. A live region inserted at the same moment as its first message is not
            reliably announced. */}
        <p role="status" aria-live="polite" className="sr-only">
          {announcement}
        </p>

        {questions.isPending ? <SkeletonLines lines={3} /> : null}

        {questions.error === null ? null : (
          <ErrorState
            title={
              questions.data === undefined
                ? 'Starter questions could not be loaded'
                : 'Starter questions could not be refreshed'
            }
            error={questions.error}
            onRetry={() => void questions.refetch()}
          />
        )}

        {questions.data === undefined ? null : rows.length === 0 ? (
          <EmptyState
            glyph={MessageCircleQuestionIcon}
            title="No starter questions yet"
            body={
              canManage
                ? 'A new bot opens with none, which is a legitimate configuration — the first-run screen simply shows no suggestions. Three to six work best when you do add them.'
                : 'This bot opens with no suggestions. Its first-run screen shows the welcome message and the composer, and nothing to click.'
            }
          />
        ) : (
          <ol className="flex flex-col gap-2">
            {rows.map((row) => (
              <StarterQuestionRow
                key={row.id}
                orgId={orgId}
                botId={botId}
                questionsKey={questionsKey}
                row={row}
                total={rows.length}
                canManage={canManage}
                onReportDirty={reportDirty}
                onAnnounce={setAnnouncement}
              />
            ))}
          </ol>
        )}

        {!canManage || questions.data === undefined ? null : full ? (
          <Alert variant="info">
            <AlertTitle>
              This bot has the maximum of {STARTER_QUESTIONS_MAX} starter questions
            </AlertTitle>
            <AlertDescription>
              Six is the number the chat surface renders, so what is stored is what is shown. Remove
              one to add another.
            </AlertDescription>
          </Alert>
        ) : (
          <AddStarterQuestionForm
            orgId={orgId}
            botId={botId}
            questionsKey={questionsKey}
            onReportDirty={reportDirty}
            onAnnounce={setAnnouncement}
          />
        )}
      </CardContent>
    </Card>
  );
}

/** The registry key for the add form. The rename forms use their row's ULID, which cannot collide. */
const ADD_FORM_ID = 'add';

/**
 * ONE QUESTION: its position, its text, and the four things that can be done to it.
 *
 * ── THE POSITION COMES FROM `sort_order`, NOT FROM THE ARRAY INDEX ────────────────────────────
 * They agree today — the server promises 0..n-1 with no gaps — and reading the server's own field is
 * what makes a gap show up as a wrong number rather than as nothing at all. `sort_order` is also the
 * coordinate the PATCH speaks in, so the move buttons and the label cannot disagree about where this
 * row is.
 *
 * ── THE MOVE BUTTONS ARE THE KEYBOARD-OPERABLE FORM OF A DRAG, AND THEY ARE THE ONLY FORM ─────
 * No interaction may depend on a drag or a precise path. Two buttons are the whole reorder
 * affordance rather than a fallback behind one, so there is nothing to keep in sync and nothing that
 * only works with a mouse.
 */
function StarterQuestionRow({
  orgId,
  botId,
  questionsKey,
  row,
  total,
  canManage,
  onReportDirty,
  onAnnounce,
}: {
  readonly orgId: string;
  readonly botId: string;
  readonly questionsKey: readonly unknown[];
  readonly row: BotStarterQuestionResource;
  readonly total: number;
  readonly canManage: boolean;
  readonly onReportDirty: (formId: string, dirty: boolean) => void;
  readonly onAnnounce: (message: string) => void;
}) {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [confirmingRemoval, setConfirmingRemoval] = useState(false);

  /**
   * A MOVE, WHICH IS AN INTENT AND NOT A COLUMN WRITE.
   *
   * The body names `sort_order` and nothing else — never the text as well. `question` and
   * `sort_order` are each other's `required_without` and the FormRequest carries NO `sometimes`, so a
   * body with exactly one of them is the legitimate shape and an empty one is refused rather than
   * answered 200. Re-sending the text here would also make a reorder indistinguishable from an edit
   * in the audit trail.
   *
   * `onSettled` and not `onSuccess`: a refusal means the list in front of the operator is stale in a
   * way that matters — the 422 the server sends for a position past the end says precisely that.
   */
  const move = useMutation<BotStarterQuestionResource, Error, number>({
    mutationFn: (sortOrder) => updateStarterQuestion(orgId, botId, row.id, { sort_order: sortOrder }),
    onSuccess: () => {
      onAnnounce(`Moved “${row.question}”. The other questions were renumbered.`);
    },
    onSettled: () => {
      // RE-READ, never splice. The server re-sequenced every row this one passed.
      void queryClient.invalidateQueries({ queryKey: questionsKey });
    },
  });

  const remove = useMutation<void, Error, void>({
    mutationFn: () => deleteStarterQuestion(orgId, botId, row.id),
    onSuccess: () => {
      onAnnounce(`Removed “${row.question}”. The remaining questions were renumbered.`);
    },
    onSettled: () => {
      // CLOSED ON FAILURE TOO: the refusal is rendered in the row, and a dialog is `inert`-over-the-
      // background by design, so an alert left underneath an open one is an alert nobody reads.
      setConfirmingRemoval(false);
      void queryClient.invalidateQueries({ queryKey: questionsKey });
    },
  });

  /**
   * Neither mutation has a form, so neither has a field to put a 422 on. `actionErrorCopy` is
   * `endUserCopy` plus exactly one arm: a `validation` failure renders Laravel's own translated
   * messages, which ARE end-user copy, and every other class delegates to the class table. It is what
   * makes "that position is past the end of the list" readable next to a chevron; running it through
   * `endUserCopy` instead would print "Some details need fixing before this can be saved.", which
   * says nothing about a button the operator just pressed.
   *
   * The envelope's own `message` reaches no rendered string on either path.
   */
  const actionError = move.error ?? remove.error;

  return (
    <li className="flex flex-col gap-2 rounded-xl bg-card-inset p-3">
      <div className="flex items-start gap-3">
        <span
          data-numeric
          className="mt-0.5 shrink-0 text-caption text-muted-foreground"
          // The visible number is the position; the label says what the number MEANS, because "3"
          // on its own is read out as a bare digit next to the question text.
          aria-label={`Position ${String(row.sort_order + 1)}`}
        >
          {row.sort_order + 1}
        </span>

        {editing ? (
          <RenameStarterQuestionForm
            orgId={orgId}
            botId={botId}
            questionsKey={questionsKey}
            row={row}
            onReportDirty={onReportDirty}
            onAnnounce={onAnnounce}
            onDone={() => setEditing(false)}
          />
        ) : (
          <>
            {/* TENANT TEXT AS A JSX CHILD. `break-words` and not `truncate`: a chip label is up to
                200 characters and the operator has to be able to read the one they are about to
                delete. */}
            <p className="min-w-0 flex-1 text-base break-words">{row.question}</p>

            {canManage ? (
              // gap-2 is 8px, which is the minimum between adjacent targets; every control below
              // reaches 44px on a coarse pointer through the button's own `pointer-coarse` sizing.
              <div className="flex shrink-0 items-center gap-2">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label={`Move “${row.question}” up`}
                  disabled={row.sort_order === 0 || move.isPending}
                  onClick={() => move.mutate(row.sort_order - 1)}
                >
                  <ChevronUpIcon aria-hidden />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label={`Move “${row.question}” down`}
                  disabled={row.sort_order >= total - 1 || move.isPending}
                  onClick={() => move.mutate(row.sort_order + 1)}
                >
                  <ChevronDownIcon aria-hidden />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label={`Edit “${row.question}”`}
                  onClick={() => setEditing(true)}
                >
                  <PencilIcon aria-hidden />
                </Button>
                {/* NOT ICON-ONLY, and that is a rule rather than a preference: no destructive
                    control is icon-only. The accessible name starts with the visible word, so a
                    voice-control user saying "Remove" still matches it. */}
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="text-destructive"
                  aria-label={`Remove “${row.question}”`}
                  disabled={remove.isPending}
                  onClick={() => setConfirmingRemoval(true)}
                >
                  <Trash2Icon aria-hidden />
                  Remove
                </Button>
              </div>
            ) : null}
          </>
        )}
      </div>

      {actionError === null ? null : (
        <Alert variant="destructive">
          <AlertDescription>{actionErrorCopy(actionError)}</AlertDescription>
        </Alert>
      )}

      {/* Mounted only while open, so a row carries no dialog state in the ordinary case. */}
      {confirmingRemoval ? (
        <Dialog open onOpenChange={() => setConfirmingRemoval(false)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Remove starter question</DialogTitle>
              <DialogDescription>
                “{row.question}” stops being offered on this bot&apos;s first-run screen, and every
                question after it moves up one place. You can add it again afterwards.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              {/* NOT `<ConfirmDestructiveDialog>`, and the shell makes the same call about its own
                  discard dialog: typing a resource name is right for an irreversible delete and
                  absurd for a chip label that takes five seconds to retype. Cancel comes first in
                  the DOM, so Escape and the initial focus land on the safe choice. */}
              <Button type="button" variant="outline" onClick={() => setConfirmingRemoval(false)}>
                Keep it
              </Button>
              <Button
                type="button"
                variant="destructive"
                disabled={remove.isPending}
                onClick={() => remove.mutate()}
              >
                {remove.isPending ? 'Removing…' : 'Remove question'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      ) : null}
    </li>
  );
}

/**
 * RENAME ONE QUESTION IN PLACE.
 *
 * ── IT SENDS THE TEXT AND ONLY THE TEXT ───────────────────────────────────────────────────────
 * `starterQuestionRenameDefaults` is `starterQuestionUpdateDefaults` narrowed to `question`, for the
 * reason `botPanelDefaults` narrows `botFormDefaults`: the PATCH body is `handleSubmit`'s output
 * verbatim, and `sort_order` on this endpoint means "move this to position N". A rename seeded with
 * the position the row held when the form OPENED would move the row if the list changed underneath —
 * a reorder nobody asked for, or a 422 nobody predicted.
 *
 * ── SAVE IS DISABLED UNTIL SOMETHING CHANGED ──────────────────────────────────────────────────
 * Not politeness: every write here is audited, and a PATCH that stores the string it already held
 * writes a row claiming an edit that did not happen.
 */
function RenameStarterQuestionForm({
  orgId,
  botId,
  questionsKey,
  row,
  onReportDirty,
  onAnnounce,
  onDone,
}: {
  readonly orgId: string;
  readonly botId: string;
  readonly questionsKey: readonly unknown[];
  readonly row: BotStarterQuestionResource;
  readonly onReportDirty: (formId: string, dirty: boolean) => void;
  readonly onAnnounce: (message: string) => void;
  readonly onDone: () => void;
}) {
  const queryClient = useQueryClient();

  const form = useForm<StarterQuestionUpdateIn, unknown, StarterQuestionUpdateOut>({
    resolver: zodResolver(starterQuestionUpdateSchema),
    defaultValues: starterQuestionRenameDefaults(row),
    mode: 'onTouched',
  });

  const dirty = form.formState.isDirty;
  useEffect(() => {
    onReportDirty(row.id, dirty);
    return () => onReportDirty(row.id, false);
  }, [onReportDirty, row.id, dirty]);

  const rename = useMutation<BotStarterQuestionResource, Error, StarterQuestionUpdateOut>({
    mutationFn: (values) => updateStarterQuestion(orgId, botId, row.id, values),
    onSuccess: (updated) => {
      onAnnounce(`Saved “${updated.question}”.`);
      onDone();
    },
    onError: (error) => {
      // Branches on `error_class`, never on a status. `validation` returns early inside the helper,
      // so a 422 keyed `question` lands under the input; a 422 keyed `sort_order` — which this form
      // renders no control for — reaches the banner with Laravel's own translated sentence rather
      // than being written to a name that displays nowhere.
      applyAuthError(form, STARTER_QUESTION_RENAME_KNOWN_PATHS, error);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: questionsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <Form {...form}>
      <form
        // POST rather than the browser's default GET: this form sits behind a session, so a native
        // fallback would put its values in an admin's history and in a referer.
        method="post"
        noValidate
        onSubmit={form.handleSubmit((values) => {
          rename.mutate(values);
        })}
        className="flex min-w-0 flex-1 flex-col gap-2"
      >
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        <FormField
          control={form.control}
          name="question"
          render={({ field }) => (
            <FormItem>
              <FormLabel className="sr-only">Question {row.sort_order + 1}</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  value={asText(field.value)}
                  autoFocus
                  autoComplete="off"
                  maxLength={STARTER_QUESTION_MAX_LENGTH ?? undefined}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <div className="flex items-center gap-2">
          {/* Disabled on `isPending`: this PATCH carries no `Idempotency-Key`, so nothing may replay
              it, including a double-click. */}
          <Button type="submit" size="sm" disabled={rename.isPending || !dirty}>
            {rename.isPending ? 'Saving…' : 'Save question'}
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={onDone}>
            Cancel
          </Button>
        </div>
      </form>
    </Form>
  );
}

/**
 * ADD ONE QUESTION, APPENDED.
 *
 * THE POSITION IS NOT A FIELD. `starterQuestionCreateSchema` is a `strictObject` over `{question}`
 * alone, because a new question goes to the end of the list — the only position that cannot collide
 * with an existing one — and "0..n-1 with no gaps" is an invariant a client must not be able to name.
 *
 * THE FULL-LIST REFUSAL LANDS ON THIS FIELD. The service throws it keyed `question`, which is in this
 * form's `knownPaths`, so it appears under the input the operator just typed into. The form is hidden
 * once the list is full, so it is the race — two admins, two tabs — that actually reaches it.
 */
function AddStarterQuestionForm({
  orgId,
  botId,
  questionsKey,
  onReportDirty,
  onAnnounce,
}: {
  readonly orgId: string;
  readonly botId: string;
  readonly questionsKey: readonly unknown[];
  readonly onReportDirty: (formId: string, dirty: boolean) => void;
  readonly onAnnounce: (message: string) => void;
}) {
  const queryClient = useQueryClient();

  const form = useForm<StarterQuestionCreateIn, unknown, StarterQuestionCreateOut>({
    resolver: zodResolver(starterQuestionCreateSchema),
    defaultValues: starterQuestionCreateDefaults(),
    mode: 'onTouched',
  });

  const dirty = form.formState.isDirty;
  useEffect(() => {
    onReportDirty(ADD_FORM_ID, dirty);
    return () => onReportDirty(ADD_FORM_ID, false);
  }, [onReportDirty, dirty]);

  const add = useMutation<BotStarterQuestionResource, Error, StarterQuestionCreateOut>({
    mutationFn: (values) => createStarterQuestion(orgId, botId, values),
    onSuccess: (created) => {
      // RESET FIRST, so the box is empty for the next one rather than holding the question that was
      // just stored — a pre-filled field is a duplicate chip one Enter away.
      form.reset(starterQuestionCreateDefaults());
      onAnnounce(`Added “${created.question}” at position ${String(created.sort_order + 1)}.`);
    },
    onError: (error) => {
      applyAuthError(form, STARTER_QUESTION_CREATE_KNOWN_PATHS, error);
    },
    onSettled: () => {
      // `onSettled` rather than `onSuccess`: the "this bot already has the maximum" 422 means the
      // list on screen is out of date — somebody else added one.
      void queryClient.invalidateQueries({ queryKey: questionsKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <Form {...form}>
      <form
        method="post"
        noValidate
        onSubmit={form.handleSubmit((values) => {
          add.mutate(values);
        })}
        className="flex flex-col gap-2"
      >
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        <FormField
          control={form.control}
          name="question"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Add a starter question</FormLabel>
              <div className="flex items-start gap-2">
                <FormControl>
                  <Input
                    {...field}
                    value={asText(field.value)}
                    autoComplete="off"
                    maxLength={STARTER_QUESTION_MAX_LENGTH ?? undefined}
                    placeholder="How do I change my billing address?"
                  />
                </FormControl>
                <Button type="submit" disabled={add.isPending}>
                  <PlusIcon aria-hidden />
                  {add.isPending ? 'Adding…' : 'Add'}
                </Button>
              </div>
              <FormMessage />
            </FormItem>
          )}
        />
      </form>
    </Form>
  );
}
