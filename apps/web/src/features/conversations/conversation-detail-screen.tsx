'use client';

import type { Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon } from 'lucide-react';
import Link from 'next/link';

import { ErrorState, SkeletonLines } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent } from '@/components/ui/card';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { canViewConversations, fetchConversationTranscript } from '@/features/conversations/api';
import { ConversationTranscript } from '@/features/conversations/conversation-transcript';

/**
 * `/conversations/{conversationId}` — one thread, whole.
 *
 * ── THIS SCREEN PRODUCES NO ORGANIZATION-SCOPED BYTE ON THE NEXT SERVER ─────────────────────────
 * The route has an identifier in the URL, which makes it LOOK cacheable. It is not. Every Next cache
 * is keyed by URL or by arguments — Full Route Cache, Data Cache, Router Cache, `unstable_cache`,
 * `use cache` — and the ORGANIZATION lives in the session cookie, which is in none of them. A
 * URL-keyed entry here would carry half a key, which is worse than none because it looks specific: a
 * conversation id is a ULID rather than a secret, and two admins in two organizations resolve the
 * same path.
 *
 * ── THE PARAM IS A ROUTING HINT AND NOT A SCOPE ─────────────────────────────────────────────────
 * `{conversation}` resolves through `$organization->conversations()` because the route group calls
 * `->scopeBindings()`, and the organization it is scoped to comes from the SESSION. So a foreign id,
 * an unknown id, and an id belonging to the organization the admin just switched away from all 404
 * at BINDING time, before any policy runs and before the row is in memory — and the render closure
 * gives all three the same `authorization` class and the same body. Nothing here validates the
 * segment, and nothing should: a client-side ULID check would only turn a server refusal into a
 * different-looking one, and would answer a question the deny split exists to leave unanswered.
 *
 * ── FOUR STATES, AND TWO OF THEM ARE THE SAME RENDER ON PURPOSE ─────────────────────────────────
 *
 *   Loading    a skeleton at the loaded layout's shape, so the page does not reflow.
 *   Error      the class-mapped sentence plus the `request_id`, with the retry affordance gated on
 *              the envelope's own `retryable`.
 *   Forbidden  THE SAME RENDER for a viewer whose role CAN read conversations, and the honest answer
 *              rather than an omission: for them an `authorization` failure is a stale id, a thread
 *              in the organization they just left, or a revoked membership — three causes that are
 *              byte-identical on the wire, and `<ForbiddenState>` naming a role would point at the
 *              wrong door. A viewer whose role genuinely cannot read conversations gets the role
 *              named, because for them it really is the reason.
 *   Empty      DOES NOT EXIST for a single resource, and inventing one would be wrong: absence here
 *              is `authorization`, above. A thread with no MESSAGES is a different thing and the
 *              transcript renders it.
 *
 * NO POLLING. A thread can gain a turn while a reviewer reads it, and this screen still does not
 * poll: there is no terminal state to stop on (see `conversationPollInterval`), and a transcript
 * that rewrote itself under a reader mid-sentence would be worse than one that is a minute old.
 */
export function ConversationDetailScreen({
  conversationId,
}: {
  readonly conversationId: string;
}) {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return <SkeletonLines lines={4} />;
  }

  if (session.status !== 'authenticated') {
    return null;
  }

  if (orgId === null) {
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Conversations belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <TranscriptForOrganization
      orgId={orgId}
      conversationId={conversationId}
      viewerRole={membership?.role ?? null}
    />
  );
}

function TranscriptForOrganization({
  orgId,
  conversationId,
  viewerRole,
}: {
  readonly orgId: string;
  readonly conversationId: string;
  readonly viewerRole: Role | null;
}) {
  const orgKeyFor = useOrgKey();

  const transcript = useQuery({
    queryKey: orgKeyFor('conversations', conversationId),
    // `signal` forwarded: cancelling in-flight reads is step 2 of both logout and the organization
    // switch — exactly when another organization's transcript must not resolve.
    queryFn: ({ signal }) => fetchConversationTranscript(orgId, conversationId, signal),
  });

  const canView = canViewConversations(viewerRole);

  if (transcript.data !== undefined) {
    return <ConversationTranscript transcript={transcript.data} />;
  }

  return (
    <div className="flex flex-col gap-8">
      {/* THE WAY BACK RENDERS IN EVERY STATE, including the failure one — which is the state an
          unknown id lands on, and the state where an operator most needs a way out. */}
      <p>
        <Link
          href="/conversations"
          className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
        >
          <ArrowLeftIcon aria-hidden className="size-4" />
          All conversations
        </Link>
      </p>

      {transcript.error === null ? (
        <Card>
          <CardContent className="pt-6">
            <SkeletonLines lines={4} />
          </CardContent>
        </Card>
      ) : (
        <>
          <ErrorState
            error={transcript.error}
            title="This conversation could not be loaded"
            onRetry={() => void transcript.refetch()}
          />
          {canView ? null : (
            // SAID ONLY TO THE VIEWER FOR WHOM IT IS ACTUALLY TRUE. `conversations.view` is withheld
            // from the knowledge manager, and the sidebar offers this route to everybody — so for
            // them the role really is the reason. For every other viewer the same 403 has three
            // indistinguishable causes and this sentence would point at the wrong door.
            <p className="text-base text-muted-foreground">
              Reading a transcript needs the analyst, admin or owner role in this organization.
            </p>
          )}
        </>
      )}
    </div>
  );
}
