/**
 * The audit trail — `GET /api/v1/organizations/{organization}/audit-logs`.
 *
 * TYPES ONLY, ZERO RUNTIME VALUES: re-exported from `src/index.ts` under the <=1 kB brotli budget.
 * The operation vocabulary a filter iterates is NOT declared here as a tuple — it is read from
 * `packages/contracts/rules/IndexAuditLogsRequest.json`'s own `in:` rule at the call site, which is
 * the same discipline `SOURCE_SORTABLE_COLUMNS` follows: a hand-copied list of 45 operation names is
 * a 422 one dropdown click away the moment the server's set moves.
 *
 * ── READ-ONLY, AND THERE IS NO ROW ROUTE ────────────────────────────────────────────────────────
 * Rows are APPEND-ONLY — the application role holds no UPDATE and no DELETE, and retention is a
 * partition drop rather than a row delete — so an id read here names a row that will never change.
 * There is deliberately no `/audit-logs/{auditLog}`: the binding would resolve over a table whose
 * model carries no `#[ScopedBy]`, so it would load a row with no tenant predicate and hand it to a
 * policy that throws on a platform-scope row.
 *
 * ── PLATFORM-SCOPE ROWS ARE NEVER RETURNED, AND A UI MUST NOT IMPLY OTHERWISE ───────────────────
 * `organization_id IS NULL` rows — a failed login for an address belonging to no user, a platform
 * action — are excluded by the repository, and the exclusion is a DECISION rather than an accident
 * of `organization_id = ?` being false for a NULL: the repository, the interface and a Security test
 * all state it. §6.1 assigns those rows to the platform owner, on a surface that does not exist.
 * A screen that calls itself "everything that happened" is therefore wrong; it is this
 * organization's trail.
 *
 * ── TWO FIELDS ARE HOSTILE TEXT AND ONE IS PII ABOUT A COLLEAGUE ────────────────────────────────
 * `user_agent` is attacker-controlled free text on the unauthenticated paths; `details` is an open
 * object whose key set differs per operation; `ip_address` is personal data about a member, which is
 * part of why `audit.view` is withheld from the reporting and ingestion roles. All three are JSX
 * children or `<code>` text — never markup, never a link, never auto-loaded.
 */

import type { ListMetaResource } from './bots.js';

/**
 * Success or failure, DERIVED FROM THE OPERATION and never submitted — so no row can claim
 * `auth.login.failed` with `outcome: 'success'`. Two operations rather than one with a varying
 * outcome is the pattern this trail uses wherever both endings are auditable.
 */
export type AuditOutcome = 'success' | 'failure';

/**
 * One audited event.
 *
 * `operation` is typed `string` and NOT a union of the 45 names, deliberately and unlike every other
 * closed vocabulary in this package. The set is published in two places already — the resource's
 * inlined `enum` in the OpenAPI document, and the `in:` rule of `IndexAuditLogsRequest` — and a
 * third transcription here is the copy that goes stale, because nothing would compare it to either.
 * `test/resource-drift.test.ts` pins the two published spellings against each other; a client reads
 * the rule manifest.
 */
export interface AuditLogResource {
  readonly id: string;
  /** From a CLOSED vocabulary the WRITER enforces: an operation not in its map is refused, because
   *  an unmapped name means nobody has decided what that event may record. */
  readonly operation: string;
  readonly outcome: AuditOutcome;
  /**
   * The member who acted, or `null` for an unauthenticated event (a failed login) and for anything
   * the platform did with no person behind it.
   *
   * THERE IS NO FOREIGN KEY: the actor may be deleted and the row must remain, which is what
   * "append-only and outlives the record it describes" means. So a client cannot assume this id
   * resolves to a member of the current organization, and a screen that joins it to the member list
   * has to render an unresolved id as itself rather than as blank.
   */
  readonly actor_id: string | null;
  /**
   * What was acted ON, as the fully-qualified model class — `App\Models\Bot`.
   *
   * THE SET IS OPEN, unlike `operation`: auditing a new kind of record needs no schema change. Treat
   * it as an opaque discriminator and read values off rows rather than composing them. `null`
   * exactly when `subject_id` is null; the database refuses a half-specified pair.
   */
  readonly subject_type: string | null;
  readonly subject_id: string | null;
  /** IPv4 or IPv6 literal. PII about a colleague. `null` for anything raised by a queued job or a
   *  console command. */
  readonly ip_address: string | null;
  /** Truncated at 512 characters. ATTACKER-CONTROLLED FREE TEXT on the unauthenticated paths. */
  readonly user_agent: string | null;
  /** The bridge to telemetry, and the only one: this row and the log lines for the same request
   *  carry the same value. */
  readonly request_id: string | null;
  /**
   * What the operation recorded, ALLOW-LISTED PER OPERATION AT WRITE TIME. The key set differs by
   * operation and is not enumerated — READ DEFENSIVELY.
   *
   * NO CREDENTIAL, TOKEN OR KEY FRAGMENT CAN APPEAR: an unlisted field never reaches the table, and
   * every bearer value is admitted only as `<name>_fingerprint`, a keyed HMAC that answers "was THIS
   * the value" without being derivable back to it. `{}` when the operation records nothing beyond
   * its columns, which is the honest shape for something like a logout.
   */
  readonly details: Readonly<Record<string, unknown>>;
  /** When the event was recorded. Also the PARTITION KEY, which is why the default sort and every
   *  index on this table are built around it. */
  readonly created_at: string;
}

export interface AuditLogCollectionResource {
  readonly audit_logs: readonly AuditLogResource[];
  readonly meta: ListMetaResource;
}
