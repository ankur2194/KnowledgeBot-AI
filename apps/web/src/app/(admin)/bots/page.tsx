import { PlusIcon } from 'lucide-react';

import { DataTableShell } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { SkeletonLines } from '@/components/states';
import { Button } from '@/components/ui/button';

/**
 * Bots list. Placeholder chrome only — no fetch, no data, nothing org-scoped.
 *
 * When the table lands it is SERVER-DRIVEN: `manualSorting` + `manualPagination` + `rowCount` from
 * the envelope, `pageIndex` reset to 0 in the SAME state update as any sort or filter change
 * (under manual mode the table will never do it), and every server-visible sort, filter and page
 * value inside the query key after `['org', orgId, 'bots', …]`.
 *
 * The four states are `components/states.tsx` and ship WITH the success state, not after it — and
 * first-run empty is a different component from filtered empty, because offering "Create your first
 * bot" to somebody whose search matched nothing is the bug that split exists to prevent.
 */
export default function BotsPage() {
  return (
    <section aria-labelledby="bots-heading">
      <PageHeader
        title="Bots"
        titleId="bots-heading"
        description="Each bot answers from the sources you give it, in the voice you configure."
        actions={
          // At most one --primary action on a page header (rule 3).
          <Button>
            <PlusIcon aria-hidden />
            Add bot
          </Button>
        }
      />

      <DataTableShell>
        <SkeletonLines lines={5} className="p-card-pad-md" />
      </DataTableShell>

      <p className="mt-4 text-sm text-muted-foreground">
        Rows load in the browser. Nothing on this page is rendered by the Next.js server.
      </p>
    </section>
  );
}
