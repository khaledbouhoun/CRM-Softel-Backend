# SOFTEL CRM — Laravel backend

REST API for the SOFTEL CRM: prospects, configurable sales pipeline, product
interests, activities, follow-ups, stage history, loss reasons and dashboard
statistics. It serves the separate Flutter Windows desktop client over the
company LAN — no internet, no cloud services, no external APIs.

| Document | Content |
|---|---|
| [docs/API.md](docs/API.md) | Full API contract for the Flutter developer (endpoints, fields, responses, errors) |
| [docs/DEPLOYMENT_LAN.md](docs/DEPLOYMENT_LAN.md) | Running the server on the LAN, Apache service, firewall, MySQL isolation |
| [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) | Daily backups, restore procedure, disaster recovery |
| [docs/MYSQL_ACCESS_RECOVERY.md](docs/MYSQL_ACCESS_RECOVERY.md) | Creating the CRM database/user (or resetting root) when the MySQL root password is unknown |

## Key design rules

- **No authentication, no users.** All employees see and edit the same data.
  There are no `users`/`employees` tables and no attribution columns. Access is
  limited to LAN addresses (`CRM_ALLOWED_NETWORKS`).
- **The backend is the single source of truth** for every business rule; the
  Flutter app's own validation is only a convenience.
- **Dynamic pipeline.** Stages live in the `sales_stages` table. Behaviour
  depends only on `stage_type` (`open`, `won`, `lost`), never on a stage name.
- **Lost requires a reason.** Entering a `lost` stage needs an active loss
  reason, plus a note when the reason has `requires_note`.
- **History is preserved.** Stage transitions are recorded in
  `prospect_stage_histories`; activities are append-only; prospects are
  soft-deleted; stages, products and loss reasons that are referenced cannot
  be deleted — they are deactivated instead, and remain readable everywhere.
- Quotations (devis) are out of scope.

## Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.2 – 8.4 (tested on 8.3.36, x64) | extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `intl` (and `pdo_sqlite` only to run tests on SQLite) |
| Composer | 2.x | only needed to install/update dependencies |
| MySQL | 8.0 or 8.4 | InnoDB, `utf8mb4` |
| Laravel | 12.x | installed by Composer |

Tested with Laravel 12.70.2, PHP 8.3.36 and MySQL 8.0.44 on Windows 11.

### Installing PHP on Windows

```powershell
winget install --id PHP.PHP.8.3 -e
```

Then create `php.ini` from `php.ini-production` in the PHP folder and enable:
`extension_dir = "ext"` (use the full path), `extension=curl`, `extension=fileinfo`,
`extension=intl`, `extension=mbstring`, `extension=openssl`, `extension=pdo_mysql`,
`extension=zip`, and set `date.timezone = UTC` (the application sets its own timezone).
Check with `php -m`.

## Installation

### 1. Database

As MySQL `root`:

```sql
CREATE DATABASE softel_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'softel_crm'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
GRANT ALL PRIVILEGES ON softel_crm.* TO 'softel_crm'@'localhost';

-- Only on a development machine, for the automated tests:
CREATE DATABASE softel_crm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON softel_crm_test.* TO 'softel_crm'@'localhost';
FLUSH PRIVILEGES;
```

The user is bound to `localhost`: only the API on the same machine can connect.
If the MySQL root password is unknown, follow [docs/MYSQL_ACCESS_RECOVERY.md](docs/MYSQL_ACCESS_RECOVERY.md).

### 2. Application

```powershell
cd "D:\path\to\Laravel Backend"
composer install --no-dev --optimize-autoloader   # omit --no-dev on a dev machine
copy .env.example .env
php artisan key:generate
```

Edit `.env` (see below), then:

```powershell
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
```

### 3. Initial data (seeders)

`php artisan db:seed` loads the starter values from [config/crm_seed.php](config/crm_seed.php):

- **sales stages** — a minimal pipeline (one `open`, one `won`, one `lost` stage).
  It is a placeholder, not the company's final pipeline: edit the list before the
  first seeding, or adjust stages later through the API / Flutter app.
- **loss reasons** — the reference examples (Trop cher, Pas intéressé, ...).
- **products** — none unless you list them.

Seeding is idempotent (matched by name, never overwrites), so it is safe to re-run.
After installation everything is managed through the API; no code change is needed.

## Environment configuration (.env)

| Variable | Example | Meaning |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | must be `false` in production |
| `APP_URL` | `http://192.168.1.10:8000` | address of the server on the LAN |
| `APP_TIMEZONE` | `Africa/Algiers` | business timezone: storage, "today", overdue computations |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | MySQL on the same machine |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `softel_crm` | quote the password if it contains `#` or spaces |
| `CRM_ALLOWED_NETWORKS` | `127.0.0.1,::1,192.168.1.0/24` | IPs/CIDR allowed to call the API, `*` = everyone |
| `CRM_RATE_LIMIT_PER_MINUTE` | `600` | per client IP |
| `CRM_ALLOW_REOPENING_CLOSED` | `true` | `false` freezes prospects in won/lost stages |
| `CRM_DASHBOARD_UPCOMING_DAYS` | `7` | window of the dashboard "upcoming follow-ups" count |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` / `warning` | logs in `storage/logs` |

After changing `.env` in production run `php artisan config:cache` again.

## Running

- Quick start / development: `php artisan serve --host=0.0.0.0 --port=8000`
  (PHP's built-in server handles one request at a time — fine for testing, not
  for daily use by the whole team).
- Production on the LAN: Apache as a Windows service — see
  [docs/DEPLOYMENT_LAN.md](docs/DEPLOYMENT_LAN.md).

Check from any employee computer: `http://<server-ip>:8000/api/v1/health`.

## Tests

Feature tests cover prospect creation/editing, multiple products, search and
filters, stage transitions (loss reason / note rules, won, inactive stage /
product / reason), stage history, activities, follow-ups (create, complete,
cancel, due scopes), dashboard statistics, configuration protections,
transaction rollback and error formatting.

```powershell
php artisan test
```

They run against the `softel_crm_test` MySQL database (see `phpunit.xml`); the
database is reset for each test. To run them on in-memory SQLite instead:

```powershell
$env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php artisan test
```

## Project structure

```
app/
  Casts/LocalDateTime.php           business date-times in APP_TIMEZONE
  Enums/                            StageType, ActivityType, FollowUpStatus
  Exceptions/                       BusinessRuleException, ApiExceptionRenderer
  Http/
    Controllers/Api/V1/             thin controllers
    Middleware/RestrictToAllowedNetworks.php
    Requests/Api/V1/                Form Requests (input validation)
    Resources/Api/V1/               API Resources (response shapes)
    Responses/ApiResponse.php       the JSON envelope
  Models/                           Eloquent models + relationships
  Repositories/                     list/filter/pagination queries
  Services/                         business rules (prospects, stage transitions,
                                    follow-ups, activities, configuration, dashboard)
config/crm.php                      runtime settings
config/crm_seed.php                 initial stages / loss reasons / products
database/migrations/                schema
database/seeders/                   config-driven seeders
routes/api.php                      /api/v1 routes
scripts/                            backup and restore (PowerShell)
tests/Feature/                      automated tests
```

## Database schema

| Table | Purpose | Notes |
|---|---|---|
| `sales_stages` | pipeline | unique `name`, `stage_type` open/won/lost, `sort_order`, `is_active` |
| `loss_reasons` | why a prospect was lost | unique `name`, `requires_note`, `sort_order`, `is_active` |
| `products` | products/software offered | unique `name`, `is_active` |
| `prospects` | potential customers | FK `sales_stage_id`, FK `loss_reason_id` (set only while in a lost stage), soft deletes |
| `prospect_products` | prospect ↔ product interest | unique (`prospect_id`, `product_id`); product FK `RESTRICT` |
| `activities` | calls, meetings, messages, notes | append-only; prospect FK `RESTRICT` |
| `follow_up_tasks` | scheduled follow-ups | `status` pending/completed/cancelled, `completed_at`, `cancelled_at`, `closing_note` |
| `prospect_stage_histories` | every stage transition | `from_stage_id` (null for the initial stage), `to_stage_id`, loss reason/note, `changed_at` |

Foreign keys use `RESTRICT` so referenced stages, reasons, products and history
can never be removed by accident, even outside the API. Indexes cover every
filter and sort column (stage, name, phone, email, city, dates, status, pivot).
Laravel's `cache`/`jobs` tables are also created but unused with the default `.env`.
