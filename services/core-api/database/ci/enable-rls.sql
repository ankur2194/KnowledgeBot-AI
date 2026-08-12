-- =====================================================================================
-- CI-ONLY ROW LEVEL SECURITY TRIPWIRE
--
-- THIS IS A DETECTOR, NEVER AN AUTHORIZATION MECHANISM. There is no RLS in production
-- (postgresql-patterns, "Row-level security: not in production"): the tenant GUC does not survive
-- PgBouncer transaction pooling, current_setting() outside a transaction is NULL so the policy
-- filters everything and the instinctive fix is a COALESCE that fails OPEN, and the owning role
-- bypasses its own policies. Isolation is enforced in code at seven layers.
--
-- What this file buys in CI is one thing the seven layers cannot buy themselves: an UNSCOPED QUERY
-- RETURNS FEWER ROWS, so the isolation test fails on the query that forgot its organization
-- predicate — including a raw query-builder call that the arch tests and the greps missed.
-- (Spelling that call out literally here would trip the tenancy grep, which scans this whole
-- directory and cannot tell prose from code.)
--
-- HOW IT IS RUN: against the CI database only, after `php artisan migrate`, with the test suite
-- connecting as kb_ci_app rather than as the migration owner.
--
-- THE ONE THING THAT WOULD MAKE THIS PERMANENTLY GREEN AND PROVE NOTHING:
-- the table OWNER bypasses row level security SILENTLY. `ENABLE ROW LEVEL SECURITY` alone leaves
-- every policy vacuous for the role that created the table, while pg_policies still lists the
-- policy and looks reassuring. `FORCE ROW LEVEL SECURITY` is what applies the policy to the owner
-- too — and running the suite as a NON-OWNER (kb_ci_app) is the second half of the same guard.
-- Both are below. Removing either one turns this file into decoration.
--
-- SCHEMA-AGNOSTIC BY CONSTRUCTION: it names no table. It arms every BASE TABLE in `public` that
-- has an `organization_id` column, so with zero migrations it arms zero tables and exits 0, and it
-- arms itself as migrations land. A new tenant-owned table is covered the day it is created,
-- without anyone remembering to edit this file — which is the only version of this that survives.
-- =====================================================================================

\set ON_ERROR_STOP on

-- 1. The non-owner application role the test suite connects as.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'kb_ci_app') THEN
        CREATE ROLE kb_ci_app LOGIN PASSWORD 'kb_ci_app';
    END IF;
END
$$;

GRANT USAGE ON SCHEMA public TO kb_ci_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kb_ci_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kb_ci_app;

-- Tables created by a later migration must be reachable too, or a new table silently fails the
-- suite with a permission error that reads like an RLS denial.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kb_ci_app;

-- audit_logs is append-only and outlives the record it describes: the application role must never
-- hold UPDATE or DELETE on it (postgresql-patterns). Applied here too so CI proves it.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = 'audit_logs'
    ) THEN
        REVOKE UPDATE, DELETE ON public.audit_logs FROM kb_ci_app;
    END IF;
END
$$;

-- 2. Arm every table that carries a tenant column.
--
-- BOTH spellings, deliberately. Laravel's tables use `organization_id`; the four derived tables
-- the data plane writes — chunks, document_elements, retrieval_traces, evaluation_results — carry
-- `org_id`, which is also the Qdrant payload spelling. Matching only `organization_id` skips all
-- four in silence, and the tables_armed count below still looks healthy because it counts what
-- was armed rather than what should have been. A table with neither column is not tenant-owned
-- and is correctly skipped.
DO $$
DECLARE
    target record;
BEGIN
    FOR target IN
        SELECT c.table_name, c.column_name
        FROM information_schema.columns AS c
        JOIN information_schema.tables AS t
          ON t.table_schema = c.table_schema
         AND t.table_name  = c.table_name
        WHERE c.table_schema = 'public'
          AND c.column_name IN ('organization_id', 'org_id')
          AND t.table_type   = 'BASE TABLE'
        ORDER BY c.table_name
    LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', target.table_name);

        -- Without FORCE the owner bypasses the policy and this whole file is a no-op that looks
        -- like coverage. See the header.
        EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY', target.table_name);

        EXECUTE format('DROP POLICY IF EXISTS kb_ci_org_isolation ON public.%I', target.table_name);

        -- current_setting(..., true) returns NULL when unset, and `col = NULL` is NULL, so an
        -- unset GUC denies every row. That is intentional and it is why this must never be
        -- production authorization: the app would break loudly and the tempting fix fails open.
        EXECUTE format(
            'CREATE POLICY kb_ci_org_isolation ON public.%I '
            'USING (%I = current_setting(''kb.org_id'', true)) '
            'WITH CHECK (%I = current_setting(''kb.org_id'', true))',
            target.table_name, target.column_name, target.column_name
        );

        RAISE NOTICE 'RLS armed on public.% (via %)', target.table_name, target.column_name;
    END LOOP;
END
$$;

-- 3. Report what was armed, so a CI log shows zero on an empty tree and grows with the schema.
SELECT count(*) AS tables_armed
FROM pg_policies
WHERE schemaname = 'public' AND policyname = 'kb_ci_org_isolation';
