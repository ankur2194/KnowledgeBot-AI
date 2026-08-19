import { PageHeader } from '@/components/page-header';
import { CreateBotDialog } from '@/features/bots/bot-create-dialog';
import { BotsScreen } from '@/features/bots/bots-screen';

/**
 * Bots for the current organization.
 *
 * ── THIS SERVER COMPONENT PRODUCES NO ORGANIZATION-SCOPED BYTE, AND THAT IS THE POINT ───────────
 * `/bots` is ONE URL for every organization an administrator belongs to, because the organization
 * lives in the session cookie and is in none of Next's five cache keys — not the Full Route Cache, not
 * the Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. So everything rendered
 * HERE is byte-identical for every tenant: a heading, one sentence, an action, and a client component
 * that renders a skeleton until a browser fetch answers. There is no `serverFetch` on this route and
 * there cannot be — `src/lib/api/server.ts` deliberately does not forward the session cookie, so
 * identity, and therefore the organization, is unreadable from the Next server by construction.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans the `'force-static'` literal in this directory: that value does not ERROR when a
 * route reads request state, it makes the read return an EMPTY value and the Full Route Cache then
 * serves one shell to every organization. Belt, not mechanism — the mechanism is that the browser is
 * the fetcher.
 *
 * ── WHAT THE CLIENT COMPONENT BELOW IS REQUIRED TO DO, AND WHERE EACH PART LIVES ────────────────
 * The table is SERVER-DRIVEN: `manualSorting` + `manualPagination` + `rowCount` from the envelope
 * (`components/server-data-table.tsx`), `pageIndex` reset to 0 in the SAME state update as any sort or
 * filter change (`lib/table/use-table-params.ts`, where the whole view is one value written in one
 * navigation, so there is no expression for the other thing), and every server-visible sort, filter
 * and page value inside the query key after `['org', orgId, 'bots', …]`
 * (`features/bots/bots-screen.tsx`).
 *
 * The four states ship WITH the success state — and first-run empty is a different COMPONENT from
 * filtered empty (`components/states.tsx`), because offering "create your first bot" to somebody whose
 * search matched nothing is the bug that split exists to prevent.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ──────────────────────────────────────
 * `<CreateBotDialog>` is the worked example: its POST is a `useMutation` calling `browserFetch`, and
 * the only thing this server component contributes to it is the button's label.
 *
 * Creating, editing, publishing and deleting a bot are mutations Laravel rate-limits
 * (`throttle:admin`), authorizes through `bots.manage`, refuses on a suspended organization, and
 * writes audit rows for. An action would bypass all four: a second business backend by accident. Every
 * mutation is a browser `fetch` to Laravel.
 */
export default function BotsPage() {
  return (
    <section aria-labelledby="bots-heading">
      <PageHeader
        title="Bots"
        titleId="bots-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3).
        description="Each bot answers from the sources you give it, in the voice you configure."
        actions={
          /**
           * At most one --primary action on a page header (rule 3), and this is it.
           *
           * ── A CLIENT COMPONENT AS A PROP OF A SERVER COMPONENT, AND IT RESOLVES ITS OWN ORG ────
           * The create path is a browser fetch to Laravel and needs the current organization, which
           * THIS FILE MUST NEVER READ: the org lives in the session cookie and is in none of Next's
           * cache keys, so a server component that read it would be producing an organization-scoped
           * byte on a URL that is one address for every tenant. `<CreateBotDialog>` takes no orgId —
           * it reads the session itself and renders NOTHING when there is no current organization or
           * when the viewer lacks `bots.manage`. So the RSC payload this page produces stays
           * byte-identical for every organization, and it does not even state whether the visitor may
           * create a bot.
           *
           * The same component is mounted a second time inside the first-run empty state, with a
           * different trigger label — see `bots-screen.tsx`. Neither name contains the other, which
           * is what keeps two simultaneously-mounted triggers addressable by name.
           */
          <CreateBotDialog triggerLabel="Add bot" />
        }
      />

      <BotsScreen />
    </section>
  );
}
