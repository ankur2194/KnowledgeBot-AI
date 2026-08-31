import { PageHeader } from '@/components/page-header';
import { ConversationDetailScreen } from '@/features/conversations/conversation-detail-screen';

/**
 * `/conversations/{conversationId}` — one thread, whole.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THE ID IN THE URL IS WHAT
 *    MAKES THAT EASY TO GET WRONG ────────────────────────────────────────────────────────────────
 * A path with an identifier in it is the shape that LOOKS cacheable. It is not: every Next cache is
 * keyed by URL or by arguments and the ORGANIZATION lives in the session cookie, which is in none of
 * them. A URL-keyed entry here would carry half a key, which is worse than none because it looks
 * specific — a conversation id is a ULID rather than a secret, and two admins in two organizations
 * resolve the same path.
 *
 * So everything rendered HERE is byte-identical for every tenant: a heading, one sentence, and a
 * client component that renders a skeleton until a browser fetch answers. NOT ONE WORD OF THE
 * TRANSCRIPT IS IN THIS FILE and none can be — it is untrusted end-user and model text, and it
 * reaches the DOM only through the shared sanitizer inside the client component.
 *
 * There is no `serverFetch` on this route and there cannot be: `src/lib/api/server.ts` deliberately
 * does not forward the session cookie, because a server-side fetch sends no `Referer`/`Origin`,
 * Sanctum's `fromFrontend()` classifies it third-party, and a perfectly valid cookie is ignored — a
 * 401 that cannot reproduce in devtools.
 *
 * `generateStaticParams` could not exist here anyway: enumerating the params would mean listing one
 * organization's conversation ids at build time.
 */
export default async function ConversationDetailPage({
  params,
}: {
  readonly params: Promise<{ readonly conversationId: string }>;
}) {
  // `params` IS A PROMISE IN NEXT 16 and awaiting it is not optional: the synchronous form was
  // removed, so reading `params.conversationId` directly is a type error rather than a deprecation
  // warning. Awaiting it also makes this route dynamic on its own, independently of the layout's
  // `force-dynamic` — which is belt rather than mechanism, exactly as that pin is.
  const { conversationId } = await params;

  return (
    <section aria-labelledby="conversation-heading">
      <PageHeader
        title="Conversation"
        titleId="conversation-heading"
        // It names no thread and no organization: this is server-rendered chrome and must be
        // byte-identical for every tenant, so the thread's own id is an h2 inside the client
        // component, from the fetched row.
        description="Everything that was said, the evidence behind each answer, and what each turn cost."
      />
      <ConversationDetailScreen conversationId={conversationId} />
    </section>
  );
}
