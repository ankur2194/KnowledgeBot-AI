'use client';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { botAccessModeLabel } from './api';
import { useBotEditor } from './bot-editor-context';

/**
 * TAB 3 OF 3 — "Publishing". THIS FILE IS ONE AGENT'S, AND ONLY THIS FILE.
 *
 * The shell (`bot-editor-screen.tsx`) carries the whole contract in prose and is the file to read
 * first; `bot-editor-context.ts` carries the code half. Neither may be edited from here, and neither
 * may `./api.ts`. Private modules beside this one are fine.
 *
 * ── WHAT LANDS HERE ─────────────────────────────────────────────────────────────────────────────
 * `BOT_PUBLISHING_FIELDS` from `./api`, and nothing outside it:
 *
 *     status  access_mode  rate_limit_per_minute  rate_limit_per_day
 *     retention_days  collect_end_user_data  consent_text
 *
 * ── `status` IS THE ONLY FIELD ON THE PATCH THAT IS NOT ON THE POST, AND IT IS A TRANSITION ─────
 * A bot is created `draft`, always — `StoreBotRequest` declares no rule for `status` at all, because
 * creating one directly into `published` would run the publish guard against a source assignment that
 * cannot exist yet. `botSettingsSchema` mirrors the VALUE set the PATCH accepts and cannot mirror the
 * TRANSITIONS, which are enforced where the current row is known: `archived` is TERMINAL server-side —
 * an archived bot is read-only, including its status — and the publish guard can refuse a move to
 * `published` for reasons this form cannot compute. So a refused transition is a 422 keyed `status`
 * with Laravel's own translated sentence, and the control must render that under itself rather than
 * pre-judging the move.
 *
 * `testing` now has a status pill of its own (a still info glyph, `components/status-pill.tsx`); it is
 * the trialled-before-publication state and reads as info rather than as neutral. `botStatusDisplay`
 * in `./api` is the one mapping — do not write a second one here.
 *
 * ── READ THIS BEFORE BUILDING THE `status` CONTROL: THE SERVER IS MOVING IT (2026-08-19) ────────
 * The control plane is landing `PUT /bots/{bot}/status` as its own endpoint and making `status`
 * `prohibited` on the PATCH — its refusal message reads "A lifecycle transition is PUT
 * /bots/{bot}/status, not a field beside a rename." That work was UNCOMMITTED in
 * `services/core-api/` when this shell was written, so nothing here has moved yet: the committed
 * `packages/contracts/rules/UpdateBotRequest.json` still rules `status`, `botSettingsSchema` still
 * declares it, and `BOT_PUBLISHING_FIELDS` therefore still carries it — which is what keeps
 * `tests/unit/bot-editor.test.ts`'s partition assertions honest against the contract that exists.
 *
 * THE CONSEQUENCE FOR WHOEVER BUILDS THIS TAB: check the manifest first. If `status` reads
 * `["prohibited"]`, `useBotSave` is the WRONG path for it — the control needs its own mutation
 * against the new endpoint, `status` comes OUT of `BOT_PUBLISHING_FIELDS` (a change to `./api.ts`,
 * which means the shell's owner, not this file's), and the partition test fails by name until it
 * does. That failure is the mechanism working, not a broken test. The other six fields are
 * unaffected.
 *
 * Two new child collections arrived in the same effort — `/bots/{bot}/domains` and
 * `/bots/{bot}/starter-questions`. Neither is a `botSettingsSchema` field, so neither belongs in any
 * of the three tuples; where they are edited is an open question for the shell's owner rather than
 * something to answer inside a tab.
 *
 * ── THE PAIR THAT NO DECLARATIVE RULE CAN EXPRESS, AND IT IS YOURS ──────────────────────────────
 * `collect_end_user_data: true` is only STORABLE together with `consent_text`, and that pairing is
 * deliberately absent from BOTH FormRequests and from `botSettingsSchema`. The server's own reason:
 * the rule would be correct on the POST and WRONG on the PATCH, where enabling collection on a bot
 * that already carries a disclosure would be refused for a field the caller had no reason to resend.
 * The whole check lives in `BotService`, evaluated against the RESULTING row, with
 * `bots_consent_text_present_when_collecting` as the database's copy.
 *
 * So do NOT add a client-side mirror of it — that would block a body the server accepts, which is the
 * direction that removes functionality with nothing reported. Render the two controls TOGETHER, in
 * one group, and let the 422 land on `consent_text`, which is in your tuple.
 *
 * ── `access_mode` IS NOT AN ORIGIN ALLOW-LIST ──────────────────────────────────────────────────
 * It says whether an ANONYMOUS end user may converse. Where the widget may be embedded is a separate,
 * server-side control and this field is no substitute for one; say so beside the control rather than
 * letting "Public" read as "on the internet with no further checks".
 *
 * `retention_days`, `rate_limit_per_minute` and `rate_limit_per_day` are all nullable, and `null`
 * means "no limit" / "keep forever" — which is a different intention from omitting the key. A cleared
 * input posts `null`; an untouched one is absent. `nullableIntField` in `@kb/contracts/forms` keeps
 * those apart and the controls must not collapse them: zero is not a limit, it is a bot that answers
 * nobody, and both `min:1` and `bots_rate_limits_positive` refuse it.
 *
 * ── THE FORM, VERBATIM ──────────────────────────────────────────────────────────────────────────
 *     const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
 *       resolver: zodResolver(botSettingsSchema),
 *       defaultValues: botPanelDefaults(bot, BOT_PUBLISHING_FIELDS),
 *       mode: 'onTouched',
 *     });
 *     const save = useBotSave(form, BOT_PUBLISHING_FIELDS);
 *     useUnsavedBotEdits('publishing', form.formState.isDirty);
 */
export function BotPublishingPanel() {
  const { bot } = useBotEditor();

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Publishing</CardTitle>
        <CardDescription>
          Whether this bot is answering, who may talk to it, and what is kept afterwards.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {/* The controls are the next step. Until they land this states what the row already says
            about reach and retention, which is the part an operator checks before publishing. */}
        <p className="text-base text-muted-foreground">
          Access is {botAccessModeLabel(bot.access_mode).toLowerCase()}
          {bot.retention_days === null
            ? ' and conversations are kept indefinitely'
            : ` and conversations are kept for ${bot.retention_days} days`}
          . The lifecycle status, rate limits, retention and the end-user data disclosure are edited
          here.
        </p>
      </CardContent>
    </Card>
  );
}
