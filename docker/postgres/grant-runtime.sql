-- Run as the schema/migration owner after migrations, with -v runtime_role=<dedicated login>.
\set ON_ERROR_STOP on
BEGIN;

-- Require a dedicated login with no inherited roles, elevated attributes, or ownership.
SELECT EXISTS (
    SELECT 1
    FROM pg_roles AS r
    WHERE r.rolname = :'runtime_role'
      AND r.rolcanlogin AND NOT r.rolsuper AND NOT r.rolcreaterole
      AND NOT r.rolcreatedb AND NOT r.rolreplication AND NOT r.rolbypassrls
      AND NOT EXISTS (SELECT 1 FROM pg_auth_members WHERE member = r.oid)
      AND NOT EXISTS (
          SELECT 1 FROM pg_shdepend
          WHERE refclassid = 'pg_authid'::regclass AND refobjid = r.oid AND deptype = 'o'
      )
      AND NOT has_schema_privilege(r.oid, 'public', 'CREATE')
      AND NOT has_parameter_privilege(r.oid, 'session_replication_role', 'SET')
) AS safe_runtime_role \gset

\if :safe_runtime_role
GRANT USAGE ON SCHEMA public TO :"runtime_role";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"runtime_role";
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO :"runtime_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"runtime_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE ON SEQUENCES TO :"runtime_role";

REVOKE ALL ON TABLE public.audit_log FROM PUBLIC, :"runtime_role";
-- The retention trigger permits deletes only for entries older than one year.
GRANT SELECT, INSERT, DELETE ON TABLE public.audit_log TO :"runtime_role";
COMMIT;
\else
DO $$ BEGIN
    RAISE EXCEPTION 'Use a dedicated application login without elevated privileges, role memberships or object ownership';
END $$;
\endif
