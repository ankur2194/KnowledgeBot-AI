import type { Metadata } from 'next';

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
    <main className="mx-auto flex min-h-dvh max-w-2xl flex-col px-4 py-6">
      {/* The palette is a same-origin stylesheet: `style-src 'self'` covers it with no nonce, and
          the response is cacheable by publicBotId alone. A nonced inline <style> could not be
          either of those things at once. */}
      <link rel="stylesheet" href={`/c/${encodeURIComponent(publicBotId)}/theme.css`} />

      <h1 className="sr-only">Chat</h1>

      <div aria-hidden className="flex-1 space-y-3">
        <div className="bg-muted h-16 animate-pulse rounded-lg" />
        <div className="bg-muted h-24 animate-pulse rounded-lg" />
      </div>

      {/* <ChatSurface> — 'use client', and the boundary sits at the first thing that varies per
          visitor. It owns the transcript, the composer, the AbortController behind the
          stop-generation action, and streamAnswer() straight to Laravel. Never back through Next,
          and never a Server Action. */}
      <p className="text-muted-foreground mt-6 text-sm">The chat surface is not implemented yet.</p>
    </main>
  );
}
