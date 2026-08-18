import { PlusIcon } from 'lucide-react';

import { DataTableShell } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { SkeletonLines } from '@/components/states';
import { Button } from '@/components/ui/button';

/**
 * Knowledge sources list. Placeholder chrome only — no fetch, no data, nothing org-scoped.
 *
 * When the table lands: polling uses the FUNCTION form of `refetchInterval` and returns false once
 * every row is terminal (a bare number never stops, and a hidden tab polling for hours is what
 * starts rejecting real requests); `placeholderData` uses the function form and compares
 * `prevQuery.queryKey[1]` to the current orgId, because keepPreviousData renders the previous
 * KEY's data and once orgId is in the key the previous key is the previous organization.
 *
 * Deletion and disable are never optimistic: they are two-phase and verified, and the browser
 * cannot know the purge succeeded. Delete goes through `<ConfirmDestructiveDialog>`, which states
 * the consequence in numbers and requires the source's name typed — clicking twice in the same
 * place is muscle memory, typing a name is not.
 */
export default function SourcesPage() {
  return (
    <section aria-labelledby="sources-heading">
      <PageHeader
        title="Sources"
        titleId="sources-heading"
        description="Documents, spreadsheets, presentations and crawled sites your bots can answer from."
        actions={
          <Button>
            <PlusIcon aria-hidden />
            Add source
          </Button>
        }
      />

      <DataTableShell>
        <SkeletonLines lines={6} className="p-card-pad-md" />
      </DataTableShell>

      <p className="mt-4 text-sm text-muted-foreground">
        Rows load in the browser. Nothing on this page is rendered by the Next.js server.
      </p>
    </section>
  );
}
