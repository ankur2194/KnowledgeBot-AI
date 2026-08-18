import type { Metadata } from 'next';

import { ChatSurface } from '@/features/chat/chat-surface';

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

      {/* <ChatSurface> is 'use client', and the boundary sits at the first thing that varies per
          visitor. It owns the transcript, the composer, the AbortController behind the
          stop-generation action, and streamAnswer() straight to Laravel. Never back through Next,
          and never a Server Action.
        
          `conversation={null}` is HONEST, not a placeholder: a send needs a conversation id and a
          chat-session credential, both minted by the `sdk/v1` bootstrap, which is deliberately out
          of scope for this repo. The surface renders its real first-run state and states the
          condition in place rather than offering a composer that silently does nothing. The bot
          name and suggestions come from the public bot configuration on the same `rt/v1` surface as
          the palette; until it exists they are the generic defaults below. */}
      <ChatSurface botName="this assistant" conversation={null} />
    </main>
  );
}
