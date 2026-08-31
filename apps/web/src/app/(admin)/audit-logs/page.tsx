import { PageHeader } from '@/components/page-header';
import { AuditScreen } from '@/features/audit/audit-screen';

/**
 * `/audit-logs` — this organization's audit trail (§18.11).
 *
 * A server component that fetches NOTHING org-scoped: a heading and one sentence, both
 * byte-identical for every tenant. Every row arrives from a browser fetch — `/audit-logs` is ONE URL
 * for every organization an administrator belongs to, and every Next cache is keyed by URL or by
 * arguments while the organization lives in the session cookie, which is in none of them.
 *
 * ── READ-ONLY, AND THERE IS NO ROW ROUTE ────────────────────────────────────────────────────────
 * There is deliberately no `/audit-logs/{id}`: the binding would resolve over a table whose model
 * carries no `#[ScopedBy]`, so it would load a row with no tenant predicate and hand it to a policy
 * that throws on a platform-scope row. Filtering the list by actor or by subject answers the same
 * questions through the one query shape that carries the organization predicate.
 *
 * THERE IS NO SERVER ACTION HERE AND THERE NEVER COULD BE: this surface has no write at all. Rows
 * are append-only — the application role holds no UPDATE and no DELETE, and retention is a partition
 * drop rather than a row delete.
 */
export default function AuditLogsPage() {
  return (
    <section aria-labelledby="audit-heading">
      <PageHeader
        title="Audit log"
        titleId="audit-heading"
        // ONE SENTENCE, and it says what the page is FOR rather than restating the title (P3). It
        // says "this organization" rather than "everything", because platform-scope rows are
        // deliberately excluded from this endpoint and a title claiming completeness would be false.
        description="Who did what in this organization, when, and from where."
      />
      <AuditScreen />
    </section>
  );
}
