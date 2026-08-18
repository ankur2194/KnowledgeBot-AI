import { LinkIcon } from 'lucide-react';
import Link from 'next/link';

import { EmptyState } from '@/components/states';
import { Button } from '@/components/ui/button';

/**
 * Per-group 404. Each of the three route groups has its own root layout, so each needs its own
 * not-found — one shared page would have to pick a layout, and picking either means the other
 * surfaces render the wrong <html>, the wrong CSP and the wrong caching posture.
 *
 * Nothing here names what was missing, and on this surface that matters more than on the others:
 * every route in this group is reachable by an anonymous visitor, so any answer more specific than
 * the sentence below is an enumeration oracle. A consumed invitation token, an expired reset token
 * and a token that never existed all land here and read identically.
 */
export default function AuthNotFound() {
  return (
    <section aria-labelledby="notfound-heading">
      <h1 id="notfound-heading" className="sr-only">
        Not found
      </h1>
      <EmptyState
        glyph={LinkIcon}
        title="That link is not valid"
        body="It may have expired or already been used."
        action={
          <Button variant="outline" asChild>
            <Link href="/login">Back to sign in</Link>
          </Button>
        }
      />
    </section>
  );
}
