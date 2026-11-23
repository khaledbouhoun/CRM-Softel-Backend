# Contributing

Thanks for helping improve the SOFTEL CRM backend. This guide covers the local setup
and the conventions the codebase follows.

## Set up

1. Fork the repository on GitHub, then clone your fork:

   ```bash
   git clone https://github.com/<your-account>/CRM-Softel-Backend.git
   cd CRM-Softel-Backend
   ```

2. Install PHP 8.3+ with `pdo_sqlite`, `gd`, `mbstring`, `openssl`, `fileinfo`, `intl` and `zip` (add `pdo_mysql` to run against MySQL), and Composer 2.
3. Install dependencies and create your environment file:

   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

4. For a local database, either create a MySQL database as described in the [README](README.md#1-create-the-database), or use SQLite: set `DB_CONNECTION=sqlite`, remove the `DB_DATABASE` line (so the default `database/database.sqlite` is used) and create that empty file. Then:

   ```bash
   php artisan migrate --seed
   php artisan serve
   ```

   With `APP_ENV=local`, seeding also creates demo data.

## Branches and commits

- Branch from `main`: `feat/<short-name>`, `fix/<short-name>`, `docs/<short-name>`.
- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat(scope): …`, `fix(scope): …`, `docs: …`, `test: …`, `refactor: …`, `style: …`, `chore: …`, `ci: …`. Scopes used so far include `portal`, `billing`, `interventions`, `scheduling`, `products`, `api`.
- Keep each commit focused on one change.

## Coding conventions

- Follow the layers described in [ARCHITECTURE.md](ARCHITECTURE.md): validation in Form Requests, business rules in services, list queries in repositories, response shapes in resources. Keep controllers thin.
- Signal a broken business rule with `BusinessRuleException` and a stable error code; never return ad-hoc error JSON.
- Every response goes through the `ApiResponse` envelope.
- Schema changes are **new, additive migrations** with a working `down()`. Never edit a migration that has already run in production.
- Referenced configuration (stages, reasons, products) is deactivated, not deleted.
- New settings go in `config/crm.php` and, if read from the environment, in `.env.example`.
- Format with Laravel Pint before committing: `vendor/bin/pint`.

## Tests

Tests are feature tests in `tests/Feature` and run on in-memory SQLite:

```bash
php artisan test
vendor/bin/pint --test
```

Add or update tests for every behaviour change, including the failure paths
(validation, business rules, access control).

## Pull requests

1. Make sure tests and Pint pass locally.
2. Update the docs when you change an endpoint (`docs/API.md` or `docs/PORTAL_AND_WORKFLOWS.md`) or a setting (`.env.example`, README).
3. Add an entry under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
4. Open the pull request against `main` and fill in the template. Mention any migration and how it affects existing data.

For security issues, do not open a public issue; follow [SECURITY.md](SECURITY.md).
