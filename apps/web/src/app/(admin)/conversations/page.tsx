import { PageHeader } from '@/components/page-header';
import { ConversationsScreen } from '@/features/conversations/conversations-screen';

/**
 * `/conversations` — every thread this organization's bots have held (§18.11 review).
 *
 * A server component that fetches NOTHING org-scoped: a heading and one sentence, both
 * byte-identical for every tenant. Every row arrives from a browser fetch — `/conversations` is ONE
 * URL for every organization an administrator belongs to, and every Next cache is keyed by URL or by
 * arguments while the organization lives in the session cookie, which is in none of them.
 *
 * ── READ-ONLY, AND THAT IS A PROPERTY OF THE SURFACE RATHER THAN A GAP ──────────────────────────
 * Two endpoints and no write. A thread is opened by the public runtime, written by the relay's
 * finalizer, and removed by the retention sweeper; §18.11 treats a transcript as EVIDENCE, so a
 * surface that could both read and destroy it would be able to rewrite the record it exists to
 * preserve. There is no Server Action here and there could not be one.
 *
 * ── AND THERE IS NO PRIVACY TOGGLE, BECAUSE THERE IS NO COLUMN FOR ONE ──────────────────────────
 * §18.10's per-organization "may administrators review conversations" switch has no storage:
 * `organizations.settings` carries no key vocabulary and no reader, so the transcript endpoint ships
 * ungated and `ConversationController` records the measurement rather than inventing one. A control
 * here would tell an operator they had made a decision the platform has no way to honour.
 */
export default function ConversationsPage() {
  return (
    <section aria-labelledby="conversations-heading">
      <PageHeader
        title="Conversations"
        titleId="conversations-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3).
        description="What people asked your bots, what the bots answered, and what it cost."
      />
      <ConversationsScreen />
    </section>
  );
}
