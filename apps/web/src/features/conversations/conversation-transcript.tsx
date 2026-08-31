'use client';

import type {
  ConversationTranscriptResource,
  ProviderCallResource,
  TranscriptCitationResource,
  TranscriptMessageResource,
} from '@kb/contracts';
import { ArrowLeftIcon, ThumbsDownIcon, ThumbsUpIcon } from 'lucide-react';
import Link from 'next/link';

import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EM_DASH, formatCount, formatDuration } from '@/features/analytics/api';
import {
  channelDisplay,
  conversationStatusDisplay,
  messageStatusDisplay,
  turnMetrics,
} from '@/features/conversations/api';
import { AssistantProse } from '@/features/chat/turns';
import { formatTimestamp } from '@/features/providers/api';
import { cn } from '@/lib/utils';

/**
 * ONE CONVERSATION, AS AN ADMINISTRATOR READS IT.
 *
 * ── EVERY STRING BELOW THE HEADER IS UNTRUSTED, AND THIS SURFACE IS THE HIGHER-STAKES ONE ───────
 * Visitor questions, model output and quoted document passages, rendered inside a session that holds
 * real permissions. So `content` and `excerpt` both go through `<AssistantProse>` — the SAME
 * sanitizer path hosted chat and the widget use (`markdown-it html:false` → DOMPurify →
 * `replaceChildren`), with no widened allow-list and no auto-loaded remote image — and every other
 * tenant string is a JSX child.
 *
 * A USER TURN IS RENDERED AS PROSE TOO, and that is deliberate rather than an oversight. It is
 * end-user text, so it is exactly as untrusted as the model's; running it through the same path
 * means there is ONE renderer on this screen rather than a sanitized branch and a "it's only what
 * they typed" branch. `kb-ai-chat-ux`'s asymmetry — a bubble for the question, full width for the
 * answer — is a layout decision and is preserved; the escaping is not negotiable either way.
 *
 * ── IT IS NOT `<ChatSurface>`, AND THE DIFFERENCE IS THE AUDIENCE ───────────────────────────────
 * That component streams: it owns a composer, an AbortController, a live region and four visible
 * states, none of which exist here — this is a settled record with no send. What it DOES share is
 * the sanitizer and the citation model, which are imported rather than re-implemented. Reusing the
 * whole surface would mean giving a read-only screen a disabled composer, which is a control an
 * operator will keep clicking.
 *
 * ── AND IT SHOWS THREE THINGS THE PUBLIC TRANSCRIPT DELIBERATELY DOES NOT ───────────────────────
 * Per-turn latency, token counts and the fallback record — all read off `provider_calls`, because a
 * turn that fell back has TWO attempts and the message carries neither number. The audience here is
 * the party that is billed for the call and accountable for the answer, which is the whole reason
 * `TranscriptMessageResource` is wider than `RuntimeMessageResource`.
 */
export function ConversationTranscript({
  transcript,
}: {
  readonly transcript: ConversationTranscriptResource;
}) {
  const status = conversationStatusDisplay(transcript.status);
  const channel = channelDisplay(transcript.channel);

  return (
    <div className="flex flex-col gap-8">
      {/* THE WAY BACK IS EXPLICIT, and it renders in every state. This route is one level deep and
          the browser's back button is not an affordance a screen may rely on — an operator who
          arrived from a bookmark has nothing to go back through. */}
      <p>
        <Link
          href="/conversations"
          className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
        >
          <ArrowLeftIcon aria-hidden className="size-4" />
          All conversations
        </Link>
      </p>

      <Card>
        <CardHeader>
          <CardTitle as="h2">
            <span className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-sm">{transcript.id}</span>
              <StatusPill status={status.kind} label={status.label} />
            </span>
          </CardTitle>
          <CardDescription>
            {channel.label} ·{' '}
            <Link
              href={`/bots/${encodeURIComponent(transcript.bot_id)}`}
              className="font-mono text-link underline-offset-4 hover:underline"
            >
              {transcript.bot_id}
            </Link>{' '}
            · started {formatTimestamp(transcript.started_at)} · last activity{' '}
            {formatTimestamp(transcript.last_activity_at)}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <Field label="Participant">
              {transcript.user_id !== null
                ? `Member ${transcript.user_id}`
                : transcript.anonymous_session_id !== null
                  ? 'Anonymous visitor'
                  : 'Unknown'}
            </Field>
            <Field label="Locale">
              {/* `null` means "we were not told", which is a different fact from `en` — so it is a
                  sentence and not an em dash. */}
              {transcript.locale ?? 'Not declared'}
            </Field>
            <Field label="Retention">
              {transcript.retention_expires_at === null
                ? // `null` means the organization's own policy decides. It is NOT "kept for ever",
                  // and saying so would be a claim about a policy this screen cannot read.
                  'Follows the organization policy'
                : `Removable after ${formatTimestamp(transcript.retention_expires_at)}`}
            </Field>
            <Field label="Consent">
              {transcript.consent_required
                ? transcript.consent_granted_at === null
                  ? // REACHABLE AND WORTH SEEING: a thread that required consent and has no grant
                    // timestamp. §18.10 treats a transcript as evidence, so this is the one field on
                    // the header a reviewer may actually need to act on.
                    'Required, and not recorded'
                  : `Granted ${formatTimestamp(transcript.consent_granted_at)}`
                : 'Not required'}
            </Field>
          </dl>

          {transcript.consent_text_snapshot === null ? null : (
            <div className="rounded-lg bg-card-inset p-card-pad-sm">
              <p className="mb-1 text-caption text-muted-foreground uppercase">
                What the visitor was shown
              </p>
              {/* OPERATOR-AUTHORED TEXT, snapshotted at the moment it was shown, and a JSX child.
                  The bot's live consent text is editable, so this is the only true record of what
                  was agreed to. */}
              <p className="text-sm">{transcript.consent_text_snapshot}</p>
            </div>
          )}
        </CardContent>
      </Card>

      <section aria-labelledby="transcript-heading" className="flex flex-col gap-6">
        <h2 id="transcript-heading" className="text-h2">
          Transcript
        </h2>

        {transcript.messages.length === 0 ? (
          // A REAL STATE: a thread was opened and nothing was said. It is not a first-run empty and
          // there is nothing to create, so it is a sentence rather than an onboarding hero.
          <p className="text-base text-muted-foreground">
            This thread was opened and no turn was taken.
          </p>
        ) : (
          transcript.messages.map((message) => <Turn key={message.id} message={message} />)
        )}

        {transcript.messages_truncated ? (
          /*
           * PUBLISHED RATHER THAN INFERRED FROM THE ARRAY LENGTH, and rendered rather than ignored.
           * A client cannot tell a capped read from a conversation that happened to be exactly that
           * long — and a transcript that ends mid-argument reads as a conversation that ended there,
           * which on an evidence surface is the most misleading thing this screen could do.
           */
          <p className="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground">
            This thread is longer than one read returns. The turns above are the beginning of it, not
            all of it.
          </p>
        ) : null}
      </section>
    </div>
  );
}

function Field({ label, children }: { readonly label: string; readonly children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-caption text-muted-foreground uppercase">{label}</dt>
      <dd className="text-base">{children}</dd>
    </div>
  );
}

/**
 * One turn, with everything behind it.
 *
 * The layout asymmetry is `kb-ai-chat-ux`'s and is preserved here: the user's question is a bubble
 * because it is short and attributable, the assistant's answer is full width because a long grounded
 * answer in a bubble is unreadable. What is added on this surface is the metadata line — the status
 * pill, the latency, the tokens and the fallback record — because the audience is the party that is
 * billed for the call.
 */
function Turn({ message }: { readonly message: TranscriptMessageResource }) {
  const isUser = message.role === 'user';
  const status = messageStatusDisplay(message.status);
  const metrics = turnMetrics(message);

  return (
    <article className={cn('flex flex-col gap-3', isUser && 'items-end')}>
      <div
        className={cn(
          isUser
            ? 'max-w-[80%] rounded-2xl rounded-br-sm bg-primary-soft px-4 py-2 text-md text-primary-soft-foreground'
            : 'w-full text-md',
        )}
      >
        {message.content === null ? (
          // `null` when the turn has produced no text — a `pending` turn, or one that failed before
          // saying anything. NEVER an empty string, so this branch is the whole of the
          // nothing-to-render test, and it says which rather than showing a blank bubble.
          <p className="text-muted-foreground italic">
            {message.status === 'failed'
              ? 'This turn failed before it said anything.'
              : 'This turn has not produced text yet.'}
          </p>
        ) : (
          // THE SAME SANITIZER AS HOSTED CHAT AND THE WIDGET. `dangerouslySetInnerHTML` is an ESLint
          // error repo-wide; this path parses to a fragment and `replaceChildren`s it, which is also
          // what closes the mXSS re-serialization hole.
          <AssistantProse text={message.content} />
        )}
      </div>

      <div className={cn('flex flex-wrap items-center gap-2', isUser && 'justify-end')}>
        <StatusPill status={status.kind} label={status.label} />
        {message.parent_message_id === null ? null : (
          // IT MEANS RETRY AND NOTHING ELSE — it is NOT "the question this answers", which is
          // deliberately not a column: overloading it would make a resend indistinguishable from a
          // retry in every later read.
          <Badge variant="default">Retry of an earlier turn</Badge>
        )}
        <span className="text-caption text-muted-foreground" title={message.created_at}>
          {formatTimestamp(message.created_at)}
        </span>
      </div>

      {metrics.attempts.length === 0 ? null : <Attempts message={message} />}

      {message.citations.length === 0 ? null : <Citations citations={message.citations} />}

      {message.feedback.length === 0 ? null : (
        <div className="flex flex-wrap gap-2">
          {message.feedback.map((verdict) => (
            <span
              key={`${verdict.created_at}:${verdict.submitted_by_user_id ?? verdict.submitted_by_session ?? ''}`}
              className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-caption',
                verdict.rating === 'positive'
                  ? 'bg-success-soft text-success-soft-foreground'
                  : 'bg-destructive-soft text-destructive-soft-foreground',
              )}
            >
              {/* COLOUR IS NEVER THE ONLY CHANNEL: a glyph AND the word, so the verdict survives
                  greyscale and CVD. */}
              {verdict.rating === 'positive' ? (
                <ThumbsUpIcon aria-hidden className="size-3" />
              ) : (
                <ThumbsDownIcon aria-hidden className="size-3" />
              )}
              {verdict.rating === 'positive' ? 'Helpful' : 'Not helpful'}
              <span className="text-muted-foreground">
                {verdict.submitted_by_user_id === null ? 'from the visitor' : 'from a reviewer'}
              </span>
              {verdict.comment === null ? null : (
                // UNTRUSTED END-USER TEXT, and a JSX child. It is short by convention and not by
                // rule (`max:4000`), so it wraps rather than being truncated — a verdict's reason is
                // the whole value of the verdict.
                <span className="font-normal">“{verdict.comment}”</span>
              )}
            </span>
          ))}
        </div>
      )}
    </article>
  );
}

/**
 * PER-TURN LATENCY, TOKENS AND FALLBACK EVENTS — read off the ATTEMPTS and never off the message.
 *
 * A turn that fell back has TWO rows here, and the extra one is the record of what was tried first
 * and why it was not enough. `fallback_metadata` being non-empty is the ONLY signal that an attempt
 * is a fallback: a fallback row with an empty object is indistinguishable from a first attempt,
 * which is the whole reason the column exists.
 *
 * ── COST IS PER ROW AND IS NEVER SUMMED ─────────────────────────────────────────────────────────
 * `estimated_cost` is an exact decimal STRING and its currency travels with it. Adding two of them
 * means either parsing to a double — which is what `numeric(16,8)` exists to avoid — or assuming one
 * currency, which is a silent wrong answer the moment a fallback crosses vendors.
 */
function Attempts({ message }: { readonly message: TranscriptMessageResource }) {
  const metrics = turnMetrics(message);

  return (
    <div className="rounded-xl bg-card-inset p-card-pad-sm">
      <p className="mb-2 text-caption text-muted-foreground uppercase">
        {metrics.attempts.length === 1 ? 'Provider attempt' : `${metrics.attempts.length} attempts`}
      </p>

      <dl className="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <SummaryPair
          label="First token"
          value={formatDuration(metrics.settling?.first_token_latency_ms ?? null)}
        />
        <SummaryPair
          label="Total"
          value={formatDuration(metrics.settling?.total_latency_ms ?? null)}
        />
        <SummaryPair
          label="Input tokens"
          // `null` means the provider told us NOTHING, which is different from zero — so it renders
          // as an em dash rather than as `0`, which would read as a turn that cost nothing.
          value={metrics.inputTokens === null ? EM_DASH : formatCount(metrics.inputTokens)}
        />
        <SummaryPair
          label="Output tokens"
          value={metrics.outputTokens === null ? EM_DASH : formatCount(metrics.outputTokens)}
        />
      </dl>

      <ol className="flex flex-col gap-2">
        {metrics.attempts.map((call, index) => (
          <li key={call.id} className="flex flex-wrap items-center gap-2 text-caption">
            <span className="text-muted-foreground">#{index + 1}</span>
            <AttemptStatus call={call} />
            {Object.keys(call.fallback_metadata).length === 0 ? null : (
              <Badge variant="warning">Fallback</Badge>
            )}
            {call.id === message.settling_provider_call_id ? (
              <Badge variant="info">Settled this turn</Badge>
            ) : null}
            <span className="font-mono text-muted-foreground" title={call.model_id}>
              {call.model_id}
            </span>
            {call.estimated_cost === null ? null : (
              // THE EXACT DECIMAL STRING, NEVER PARSED, with its own currency beside it.
              <span className="tabular-nums">
                {call.estimated_cost} {call.estimated_cost_currency ?? ''}
              </span>
            )}
            {call.provider_request_id === null ? null : (
              // The vendor's own identifier — the one field here that lets somebody else reproduce a
              // failure, and what a support ticket to the provider quotes. Selectable for that
              // reason.
              <code className="font-mono text-muted-foreground select-all">
                {call.provider_request_id}
              </code>
            )}
          </li>
        ))}
      </ol>
    </div>
  );
}

/**
 * An attempt's outcome.
 *
 * `cancelled` IS NOT A FAILURE — a closed tab — and the tokens generated before it were still
 * billed, so counting it as a provider error makes the error-rate figure unusable. It gets the inert
 * treatment, not the red one.
 *
 * `error_class` IS RENDERED AS THE CLASS NAME, and that is the one place in this app where a raw
 * taxonomy string reaches a screen. It is deliberate here and nowhere else: the audience is an
 * operator diagnosing their own organization's provider configuration, the eighteen classes are a
 * published vocabulary, and mapping it to `ERROR_COPY`'s end-user sentence would replace "which
 * class did the provider fail with" — the question this row exists to answer — with "the AI provider
 * is temporarily unavailable", which the operator already knows.
 */
function AttemptStatus({ call }: { readonly call: ProviderCallResource }) {
  if (call.status === 'succeeded') return <StatusPill status="ready" label="Succeeded" />;
  if (call.status === 'cancelled') return <StatusPill status="disabled" label="Cancelled" />;
  if (call.status === 'pending') return <StatusPill status="running" label="In flight" />;
  return (
    <span className="inline-flex items-center gap-1.5">
      <StatusPill status="failed" label="Failed" />
      {call.error_class === null ? null : (
        <code className="font-mono text-caption text-muted-foreground">{call.error_class}</code>
      )}
    </span>
  );
}

function SummaryPair({ label, value }: { readonly label: string; readonly value: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  );
}

/**
 * THE EVIDENCE BEHIND AN ANSWER, WITH ITS EXCERPT RESOLVED.
 *
 * ── EVERY FIELD BUT `chunk_id` IS DENORMALIZED, SO A FOOTNOTE OUTLIVES ITS SOURCE ───────────────
 * `title`, `location` and `excerpt` were copied onto the citation row at write time, which is why
 * conversation review needs no `sources.view` grant and why a purged source leaves a readable
 * transcript behind. `chunk_id` goes `null` when that happens; the footnote survives deliberately,
 * because it is the record of what was SHOWN and losing it would rewrite history.
 *
 * ── THE EXCERPT IS HOSTILE DATA PERMANENTLY ─────────────────────────────────────────────────────
 * Uploaded or crawled content, rendered through the SAME sanitizer as model output, on this surface
 * as on every other. It is framed as a QUOTATION — indented, on `--card-inset` — rather than as the
 * bot speaking, which is `kb-ai-chat-ux`'s rule for an evidence preview and matters more here than
 * anywhere: the reader is an administrator deciding whether an answer was grounded.
 *
 * ── THE LOCATOR'S KEY SET DIFFERS BY SOURCE TYPE ────────────────────────────────────────────────
 * Page, slide, sheet, row range, url, anchor, heading path — and AN ABSENT LOCATOR IS AN ABSENT KEY,
 * never a null, because a null `page` on a slide is indistinguishable from page zero. So it is read
 * defensively and rendered as whatever it carries.
 */
function Citations({ citations }: { readonly citations: readonly TranscriptCitationResource[] }) {
  return (
    <section aria-label="Evidence" className="rounded-xl bg-card-inset p-card-pad-sm">
      <h4 className="mb-2 text-caption text-muted-foreground uppercase">
        {citations.length === 1 ? '1 source' : `${citations.length} sources`}
      </h4>
      <ol className="flex flex-col gap-3">
        {citations.map((citation) => (
          <li key={`${citation.label}:${citation.created_at}`} className="flex flex-col gap-1">
            <div className="flex flex-wrap items-baseline gap-2 text-sm">
              <span
                aria-hidden
                className="flex size-4 shrink-0 items-center justify-center rounded-full bg-card text-caption tabular-nums"
              >
                {citation.label}
              </span>
              {/* IT IS NOT THE SOURCE'S NAME: the retrieval payload carries no source title, and this
                  is the closest true statement the index can make about where the sentence came
                  from. A JSX child either way. */}
              <span className="font-medium">
                <span className="sr-only">Source {citation.label}: </span>
                {citation.title}
              </span>
              {locatorSummary(citation.location) === null ? null : (
                <span className="text-caption text-muted-foreground">
                  {locatorSummary(citation.location)}
                </span>
              )}
              {citation.chunk_id === null ? (
                <span className="text-caption text-muted-foreground">
                  the passage has since been purged
                </span>
              ) : null}
            </div>
            {/* THE QUOTATION, framed as one. Same sanitizer as model output. */}
            <blockquote className="border-l-2 border-border pl-3 text-sm text-muted-foreground">
              <AssistantProse text={citation.excerpt} />
            </blockquote>
          </li>
        ))}
      </ol>
    </section>
  );
}

/**
 * The locator as one line, or `null` when it carries nothing renderable.
 *
 * READ DEFENSIVELY AND WITH NO SCHEMA: the key set differs by source type and is not enumerated on
 * either side, so this renders `key value` pairs for the scalars it finds rather than reaching for
 * `location.page`. A hard-coded `page` would render nothing for a slide deck, a spreadsheet and a
 * crawled page — three of the five source kinds.
 */
function locatorSummary(location: Readonly<Record<string, unknown>>): string | null {
  const parts = Object.entries(location)
    .filter(([, value]) => typeof value === 'string' || typeof value === 'number')
    .map(([key, value]) => `${key.replaceAll('_', ' ')} ${String(value)}`);
  return parts.length === 0 ? null : parts.join(' · ');
}
