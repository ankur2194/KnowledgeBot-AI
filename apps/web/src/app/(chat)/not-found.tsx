/**
 * Per-group 404 for hosted chat. It must be byte-identical for "this bot does not exist", "this bot
 * is not published", and "you may not use this bot": on public surfaces the `authorization` class
 * renders as 404 precisely so a foreign identifier cannot confirm that a row exists.
 *
 * No bot name, no organization name, no identifier, and no link back into the product.
 */
export default function ChatNotFound() {
  return (
    <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-gutter-sm">
      {/* A card, because content never floats directly on the canvas (rule 1) — and no action,
          because there is nowhere on this surface to send an anonymous visitor. */}
      <div className="flex flex-col gap-2 rounded-2xl bg-card p-card-pad-lg shadow-md">
        <h1 className="text-h2">This chat is unavailable</h1>
        <p className="text-base text-muted-foreground">
          The link may be incorrect, or the assistant may no longer be published.
        </p>
      </div>
    </main>
  );
}
