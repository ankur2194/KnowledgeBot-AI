/**
 * Per-group 404 for hosted chat. It must be byte-identical for "this bot does not exist", "this bot
 * is not published", and "you may not use this bot": on public surfaces the `authorization` class
 * renders as 404 precisely so a foreign identifier cannot confirm that a row exists.
 *
 * No bot name, no organization name, no identifier, and no link back into the product.
 */
export default function ChatNotFound() {
  return (
    <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-3 px-6">
      <h1 className="text-xl font-semibold">This chat is unavailable</h1>
      <p className="text-muted-foreground text-sm">
        The link may be incorrect, or the assistant may no longer be published.
      </p>
    </main>
  );
}
