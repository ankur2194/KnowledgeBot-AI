import type { Metadata } from 'next';

import { HostedChat } from '@/features/chat/hosted-chat';

/**
 * Hosted chat for one public bot.
 *
 * CACHE DECISION, and it is the opposite of the admin console's on purpose: everything this route
 * renders is keyed by `publicBotId`, which IS in the URL, so nothing here can vary by an
 * organization the URL does not name.
 *
 * The PAGE renders dynamically, because proxy.ts mints a per-response CSP nonce and a nonce baked
 * into a cached HTML document is a nonce that no longer matches. What is genuinely worth caching is
 * cached where the key is honest:
 *   - the public bot configuration, via
 *     `fetch(url, { next: { tags: [`bot:${publicBotId}`], revalidate: 60 } })`, expired on publish
 *     with `revalidateTag(tag, profile)` — note the cacheLife profile argument is REQUIRED since
 *     16.0 and the one-argument form is a type error;
 *   - the palette, as a real stylesheet response at ./theme.css.
 *
 * THE MOMENT a bot is set to password-protected or authenticated-organization mode (§8.19) its
 * content varies by something not in its URL and this route moves to the admin posture. That is the
 * whole test, applied per route.
 *
 * Everything past first paint — session token, conversation, stream — is per-visitor and lives in a
 * client component that talks straight to Laravel.
 */
export const dynamic = 'force-dynamic';

export async function generateMetadata({
  params,
}: {
  params: Promise<{ publicBotId: string }>;
}): Promise<Metadata> {
  // generateMetadata runs BEFORE any access gate, and §8.19 lets an organization set a custom page
  // title. For anything other than a fully public bot this must stay generic, or <title> answers
  // the question the byte-identical 404 exists to refuse. Until the access mode is fetched, generic
  // is the only safe answer.
  await params;
  return { title: 'Chat', robots: { index: false, follow: false } };
}

export default async function HostedChatPage({
  params,
}: {
  params: Promise<{ publicBotId: string }>;
}) {
  // Params are a Promise in Next 16 (async request APIs).
  const { publicBotId } = await params;

  return (
    // The chat column: 48rem centred at desktop, full width on a phone with the composer pinned to
    // the bottom inset (kb-ui-patterns, *Density and responsive*). `min-h-dvh` + `min-h-0` is what
    // lets the transcript scroll inside its own box instead of scrolling the page.
    <main className="mx-auto flex h-dvh w-full max-w-3xl flex-col gap-4 px-gutter-sm py-4">
      {/* The palette is a same-origin stylesheet: `style-src 'self'` covers it with no nonce, and
          the response is cacheable by publicBotId alone. A nonced inline <style> could not be
          either of those things at once. */}
      <link rel="stylesheet" href={`/c/${encodeURIComponent(publicBotId)}/theme.css`} />

      <h1 className="sr-only">Chat</h1>

      {/* <HostedChat> is 'use client', and the boundary sits at the first thing that varies per
          visitor. It owns the `sdk/v1` handshake, the chat-session bearer, the conversation, the
          transcript, the composer, the AbortController behind the stop-generation action, and
          streamAnswer() straight to Laravel. Never back through Next, and never a Server Action.

          THIS PROP IS THE WHOLE OF WHAT THE SERVER HANDS IT, and that is the property rather than a
          coincidence: `publicBotId` came out of the URL, so nothing crossing this boundary varies by
          anything the URL does not name. A server component that fetched the bot configuration here
          would put it in the RSC payload — the WHOLE object, not the fields anybody reads — and
          would make this route's cacheability a question about a body instead of about a path.

          `conversation={null}` USED TO LIVE HERE with a note saying the runtime was out of scope.
          The runtime landed (`sdk/v1` bootstrap and mint, `rt/v1` conversations, messages, history,
          citations and feedback), so the note is gone and the surface connects. What did NOT land is
          a way to record consent, and `HostedChat` states that in place for a bot that needs it —
          see the paragraph headed A CONSENT-REQUIRED BOT there. */}
      <HostedChat publicBotId={publicBotId} />
    </main>
  );
}
