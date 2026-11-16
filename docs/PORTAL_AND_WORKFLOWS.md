# Customer journey API: staff workflows and customer portal

Added on top of the existing `/api/v1` CRM API (see `API.md`). Nothing existing was renamed or removed;
`GET /api/v1/prospects*` responses only gained `is_customer`, `converted_at`, `converted_by`, `conversion_note`.

All responses use the standard envelope: `{ success, message, data, meta }` / `{ success:false, message, error_code, errors }`.
Date-times are ISO 8601 in `Africa/Algiers`. Request date-times without an offset are read as Algiers local time.

## 1. Two separate surfaces

| | Staff API | Customer portal API |
|---|---|---|
| Base path | `/api/v1` | `/api/portal/v1` |
| Who calls it | Flutter desktop app on the LAN | The **public website's server** (never a browser) |
| Protection | `RestrictToAllowedNetworks` (`CRM_ALLOWED_NETWORKS`), unchanged | `X-Portal-Key` shared secret (`CRM_PORTAL_SERVICE_KEY`) + optional `CRM_PORTAL_ALLOWED_NETWORKS`; customer routes also need `Authorization: Bearer <customer token>` |
| Identity | none (equal access for all employees) | `portal_accounts` table, hashed bearer tokens, **no relation to employees** |

* The service key never opens staff routes, and the LAN allow-list is never loosened. Only the portal path is reachable from outside the LAN, and only with the key.
* If `CRM_PORTAL_SERVICE_KEY` is empty the portal answers `503 PORTAL_DISABLED`.
* Staff requests may send `X-Employee-Name: <name>`; it is stored as an audit label (`decided_by`, `converted_by`, ...). It is not authentication.
* Every customer query is scoped by the signed-in account (`portal_account_id`) or its company (`prospect_id`); other customers' ids answer `404`.
* Customer output is whitelisted in `PortalPresenter`: no internal notes, assignees, priorities, internal status names, activity ids.

## 2. Data model (all additive, reversible)

`prospects` + `converted_at, converted_by, conversion_note` (a **customer is a prospect with `converted_at`**; same row/id, history intact) ·
`prospect_products.purchased_at` · `portal_accounts`, `portal_tokens`, `portal_password_resets` ·
`crm_settings` (key/value) · `appointment_types`, `holidays`, `appointment_series`, `appointments`, `portal_requests`, `trials` ·
`intervention_types`, `intervention_statuses`, `intervention_status_transitions`, `intervention_priorities`, `interventions`,
`intervention_customers`, `intervention_messages`, `intervention_attachments` · `message_templates`, `product_resources`.

Initial configuration (types, statuses, transitions, priorities, templates) is loaded **once** by
`php artisan db:seed --class=PortalDefaultsSeeder` (idempotent). Afterwards everything is edited from settings.

### Booking/hold rules
* A customer-selected demo slot becomes a `held` appointment immediately (transaction + calendar lock row + unique `slot_key` per capacity lane).
* `hold_expiry_minutes` is configurable. Expired holds are released lazily on every calendar read/write and by `php artisan crm:release-expired-holds` (scheduled every minute — the Windows Task Scheduler must run `php artisan schedule:run` every minute).
* Appointment statuses: `held`, `proposed` (staff offered another time, customer must accept), `confirmed`, `rejected`, `cancelled`, `expired`, `completed`, `no_show`.
* An appointment is **not** an activity. `complete` (only after it started) creates the `Activity` and links `activity_id`.
* Slots offered = working days/hours − holidays − live appointments, with `min_notice_hours` and `booking_horizon_days`; capacity `demo_capacity` demos in parallel.
* Training/series conflicts (same employee or same customer, holidays) are checked for **every** occurrence before anything is written; `409 APPOINTMENT_CONFLICT` with `errors.conflicts[]`.
* First training is per customer **and** product (`training_kind: first|follow_up`). WhatsApp: only a prefilled `https://wa.me/...` link is returned; `delivery_confirmed` is always `false`.

## 3. Staff endpoints (`/api/v1`, LAN)

| Method & path | Purpose |
|---|---|
| `GET /lookups` | now also: `appointment_types`, `intervention_types/statuses/priorities`, `scheduling` |
| `POST /prospects/{id}/convert` `{product_ids[], note?, won_stage_id?}` | explicit prospect → customer (moves to first active won stage via the normal stage history; `409 ALREADY_CUSTOMER`) |
| `GET /customers`, `GET /customers/{id}` | customer list (`?search`) / profile: prospect, products(+`purchased_at`), `portal_accounts`, trials, appointments |
| `GET/POST /prospects/{id}/trials` `{product_id,url,starts_at?,ends_at?,internal_notes?}`, `POST /trials/{id}/revoke` | trial access (`trial_default_days`); `409 TRIAL_OVERLAP` |
| `GET /calendar?from&to&employee&prospect_id&product_id&type&status&kinds[]` | merged `appointment` + `follow_up` + `intervention` items `{kind,id,title,starts_at,ends_at,status,color,type_code,group,prospect,product,assigned_employee,conflicts[]}` |
| `GET /appointments` (`status,group,prospect_id,from,to`, paginated) `GET /appointments/{id}` | |
| `POST /appointments` `{prospect_id,appointment_type_id,product_id?,starts_at,ends_at?,assigned_employee?,internal_notes?,meeting_url?,send_whatsapp?,resource_url?,occurrences?,interval_days?}` | single session; for a type of group `accompaniment` creates the whole series (defaults `accompaniment_occurrences` / `accompaniment_interval_days`) → `{series_id, appointments[]}` |
| `POST /appointments/{id}/confirm` `{meeting_url?,assigned_employee?,message?}` | held → confirmed (`410 HOLD_EXPIRED`) |
| `POST /appointments/{id}/reject` / `cancel` `{reason?}` | releases the slot; reason is customer-visible |
| `POST /appointments/{id}/propose` `{starts_at,message?}` | holds a new time for the customer |
| `POST /appointments/{id}/reschedule` `{starts_at,ends_at,assigned_employee?}` | future non-demo sessions only |
| `POST /appointments/{id}/complete` `{outcome}` / `no-show` | records the Activity |
| `POST /appointment-series/{id}/cancel?from_appointment_id=` | cancels remaining future sessions only |
| `GET /portal-requests`, `GET/PATCH /portal-requests/{id}` `{status: handled|rejected|cancelled, customer_message?}` | website requests |
| `GET /interventions` (`status_id,priority_id,type_id,source,assigned_employee,prospect_id,open`), `POST /interventions` | employee-originated work (`customer_ids[]` = affected customers) |
| `GET/PATCH /interventions/{id}` `{intervention_priority_id?,assigned_employee?,scheduled_at?,title?}` | triage |
| `GET /interventions/{id}/transitions`, `POST .../transition` `{status_id,customer_message?}` | only configured transitions (`422 INVALID_STATUS_TRANSITION`) |
| `POST /interventions/{id}/messages` `{visibility: customer|internal, body}` | internal notes vs customer-visible replies |
| `POST /interventions/{id}/publish` `{customer_resolution}` / `unpublish` | release/update notes for affected customers |
| `GET/PUT /settings/scheduling` | `working_days[0=Sun..6], work_start, work_end, slot_minutes, hold_expiry_minutes, booking_horizon_days, min_notice_hours, demo_capacity, accompaniment_occurrences, accompaniment_interval_days, trial_default_days, whatsapp_country_code` |
| `GET/POST /config/{entity}`, `PUT/PATCH /config/{entity}/{id}`, `DELETE /config/{entity}/{id}` | entities: `appointment-types, holidays, intervention-types, intervention-statuses, intervention-transitions, intervention-priorities, message-templates, product-resources`. **DELETE only deactivates** (`is_active=false`). |

Config row fields — appointment-types: `code,name,group(demo|meeting|training|accompaniment),default_duration_minutes,color,customer_bookable,sort_order,is_active`;
intervention-types: `code,name,source(customer|employee|both),color`; intervention-statuses: `code,name,customer_label,color,is_initial,is_closed,awaits_customer,is_delivered`;
intervention-transitions: `from_status_id,to_status_id,customer_allowed`; intervention-priorities: `code,name,color,response_target_hours`;
holidays: `date,name`; message-templates: `code(training_first|training_review),locale,body` with `{contact} {product} {date} {start} {end} {resource_url}`; product-resources: `product_id,title,kind,url,customer_visible,is_training_material`.

## 4. Customer portal endpoints (`/api/portal/v1`, header `X-Portal-Key`)

Public (key only): `GET catalog/products`, `GET availability?from&to` (≤62 days → `{timezone,slot_minutes,hold_expiry_minutes,slots[{starts_at,ends_at}]}`),
`POST auth/register {name,email,phone?,password(min 8),locale?}`, `POST auth/login {email,password}` (→ `{account, token}`; 5 attempts/min per ip+email),
`POST auth/forgot-password {email}` (→ `{reset_token|null}` — **the website server e-mails the link; never return it to the browser**), `POST auth/reset-password {token,password}`.

Signed-in (`Authorization: Bearer`): `POST auth/logout`, `GET/PATCH me`, `GET overview`,
`POST requests` `{type: demo|quotation|trial, product_id, company, contact_name, job_title?, phone, email, wilaya, city, business_activity?, notes?, source?, slot_start?}`
(`slot_start` required for `demo`; creates/links the CRM prospect and the hold; `409 SLOT_UNAVAILABLE`, `409 DUPLICATE_REQUEST`, `422 SLOT_NOT_OFFERED`),
`GET requests`, `GET requests/{id}`, `POST requests/{id}/cancel`, `GET appointments`, `POST appointments/{id}/accept|decline`,
`GET trials` (`url` only while `state=active`), `GET products` (purchased), `GET resources`, `GET updates`,
`GET interventions/options`, `GET/POST interventions` `{intervention_type_id,product_id,title,description}`, `GET interventions/{id}`,
`POST interventions/{id}/messages {body}`, `GET .../transitions`, `POST .../transition {status_id,message?}` (customer-allowed only, e.g. reopen),
`POST .../attachments` (multipart `file`, ≤5 MB, jpg/jpeg/png/pdf/txt/zip, stored on the private disk), `GET .../attachments/{id}` (streamed after ownership check).

Error codes: `INVALID_SERVICE_KEY 401`, `UNAUTHENTICATED 401`, `INVALID_CREDENTIALS 401`, `PORTAL_DISABLED 503`, `VALIDATION_FAILED 422`,
`SLOT_UNAVAILABLE 409`, `HOLD_EXPIRED 410`, `DUPLICATE_REQUEST 409`, `PRODUCT_NOT_OWNED 403`, `NO_CUSTOMER 403`, `INVALID_STATUS_TRANSITION 422`, `TOO_MANY_REQUESTS 429`.

## 5. Environment variables (backend `.env`)

```ini
CRM_PORTAL_SERVICE_KEY=<long random secret, also put in the website .env as CRM_API_KEY>
CRM_PORTAL_ALLOWED_NETWORKS=            # optional: website server IP/CIDR
CRM_PORTAL_TOKEN_TTL_DAYS=30
CRM_PORTAL_RESET_TTL_MINUTES=60
CRM_PORTAL_RATE_LIMIT_PER_MINUTE=120
CRM_PORTAL_LOGIN_ATTEMPTS=5
```

## 6. Safe rollout (needs human approval — nothing here was run on the company database)

1. Back up MySQL (`docs/BACKUP_RESTORE.md`) and the `storage/app/private` folder.
2. Deploy code, `composer install --no-dev`, set the new env variables, `php artisan config:cache`.
3. `php artisan migrate --pretend` and review, then `php artisan migrate` (additive; rollback = `php artisan migrate:rollback --step=3`, which only drops the new tables/columns).
4. `php artisan db:seed --class=PortalDefaultsSeeder` (idempotent defaults), then adjust working hours, holidays and templates in CRM settings.
5. Add a Windows scheduled task running `php artisan schedule:run` every minute (hold expiry).
6. Website server: `softel-website/composer.lock` pins Symfony 8, so the website needs **PHP 8.4 or newer** (the CRM backend still runs on PHP 8.2+). Use `composer install` with that PHP, never `--ignore-platform-req`.
7. Expose **only** `/api/portal/` to the website host through the reverse proxy; keep `/api/v1/` LAN-only (the app also enforces it). Do not open the LAN port to the internet.
