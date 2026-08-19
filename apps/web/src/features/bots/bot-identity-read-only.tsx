'use client';

import type { BotResource } from '@kb/contracts';
import type { ReactNode } from 'react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * THE IDENTITY TAB FOR A READER WITHOUT `bots.manage`, AND IT MOUNTS NO FORM AT ALL.
 *
 * Panel rule (a): `canManage === false` means render no form. A disabled input holding a value is a
 * control an operator will keep clicking, and RHF strips disabled names from a submit anyway, so the
 * "harmless" version of that mistake is a save that silently drops half the body. There is no
 * `useForm` on this path, no resolver, and no submit — the values are text.
 *
 * The shell has already said WHY, once, above the tabs, as a fact about this organization rather than
 * as an error: `bots.view` is held by all four roles and an analyst reading this screen is doing
 * something legitimate. This component does not repeat it.
 *
 * ── THE TWO FIELDS THAT ARE NOT MISSING, THEY ARE WITHHELD ────────────────────────────────────
 * `system_instruction` and `answer_style_instruction` are a MANAGEMENT-ONLY PROJECTION (ADR-056):
 * the server sends `null` for both to any caller without `bots.manage`, whatever is stored. `null`
 * there means "not shown to you" and NEVER "not set", and the two must not render the same — so this
 * path does not test them at all. It says they are hidden, unconditionally, because on this path
 * that is the only thing that is true: a bot with a 4,000-character prompt and a bot with none are
 * byte-identical on the wire here, and a screen that guessed would be inviting the reader to fill in
 * a field it is not allowed to see.
 *
 * Every other field on this tab is sent in full to every role, so `null` on one of those really is
 * "not set" and is rendered as such.
 */
export function BotIdentityReadOnly({ bot }: { readonly bot: BotResource }) {
  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle>What your team calls it</CardTitle>
          <CardDescription>
            The name and handle this organization finds the bot by. Only people here see them.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="flex flex-col gap-4">
            <ReadOnlyField label="Name">{bot.name}</ReadOnlyField>
            <ReadOnlyField label="Handle">
              <span className="font-mono">{bot.slug}</span>
            </ReadOnlyField>
            <ReadOnlyField label="Description">{orNotSet(bot.description)}</ReadOnlyField>
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>What the people talking to it see</CardTitle>
          <CardDescription>
            The first thing the bot says, and the prompt inside the box they type into.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="flex flex-col gap-4">
            <ReadOnlyField label="Welcome message">
              {orNotSet(bot.welcome_message)}
            </ReadOnlyField>
            <ReadOnlyField label="Composer placeholder">
              {orNotSet(bot.placeholder_text)}
            </ReadOnlyField>
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>How it is told to answer</CardTitle>
          <CardDescription>
            The operator-authored prompt and answer style. These are the two fields the console keeps
            to owners and admins.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-base text-muted-foreground">
            The system instruction and the answer style are hidden from this view — they are{' '}
            <strong className="font-medium text-foreground">not empty</strong>, and this screen
            cannot tell you whether they are set. Reading them needs the owner or admin role in this
            organization.
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Appearance</CardTitle>
          <CardDescription>
            The two colours and the corner radius this bot&apos;s chat surface is drawn with.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="flex flex-col gap-4">
            <ReadOnlyField label="Brand colour">
              {orPlatformDefault(bot.theme.primary)}
            </ReadOnlyField>
            <ReadOnlyField label="Highlight colour">
              {orPlatformDefault(bot.theme.accent)}
            </ReadOnlyField>
            <ReadOnlyField label="Corner radius">
              {orPlatformDefault(bot.theme.radius)}
            </ReadOnlyField>
          </dl>
        </CardContent>
      </Card>
    </>
  );
}

/**
 * One label/value pair. A real `<dt>`/`<dd>` rather than two styled divs, so the association survives
 * into the accessibility tree — the same argument card titles are real headings for.
 */
function ReadOnlyField({
  label,
  children,
}: {
  readonly label: string;
  readonly children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-caption text-muted-foreground">{label}</dt>
      {/* `whitespace-pre-line`: a welcome message may carry the line breaks the operator typed, and
          collapsing them here would show a paragraph the end user will not see. Tenant text is a JSX
          child on this path as on every other — never markup. */}
      <dd className="text-base whitespace-pre-line">{children}</dd>
    </div>
  );
}

/**
 * `null` on one of these fields really is "not set" — they are sent in full to every role, so there
 * is no projection to confuse it with. Said in words rather than as an em dash: a dash in a value
 * slot is a THIRD statement ("we could not measure this") and this is not that.
 */
function orNotSet(value: string | null): ReactNode {
  return value === null ? <span className="text-muted-foreground">Not set</span> : value;
}

/** An absent theme member is the platform's own value rather than an empty one. */
function orPlatformDefault(value: string | undefined): ReactNode {
  return value === undefined ? (
    <span className="text-muted-foreground">The platform default</span>
  ) : (
    <span className="font-mono">{value}</span>
  );
}
