# Mirror the CRM's stack and conventions

The HRMS will later integrate with the company's Laravel CRM (github.com/mr-h12/smart-technology-CRM), and the same developer built it. So we copy its stack and conventions instead of the generic Laravel choices first written in CLAUDE.md:

- Laravel 13 / PHP 8.4, PostgreSQL 17, Redis queues, Docker.
- `/api/v1`, UUIDv7 ids and the CRM response envelope.
- Modules split into Domain/Application/Infrastructure/Presentation, with deptrac and PHPStan.
- PHPUnit, not Pest.
- The CRM's custom `resource.action.scope` RBAC, not spatie/laravel-permission.
- Its append-only audit log.
- `NUMERIC(18,6)` money with bcmath, not `numeric(12,2)`.
- browsershot PDFs on a queue.

The frontend keeps the HRMS demo's look (Tailwind 4, shadcn/Radix, the shared tokens) but uses react-i18next instead of the demo's DOM-rewriting translator.

## Consequences
- `NUMERIC(18,6)` also covers the "no intermediate rounding" rule needed to match the Excel results.
- The CRM's login (bearer session, lockout, device list) is reused, but without self-service password change: only the Developer role sets passwords.
