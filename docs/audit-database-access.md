# Database access for the audit log

The development Compose file uses a database owner for migrations and tests. On the deployed stack, configure a dedicated PostgreSQL login for the API, queue workers and scheduler. The login can write audit entries and run the existing retention job. The migration owner manages the schema and its triggers.

1. As the PostgreSQL administrator, create a login with no role memberships, object ownership or elevated privileges. Set its password with `\password hrms_runtime` in psql. Keep the password in the deployment secret store.
2. After migrations, run the grants script as the role that owns the application tables and runs migrations. Use `psql -v runtime_role=hrms_runtime -f docker/postgres/grant-runtime.sql` against the HRMS database.
3. Set Laravel's `DB_USERNAME` and `DB_PASSWORD` for the deployed API, workers and scheduler to the runtime login and secret. Rebuild the config cache and restart those processes. Run migrations separately with the owner credentials.
4. Verify a normal application write creates its audit entry. In a staging database, connect as the runtime login and verify that `ALTER TABLE public.audit_log DISABLE TRIGGER ALL`, `TRUNCATE public.audit_log`, and `SET session_replication_role = replica` are refused.

Reapply the grants if a future migration recreates the audit table. For a deployment with pre-existing custom PostgreSQL grants, review effective grants and ownership before switching credentials.
