import Link from 'next/link';

/**
 * Per-group 404. The admin console and hosted chat have separate root layouts, so each group needs
 * its own not-found — one shared page would have to pick a layout, and picking either means the
 * other surface renders the wrong <html>, the wrong CSP, and the wrong caching posture.
 *
 * Nothing here names the resource that was missing. On authenticated admin surfaces a foreign
 * identifier is an `authorization` failure rendered as 403; on public surfaces the same class
 * renders as 404 precisely so it cannot be used as an enumeration oracle. Neither answer is
 * composed in this file.
 */
export default function AdminNotFound() {
  return (
    <section aria-labelledby="notfound-heading" className="space-y-4">
      <h1 id="notfound-heading" className="text-2xl font-semibold">
        Not found
      </h1>
      <p className="text-muted-foreground text-sm">
        That page does not exist, or you do not have access to it.
      </p>
      <Link href="/" className="text-sm underline">
        Back to overview
      </Link>
    </section>
  );
}
