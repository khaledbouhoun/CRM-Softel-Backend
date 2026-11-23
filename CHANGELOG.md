# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project has no tagged
releases yet, so entries are grouped by date from the Git history.

## [Unreleased]

### Added
- Project documentation: README rewrite, `ARCHITECTURE.md`, `CONTRIBUTING.md`, `SECURITY.md`, this changelog.
- GitHub Actions workflow running the test suite and Pint; issue and pull request templates.
- Missing upload variables (`CRM_UPLOAD_DISK`, `CRM_*_MAX_KB`) in `.env.example`.

### Fixed
- `php artisan db:seed` no longer inserts demo data when `APP_ENV=production`.

### Changed
- Code formatted with Laravel Pint (no behaviour change).
- `.claude/` and editor folders are no longer tracked.

## 2026-11-23

### Added
- Analytics report endpoint (`GET /api/v1/analytics`) and an extended dashboard summary.
- Workstation presence for employees and attribution of activities to the employee.
- Customer billing: maintenance subscriptions, renewal requests and quotes, invoice lifecycle, scanned receipts.
- Multilingual public product API (`/api/public/v1/products`) and catalogue management.
- Multilingual settings help and attachment streaming for the portal.
- More filters for customers, prospects, activities and follow-ups.

### Fixed
- Several portal workflow fixes.

## 2026-11-19

### Added
- Portal: Google sign-in and e-mail verification; customer onboarding and profile; wilaya list.
- Product packs, catalogue relations and product filtering.
- Product releases with installer uploads and downloads.
- Interventions: timeline attachments, live chat and closure workflow.
- Multilingual WhatsApp message templates and the customer's preferred language.
- Interactive settings help, demo data seeders and further portal routes.

### Changed
- Appointment types standardised; demo capacity limit removed.

## 2026-11-16

### Added
- Customer portal API (`/api/portal/v1`) with service-key and bearer-token authentication.
- Prospect conversion to customers, software trials, appointment scheduling with holds and conflict detection, calendar.
- Staff triage of website requests and after-sales interventions with a configurable workflow.
- Configuration entity endpoints (appointment types, holidays, intervention settings, message templates, product resources).
- Portal defaults seeder; feature tests for customer lifecycle, booking holds, interventions and portal security.
- Customer portal architecture and API documentation.

### Changed
- Tests run on in-memory SQLite.

## 2026-11-09 – 2026-11-11

### Added
- Initial staff API: prospects, configurable sales stages, loss reasons, products, activities, follow-ups, stage history and dashboard.
- Prospect profile fields, form options and activity linking.
- Feature tests for dashboard, follow-ups and prospects; LAN deployment, backup and MySQL recovery guides.
