import { PageHeader } from '@/components/page-header';
import { QuotasScreen } from '@/features/quotas/quotas-screen';

/**
 * `/quotas` — usage against ceilings, under Organization.
 *
 * A server component that fetches NOTHING org-scoped: a heading and one sentence, both
 * byte-identical for every tenant. Every figure arrives from a browser fetch
 * (`nextjs-app-router` NN1) — `/quotas` is ONE URL for every organization an administrator belongs
 * to, and every Next cache is keyed by URL or by arguments while the organization lives in the
 * session cookie, which is in none of them.
 *
 * `(admin)/layout.tsx` pins `dynamic = 'force-dynamic'` and `revalidate = 0` for every route beneath
 * it, and ESLint bans `'force-static'`, `generateStaticParams` and the `fetchCache` override in this
 * directory. Belt, not mechanism — the mechanism is that the browser is the fetcher.
 *
 * THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE. Changing a ceiling is a mutation Laravel
 * rate-limits (`throttle:admin`), authorizes through `quotas.manage`, refuses on a suspended
 * organization, re-checks for direction against the platform-owner flag, and writes an audit row
 * for. An action would bypass all five.
 */
export default function QuotasPage() {
  return (
    <section aria-labelledby="quotas-heading">
      <PageHeader
        title="Quotas"
        titleId="quotas-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3). It
        // names no organization: this is server-rendered chrome and must be byte-identical for every
        // tenant.
        description="How much of this plan is in use, and where the ceilings are."
      />
      <QuotasScreen />
    </section>
  );
}
