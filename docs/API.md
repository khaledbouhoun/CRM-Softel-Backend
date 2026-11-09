# SOFTEL CRM — REST API reference (v1)

This is the contract between the Laravel backend and the Flutter desktop client.
Everything the client needs — URLs, payload fields, response shapes, error codes —
is listed here. If behaviour and this document disagree, it is a bug.

- Base URL: `http://<server-ip>:<port>/api/v1` (e.g. `http://192.168.1.10:8000/api/v1`)
- Content type: send `Content-Type: application/json` and `Accept: application/json`.
- Authentication: **none**. Access is limited to computers on allowed LAN
  addresses (`CRM_ALLOWED_NETWORKS`); other addresses get `403 NETWORK_NOT_ALLOWED`.
- Encoding: UTF-8 JSON. Unicode is not escaped (`"Gagné"`, not `"Gagné"`).

## Contents

1. [Conventions](#1-conventions)
2. [Errors](#2-errors)
3. [Resource shapes](#3-resource-shapes)
4. [System: health, lookups](#4-system)
5. [Dashboard](#5-dashboard)
6. [Prospects](#6-prospects)
7. [Stage transitions & history](#7-stage-transitions--history)
8. [Activities](#8-activities)
9. [Follow-ups](#9-follow-ups)
10. [Products](#10-products)
11. [Sales stages](#11-sales-stages)
12. [Loss reasons](#12-loss-reasons)
13. [Endpoint summary](#13-endpoint-summary)

---

## 1. Conventions

### 1.1 Envelope

Every response, success or error, has the same top-level shape.

Success:

```json
{
  "success": true,
  "message": "Prospect created successfully.",
  "data": { "id": 1, "name": "Example Company" },
  "meta": null
}
```

Paginated list (`data` is an array, `meta` holds pagination):

```json
{
  "success": true,
  "message": "Prospects retrieved successfully.",
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": { "current_page": 1, "last_page": 5, "per_page": 20, "total": 100, "from": 1, "to": 20 }
}
```

Full (non-paginated) configuration list — products, sales stages, loss reasons:

```json
{ "success": true, "message": "Products retrieved successfully.", "data": [ ... ], "meta": { "total": 4 } }
```

Error:

```json
{
  "success": false,
  "message": "Validation failed.",
  "error_code": "VALIDATION_FAILED",
  "errors": { "name": ["The name field is required."] }
}
```

`errors` is `null` when there are no field details.

### 1.2 Pagination

Paginated endpoints accept:

| Query param | Type | Default | Notes |
|---|---|---|---|
| `page` | int ≥ 1 | 1 | |
| `per_page` | int 1–100 | 20 | values above 100 → 422 |
| `direction` | `asc` \| `desc` | per endpoint | sort direction |

### 1.3 Dates and timezone

- The server works in one **business timezone** (`APP_TIMEZONE`, default
  `Africa/Algiers`, UTC+01:00). `GET /health` and `GET /lookups` return it.
- **Responses**: every date-time is ISO 8601 with offset, e.g. `"2026-11-01T14:30:00+01:00"`.
  Parse with `DateTime.parse()` in Dart; it handles the offset.
- **Requests** (date-time fields): send ISO 8601. With an offset
  (`2026-11-05T09:00:00+01:00`, or UTC `2026-11-05T08:00:00Z`) the value is
  converted to business time. Without an offset (`2026-11-05 09:00`) it is
  read as business time. Recommended in Dart: `dt.toUtc().toIso8601String()`.
- **Date filters** (`created_from`, `date_from`, `date`, ...) use `YYYY-MM-DD`
  and are whole days in business time, bounds inclusive.
- "Today" always means the current calendar day in business time.

### 1.4 Partial updates

`PUT` (and `PATCH`) endpoints update only the fields present in the body.
A field sent as `null` is set to `null` (when nullable). Omit a field to keep it.

### 1.5 Booleans in query strings

`is_active` filters accept `1`, `0`, `true`, `false`.

---

## 2. Errors

| HTTP | `error_code` | When |
|---|---|---|
| 403 | `NETWORK_NOT_ALLOWED` | Client IP is not in `CRM_ALLOWED_NETWORKS`. |
| 404 | `NOT_FOUND` | Unknown URL, or record id does not exist (message names the model, e.g. `"Prospect not found."`). Soft-deleted prospects are not found. |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method for the URL. |
| 409 | `RESOURCE_IN_USE` | DELETE of a product / stage / loss reason that is referenced. Deactivate it instead. |
| 409 | `CONSTRAINT_VIOLATION` | Database integrity constraint rejected the write (should not happen through normal API use). |
| 422 | `VALIDATION_FAILED` | Field validation, including business validation that maps to a field (inactive product, missing loss reason, ...). `errors` is keyed by field. |
| 422 | `INVALID_STAGE_TRANSITION` | Stage change not allowed (already in that stage, prospect closed and reopening disabled). `errors.sales_stage_id` is set. |
| 409 | `ACTIVITY_ALREADY_LINKED` | Linking an activity that belongs to another prospect without `confirm_relink`. |
| 422 | `ACTIVITY_LINK_LOCKED` | Moving an activity that was converted from a follow-up. |
| 422 | `INVALID_FOLLOW_UP_STATE` | Editing / completing / cancelling a follow-up that is not pending. |
| 422 | `STAGE_TYPE_LOCKED` | Changing `stage_type` of a stage that is in use. |
| 422 | `LAST_OPEN_STAGE` | Deactivating / deleting / retyping the last active `open` stage. |
| 429 | `TOO_MANY_REQUESTS` | More than `CRM_RATE_LIMIT_PER_MINUTE` requests per minute from one IP. |
| 500 | `SERVER_ERROR` / `DATABASE_ERROR` | Unexpected failure. Details are only in the server log (`storage/logs`). |
| 503 | `SERVICE_UNAVAILABLE` | `GET /health` only: database unreachable. |

Field errors for array items use dotted keys, e.g. `"product_ids.1": ["The product \"POS\" is inactive and cannot be associated."]`.

The response never contains stack traces, SQL or file paths.

---

## 3. Resource shapes

Fields marked *(list/detail)* are present only where noted.

### 3.1 SalesStage

```json
{
  "id": 2,
  "name": "Démonstration",
  "description": null,
  "color": "#2563EB",
  "sort_order": 2,
  "stage_type": "open",
  "is_active": true,
  "prospects_count": 12,
  "created_at": "2026-11-01T09:00:00+01:00",
  "updated_at": "2026-11-01T09:00:00+01:00"
}
```

- `stage_type`: `open` | `won` | `lost`. Drives all behaviour (never the name).
- `prospects_count` (non-deleted prospects currently in the stage): present in
  `/sales-stages` endpoints, absent when embedded in another resource.

### 3.2 LossReason

```json
{
  "id": 7,
  "name": "Autre",
  "description": null,
  "requires_note": true,
  "sort_order": 7,
  "is_active": true,
  "created_at": "...",
  "updated_at": "..."
}
```

### 3.3 Product

```json
{
  "id": 3,
  "name": "ERP Commercial",
  "description": "Gestion commerciale",
  "is_active": true,
  "prospects_count": 5,
  "created_at": "...",
  "updated_at": "..."
}
```

`prospects_count` (non-deleted prospects interested) only in `/products` endpoints.

### 3.4 Prospect

```json
{
  "id": 15,
  "name": "Alpha SARL",
  "contact_person": "Karim B.",
  "phone": "+213 555 12 34 56",
  "email": "contact@alpha.dz",
  "address": "12 rue Didouche Mourad",
  "city": "Alger",
  "business_activity": "wholesale",
  "source": "other",
  "source_other": "Trade fair",
  "previous_software": "sage",
  "notes": null,
  "sales_stage_id": 4,
  "sales_stage": { "...SalesStage (without prospects_count)" },
  "loss_reason_id": 2,
  "loss_reason": { "...LossReason" },
  "loss_note": "Chose competitor X",
  "products": [ { "...Product" } ],
  "activities_count": 6,
  "pending_follow_ups_count": 1,
  "last_activity_at": "2026-10-30T16:00:00+01:00",
  "next_follow_up_at": "2026-11-04T10:00:00+01:00",
  "created_at": "...",
  "updated_at": "..."
}
```

- `business_activity`, `source`, `source_other`, `previous_software` are optional
  (`null` on older prospects). They hold **stable codes**, never translated labels:
  `source` is a lowercase code (`facebook`, `instagram`, `friend_referral`,
  `customer_referral`, `website`, `phone_call`, `other`; new codes are accepted),
  `source_other` is free text kept only while `source = "other"` (cleared otherwise),
  `business_activity` and `previous_software` are a suggested code
  (`GET /lookups` -> `business_activities`, `previous_software`) **or free text**.
- `loss_reason` is `null` unless the prospect is currently in a `lost` stage.
- `last_activity_at` / `next_follow_up_at` are `null` when there is none.
  `next_follow_up_at` is the earliest *pending* follow-up (may be overdue).

### 3.5 ProspectSummary (embedded in activities, follow-ups)

`sales_stage` is present when the stage was loaded (activity lists, search pickers).

```json
{ "id": 15, "name": "Alpha SARL", "contact_person": "Karim B.", "phone": "...", "sales_stage_id": 4,
  "sales_stage": { "...SalesStage" }, "is_deleted": false }
```

### 3.6 Activity

```json
{
  "id": 31,
  "prospect_id": 15,
  "prospect": { "...ProspectSummary" },
  "is_linked": true,
  "origin": "direct",
  "follow_up_id": null,
  "type": "appointment",
  "title": "Demo at customer site",
  "description": "Presented the new version",
  "outcome": "Wants a quotation",
  "activity_at": "2026-11-01T10:30:00+01:00",
  "linked_at": "2026-11-01T10:35:00+01:00",
  "created_at": "...",
  "updated_at": "..."
}
```

- `type`: `call` | `appointment` | `message` | `note` | `other`. `meeting` is the
  **legacy** code of existing rows and means the same as `appointment`; filtering
  by `type=appointment` (or `meeting`) returns both. Clients display both as "appointment".
- `prospect_id` / `prospect` are `null` while the activity is **not linked** (`is_linked: false`).
- `origin`: `direct` = created on its own (from a prospect page or standalone);
  `follow_up` = converted from a follow-up (`follow_up_id` is that follow-up).
- `title` is optional on old rows (`null`).

### 3.7 FollowUp

```json
{
  "id": 9,
  "prospect_id": 15,
  "prospect": { "...ProspectSummary" },
  "title": "Call back about the quotation",
  "description": null,
  "activity_type": "call",
  "activity_id": null,
  "scheduled_at": "2026-11-04T10:00:00+01:00",
  "status": "pending",
  "due_state": "upcoming",
  "completed_at": null,
  "cancelled_at": null,
  "closing_note": null,
  "created_at": "...",
  "updated_at": "..."
}
```

- `status`: `pending` | `completed` | `cancelled`.
- `due_state` (pending only, else `null`), whole days in business time:
  - `overdue` — scheduled before today
  - `today` — scheduled any time today
  - `upcoming` — scheduled after today
- `closing_note`: optional note given when completing or cancelling.
- `activity_type`: `call` | `appointment` | `message`, or `null` on follow-ups created before it existed.
- `activity_id`: id of the activity this follow-up was converted into, else `null`.

### 3.8 StageHistory

```json
{
  "id": 40,
  "prospect_id": 15,
  "from_stage_id": 2,
  "from_stage": { "...SalesStage" },
  "to_stage_id": 4,
  "to_stage": { "...SalesStage" },
  "loss_reason_id": 2,
  "loss_reason": { "...LossReason" },
  "loss_note": "Chose competitor X",
  "changed_at": "2026-11-01T11:00:00+01:00",
  "created_at": "..."
}
```

- The first entry of every prospect has `from_stage_id: null` / `from_stage: null`
  (the stage it was created in).
- Stages and loss reasons are returned even if they were deactivated later
  (`is_active: false`), so history always stays readable.

---

## 4. System

### GET /health

Connectivity check — call it when the app starts or the server address is changed.

```json
{
  "success": true,
  "message": "API is running.",
  "data": {
    "status": "ok",
    "database": "ok",
    "api_version": "v1",
    "server_time": "2026-11-01T14:30:00+01:00",
    "timezone": "Africa/Algiers"
  },
  "meta": null
}
```

Database down → HTTP 503, `error_code: SERVICE_UNAVAILABLE`.

### GET /lookups

All reference data for forms and filters in one call. Lists contain inactive
entries too (`is_active: false`): show them only to label existing data, offer
only active ones for new selections.

```json
{
  "success": true,
  "message": "Lookups retrieved successfully.",
  "data": {
    "stage_types": ["open", "won", "lost"],
    "activity_types": ["call", "meeting", "appointment", "message", "note", "other"],
    "follow_up_activity_types": ["call", "appointment", "message"],
    "business_activities": ["wholesale", "retail", "manufacturing", "construction", "services", "other"],
    "prospect_sources": ["facebook", "instagram", "friend_referral", "customer_referral", "website", "phone_call", "other"],
    "previous_software": ["sage", "pc_compta", "excel", "other_software", "none", "other"],
    "follow_up_statuses": ["pending", "completed", "cancelled"],
    "follow_up_scopes": ["overdue", "today", "upcoming"],
    "sales_stages": [ { "...SalesStage" } ],
    "loss_reasons": [ { "...LossReason" } ],
    "products": [ { "...Product" } ],
    "settings": {
      "timezone": "Africa/Algiers",
      "default_per_page": 20,
      "max_per_page": 100,
      "allow_reopening_closed": true
    }
  },
  "meta": null
}
```

---

## 5. Dashboard

### GET /dashboard

| Query | Type | Default | Notes |
|---|---|---|---|
| `recent_limit` | int 1–50 | 10 | number of recent activities |
| `upcoming_days` | int 1–365 | 7 (`CRM_DASHBOARD_UPCOMING_DAYS`) | window for `follow_ups.upcoming` |

```json
{
  "success": true,
  "message": "Dashboard retrieved successfully.",
  "data": {
    "prospects": { "total": 42, "open": 30, "won": 7, "lost": 5 },
    "prospects_by_stage": [
      { "sales_stage_id": 1, "name": "Nouveau", "color": "#2563EB", "stage_type": "open", "sort_order": 1, "is_active": true, "count": 18 }
    ],
    "lost_by_reason": [
      { "loss_reason_id": 1, "name": "Trop cher", "is_active": true, "count": 3 }
    ],
    "this_month": { "month": "2026-11", "won": 2, "lost": 1, "new_prospects": 6 },
    "follow_ups": { "today": 3, "overdue": 2, "upcoming": 5, "upcoming_window_days": 7 },
    "recent_activities": [ { "...Activity" } ],
    "generated_at": "2026-11-01T14:30:00+01:00"
  },
  "meta": null
}
```

- All numbers are computed from the database on every call. Soft-deleted prospects
  (and their follow-ups/activities) are excluded.
- `prospects_by_stage`: pipeline order; inactive stages appear only while they still hold prospects.
- `this_month.won/lost`: distinct prospects moved into a won/lost stage since the
  1st of the current month.
- `follow_ups.overdue/today/upcoming` match `GET /follow-ups?scope=...` (with
  `days=upcoming_window_days` for upcoming).

---

## 6. Prospects

### GET /prospects

Paginated. Each item is a full [Prospect](#34-prospect).

| Query | Type | Notes |
|---|---|---|
| `search` | string ≤100 | **One search for every prospect lookup** (list, pipeline, pickers). Contains, case-insensitive, over `name` (company), `contact_person` (full name), `phone`, `email`, `city` and the prospect `id` when the term is a number. Phone terms also match digits only (`55512` finds `+213 (555) 12-34-56`). It ignores the stage: won / lost prospects are found. Each result carries `sales_stage`. |
| `source`, `business_activity`, `previous_software` | string | exact match on the stored code / text |
| `sales_stage_id` | int | exact stage |
| `stage_type` | `open`\|`won`\|`lost` | all stages of that type |
| `product_id` | int | prospects interested in this product |
| `loss_reason_id` | int | current loss reason |
| `city` | string | exact city |
| `created_from`, `created_to` | `YYYY-MM-DD` | creation date range (inclusive) |
| `updated_from`, `updated_to` | `YYYY-MM-DD` | last update range (inclusive) |
| `sort` | `name`\|`city`\|`created_at`\|`updated_at` | default `created_at` |
| `direction` | `asc`\|`desc` | default `desc` (`asc` for name/city) |
| `page`, `per_page` | | see 1.2 |

Example: `GET /prospects?search=amine&stage_type=open&product_id=3&sort=name&per_page=50`

### GET /prospects/{id}

Returns one [Prospect](#34-prospect). 404 if missing or deleted.

### POST /prospects

| Field | Type | Rules |
|---|---|---|
| `name` | string | **required**, ≤150 |
| `contact_person` | string\|null | ≤150 |
| `phone` | string\|null | ≤30, digits/spaces/`.`/`-`/`()`/`/`, optional leading `+` |
| `email` | string\|null | valid e-mail, ≤191 |
| `address` | string\|null | ≤255 |
| `city` | string\|null | ≤100 |
| `business_activity` | string\|null | ≤150, code or free text |
| `source` | string\|null | ≤50, lowercase code (`^[a-z][a-z0-9_]*$`) |
| `source_other` | string\|null | ≤150, kept only when `source = "other"` |
| `previous_software` | string\|null | ≤150, code or free text |
| `notes` | string\|null | ≤10000 |
| `sales_stage_id` | int\|null | existing **active** stage. Omitted/null → first active `open` stage in pipeline order |
| `loss_reason_id` | int\|null | only if the stage is `lost` (then **required**, must be active) |
| `loss_note` | string\|null | ≤2000; only for `lost`; **required** if the loss reason has `requires_note` |
| `product_ids` | int[] | existing **active** products, no duplicates, ≤100 |

Request:

```json
{
  "name": "Alpha SARL",
  "contact_person": "Karim B.",
  "phone": "+213 555 12 34 56",
  "email": "contact@alpha.dz",
  "city": "Alger",
  "sales_stage_id": 1,
  "product_ids": [3, 5]
}
```

Response `201` — `data` is the created [Prospect](#34-prospect). The initial stage
is recorded in stage history (`from_stage_id: null`). Everything is written in
one transaction: on any error nothing is saved.

Typical errors (422):

```json
{ "success": false, "message": "Validation failed.", "error_code": "VALIDATION_FAILED",
  "errors": { "product_ids.1": ["The product \"POS Caisse\" is inactive and cannot be associated."] } }
```

```json
{ "success": false, "message": "Validation failed.", "error_code": "VALIDATION_FAILED",
  "errors": { "sales_stage_id": ["The sales stage \"Démonstration\" is inactive and cannot be selected."] } }
```

### PUT /prospects/{id}

Partial update of profile fields (`name`, `contact_person`, `phone`, `email`,
`address`, `city`, `business_activity`, `source`, `source_other`, `previous_software`, `notes`) — same rules as POST — and products.

- `product_ids`, when present, **replaces** the product list (send `[]` to clear).
  New products must be active; products already linked may stay linked even if
  they were deactivated meanwhile.
- `sales_stage_id`, `loss_reason_id`, `loss_note` may be sent back unchanged
  (so you can PUT the object you received), but changing them → 422. Use
  [change-stage](#post-prospectsidchange-stage).

```json
{ "phone": "0555 00 11 22", "product_ids": [3] }
```

Response `200` — updated [Prospect](#34-prospect).

### DELETE /prospects/{id}

Soft delete. The prospect disappears from lists, dashboard and lookups; its
activities, follow-ups and stage history are kept in the database.

```json
{ "success": true, "message": "Prospect deleted successfully.", "data": null, "meta": null }
```

---

## 7. Stage transitions & history

### POST /prospects/{id}/change-stage

| Field | Type | Rules |
|---|---|---|
| `sales_stage_id` | int | **required**, existing, **active**, different from the current stage |
| `loss_reason_id` | int\|null | target `lost`: **required**, active. Other targets: must be omitted/null |
| `loss_note` | string\|null | ≤2000. Target `lost` + reason `requires_note`: **required** (non-blank). Other targets: must be omitted/null |

```json
{ "sales_stage_id": 4, "loss_reason_id": 2, "loss_note": "The customer selected a competitor." }
```

Response `200`:

```json
{
  "success": true,
  "message": "Prospect stage changed successfully.",
  "data": {
    "prospect": { "...Prospect (updated)" },
    "stage_history": { "...StageHistory (the new record)" }
  },
  "meta": null
}
```

Rules:

- Prospect update + history insert happen in one transaction with the prospect
  row locked. If anything fails nothing changes.
- Moving **into** a `lost` stage stores the loss reason/note on the prospect and in history.
- Moving **out of** a `lost` stage clears the prospect's current `loss_reason_id` /
  `loss_note`; the history row of the loss keeps them.
- Moving into a `won` stage is recorded like any other transition.
- Same stage as current → `422 INVALID_STAGE_TRANSITION`.
- If the server sets `CRM_ALLOW_REOPENING_CLOSED=false`, a prospect in a `won`
  or `lost` stage cannot move anymore → `422 INVALID_STAGE_TRANSITION`
  (`lookups.settings.allow_reopening_closed` tells the client).

Errors:

```json
{ "success": false, "message": "Validation failed.", "error_code": "VALIDATION_FAILED",
  "errors": { "loss_reason_id": ["A loss reason is required when moving a prospect to a lost stage."] } }
```

```json
{ "success": false, "message": "Validation failed.", "error_code": "VALIDATION_FAILED",
  "errors": { "loss_note": ["A note is required for the loss reason \"Autre\"."] } }
```

```json
{ "success": false, "message": "The prospect is already in the stage \"Nouveau\".",
  "error_code": "INVALID_STAGE_TRANSITION",
  "errors": { "sales_stage_id": ["The prospect is already in the stage \"Nouveau\"."] } }
```

### GET /prospects/{id}/stage-history

Paginated [StageHistory](#38-stagehistory), newest first (`direction=asc` for oldest first).
Query: `page`, `per_page`, `direction`.

---

## 8. Activities

Activities are the communication log: **append-only**. Content, type and dates are
never modified and nothing is deleted. The only change after creation is
**linking** an activity to a prospect. There are three ways to get one:

1. created from a prospect page (`POST /prospects/{id}/activities`),
2. created on its own (`POST /activities`) and linked later,
3. converted from a completed follow-up (section 9).

### GET /prospects/{id}/activities

Paginated [Activity](#36-activity) of one prospect, newest first. Same filters as
`GET /activities` except `prospect_id` and `linked`.

### POST /prospects/{id}/activities

| Field | Type | Rules |
|---|---|---|
| `type` | string | **required**: `call`, `meeting`, `appointment`, `message`, `note`, `other` |
| `title` | string\|null | ≤200 |
| `description` | string | **required**, 2–5000 |
| `outcome` | string\|null | ≤5000 |
| `activity_at` | date-time\|null | not after today, not before 2000-01-01. Omitted → now |

Response `201` — the [Activity](#36-activity).

### POST /activities

Creates an activity on its own; the prospect is optional.

| Field | Type | Rules |
|---|---|---|
| `type` | string | **required**, as above |
| `title` | string | **required**, 2–200 |
| `description` | string\|null | ≤5000 (discussion, customer response, notes) |
| `outcome` | string\|null | ≤5000 |
| `activity_at` | date-time\|null | as above; omitted → now |
| `prospect_id` | int\|null | existing, non-deleted prospect; omit to leave the activity unlinked |

```json
{ "type": "call", "title": "Customer called the office", "description": "Asked about the price list",
  "activity_at": "2026-11-08T10:00:00+01:00" }
```

Response `201` — the [Activity](#36-activity) (`is_linked: false`, `origin: "direct"` when no prospect).

### GET /activities/{id}

One [Activity](#36-activity).

### POST /activities/{id}/link-prospect

Links the **existing** activity to a prospect (no new row; `created_at`, `activity_at`,
type and content are untouched; `linked_at` is set).

| Field | Type | Rules |
|---|---|---|
| `prospect_id` | int | **required**, existing non-deleted prospect |
| `confirm_relink` | bool | must be `true` to move an activity that already belongs to **another** prospect |

- Same prospect again → `200`, nothing changes.
- Already linked elsewhere without `confirm_relink` → `409 ACTIVITY_ALREADY_LINKED`.
- Activity converted from a follow-up (`origin: "follow_up"`) → `422 ACTIVITY_LINK_LOCKED`.
- Runs in one transaction with the activity row locked.

### GET /activities

Paginated, newest first. Includes unlinked activities; activities of deleted prospects are excluded.

| Query | Type | Notes |
|---|---|---|
| `prospect_id` | int | |
| `linked` | `1`\|`0` | linked only / unlinked only |
| `origin` | `direct`\|`follow_up` | |
| `type` | activity type | `appointment` also returns legacy `meeting` rows |
| `date_from`, `date_to` | `YYYY-MM-DD` | on `activity_at`, inclusive |
| `search` | string | in title, description and outcome |
| `direction` | `asc`\|`desc` | default `desc` |
| `page`, `per_page` | | |

---

## 9. Follow-ups

Lifecycle: `pending` → `completed` or `cancelled`. Completed/cancelled are final.

### GET /prospects/{id}/follow-ups

Paginated [FollowUp](#37-followup) of one prospect. Same filters as `GET /follow-ups`
except `prospect_id`.

### POST /prospects/{id}/follow-ups

| Field | Type | Rules |
|---|---|---|
| `title` | string | **required**, ≤200 |
| `description` | string\|null | ≤5000 |
| `activity_type` | string\|null | `call`, `appointment` or `message` (optional for old clients; the Flutter app always sends it) |
| `scheduled_at` | date-time | **required**, not before the start of today |

```json
{ "title": "Call back about the quotation", "scheduled_at": "2026-11-04T10:00:00+01:00" }
```

Response `201` — the [FollowUp](#37-followup) (`status: pending`).

### GET /follow-ups

Paginated, all prospects (deleted prospects excluded).

| Query | Type | Notes |
|---|---|---|
| `status` | `pending`\|`completed`\|`cancelled` | |
| `scope` | `overdue`\|`today`\|`upcoming` | due windows (pending only), see 3.7 |
| `days` | int 1–365 | with `scope=upcoming`: only the next N days after today |
| `prospect_id` | int | |
| `date` | `YYYY-MM-DD` | scheduled on that day |
| `date_from`, `date_to` | `YYYY-MM-DD` | scheduled in range (inclusive) |
| `search` | string | in title and description |
| `sort` | `scheduled_at`\|`created_at`\|`completed_at` | default `scheduled_at` |
| `direction` | `asc`\|`desc` | default `asc` for scheduled_at, else `desc` |
| `page`, `per_page` | | |

Common screens:

| Screen | Request |
|---|---|
| Overdue | `GET /follow-ups?scope=overdue` |
| Today | `GET /follow-ups?scope=today` |
| Upcoming (7 days) | `GET /follow-ups?scope=upcoming&days=7` |
| Completed | `GET /follow-ups?status=completed&sort=completed_at&direction=desc` |

### GET /follow-ups/{id}

One [FollowUp](#37-followup).

### PUT /follow-ups/{id}

Pending follow-ups only. Partial update of `title`, `description`, `activity_type`, `scheduled_at`
(same rules as POST). Sending `status`, `completed_at`, `cancelled_at` or
`prospect_id` → 422. Not pending → `422 INVALID_FOLLOW_UP_STATE`.

### POST /follow-ups/{id}/complete

Body optional: `{ "closing_note": "Reached the manager, demo booked" }` (≤2000).
Sets `status=completed`, `completed_at=now`. Not pending → `422 INVALID_FOLLOW_UP_STATE`.

Add `"create_activity": true` to **also record the activity** in the same transaction:
type = the follow-up's `activity_type` (`other` for old follow-ups without one), prospect =
the follow-up's prospect, `title` = follow-up title, `outcome` = `closing_note`. Optional
refinements: `activity_description` (≤5000), `activity_outcome` (≤5000), `activity_at`.
The activity is linked to the follow-up (`activities.follow_up_task_id` is UNIQUE), so it can
never be created twice; completing again returns `INVALID_FOLLOW_UP_STATE`.
The returned follow-up has `activity_id`.

### POST /follow-ups/{id}/convert-to-activity

For a follow-up that was completed **without** `create_activity`. Same optional body
(`activity_description`, `activity_outcome`, `activity_at`). Not completed → `422 INVALID_FOLLOW_UP_STATE`.
Idempotent: `201` with the new [Activity](#36-activity), or `200` with the existing one if
the follow-up was already converted.

### POST /follow-ups/{id}/cancel

Body optional: `{ "closing_note": "Customer asked to postpone" }`.
Sets `status=cancelled`, `cancelled_at=now`. Not pending → `422 INVALID_FOLLOW_UP_STATE`.

```json
{ "success": false, "message": "This follow-up is completed and can no longer be cancelled.",
  "error_code": "INVALID_FOLLOW_UP_STATE", "errors": null }
```

---

## 10. Products

### GET /products

Full list (not paginated), ordered by name. Query: `is_active`, `search` (name contains).

### GET /products/{id}

### POST /products

| Field | Type | Rules |
|---|---|---|
| `name` | string | **required**, unique, ≤150 |
| `description` | string\|null | ≤5000 |
| `is_active` | bool | default `true` |

### PUT /products/{id}

Partial update, same fields. **Activate/deactivate**: `{ "is_active": false }`.
A deactivated product cannot be added to prospects anymore but stays on the
prospects already linked.

### DELETE /products/{id}

Only for products never linked to any prospect (deleted prospects included).
Otherwise `409 RESOURCE_IN_USE` — deactivate it instead.

---

## 11. Sales stages

### GET /sales-stages

Full pipeline in display order (`sort_order`, then `id`). Query: `is_active`, `stage_type`.

### GET /sales-stages/{id}

### POST /sales-stages

| Field | Type | Rules |
|---|---|---|
| `name` | string | **required**, unique, ≤100 |
| `stage_type` | string | **required**: `open`, `won`, `lost` |
| `description` | string\|null | ≤500 |
| `color` | string\|null | `#RRGGBB` |
| `sort_order` | int ≥ 0 | default: after the last stage |
| `is_active` | bool | default `true` |

### PUT /sales-stages/{id}

Partial update, same fields.

- **Activate/deactivate**: `{ "is_active": false }`. Prospects already in an
  inactive stage stay there; it just cannot be chosen as a target anymore.
- `stage_type` cannot change once the stage is used by a prospect or stage
  history → `422 STAGE_TYPE_LOCKED`.
- The last active `open` stage cannot be deactivated or retyped → `422 LAST_OPEN_STAGE`.

### POST /sales-stages/reorder

Body: every stage id exactly once, in the new order. `sort_order` becomes 1..n.

```json
{ "ids": [1, 5, 2, 3, 4] }
```

Response: the full reordered list (same shape as GET /sales-stages). Missing or
unknown ids → 422 on `ids`.

### DELETE /sales-stages/{id}

Only for stages never used by a prospect (deleted ones included) or history.
Otherwise `409 RESOURCE_IN_USE`. Also refused for the last active open stage.

---

## 12. Loss reasons

### GET /loss-reasons

Full list in display order. Query: `is_active`.

### GET /loss-reasons/{id}

### POST /loss-reasons

| Field | Type | Rules |
|---|---|---|
| `name` | string | **required**, unique, ≤100 |
| `description` | string\|null | ≤500 |
| `requires_note` | bool | default `false`; when true a `loss_note` is mandatory |
| `sort_order` | int ≥ 0 | default: after the last one |
| `is_active` | bool | default `true` |

### PUT /loss-reasons/{id}

Partial update. Deactivating hides the reason from new selections; prospects and
history that use it keep displaying it.

### POST /loss-reasons/reorder

Same as sales stages: `{ "ids": [ ...every id... ] }`.

### DELETE /loss-reasons/{id}

Only if never referenced by a prospect or stage history, else `409 RESOURCE_IN_USE`.

---

## 13. Endpoint summary

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | connectivity + DB check |
| GET | `/lookups` | enums + stages + reasons + products + settings |
| GET | `/dashboard` | statistics |
| GET | `/prospects` | list (search, filters, sort, pagination) |
| POST | `/prospects` | create |
| GET | `/prospects/{id}` | detail |
| PUT/PATCH | `/prospects/{id}` | update profile and products |
| DELETE | `/prospects/{id}` | soft delete |
| POST | `/prospects/{id}/change-stage` | stage transition |
| GET | `/prospects/{id}/stage-history` | transitions |
| GET | `/prospects/{id}/activities` | activities of a prospect |
| POST | `/prospects/{id}/activities` | add activity |
| GET | `/prospects/{id}/follow-ups` | follow-ups of a prospect |
| POST | `/prospects/{id}/follow-ups` | add follow-up |
| GET | `/activities` | all activities (filters: linked, origin, type, dates, search) |
| POST | `/activities` | create an activity on its own (prospect optional) |
| GET | `/activities/{id}` | activity detail |
| POST | `/activities/{id}/link-prospect` | link / move an existing activity to a prospect |
| GET | `/follow-ups` | all follow-ups |
| GET | `/follow-ups/{id}` | detail |
| PUT/PATCH | `/follow-ups/{id}` | edit pending follow-up |
| POST | `/follow-ups/{id}/complete` | complete |
| POST | `/follow-ups/{id}/convert-to-activity` | turn a completed follow-up into an activity |
| POST | `/follow-ups/{id}/cancel` | cancel |
| GET/POST | `/products` | list / create |
| GET/PUT/PATCH/DELETE | `/products/{id}` | detail / update / delete |
| GET/POST | `/sales-stages` | list / create |
| POST | `/sales-stages/reorder` | reorder |
| GET/PUT/PATCH/DELETE | `/sales-stages/{id}` | detail / update / delete |
| GET/POST | `/loss-reasons` | list / create |
| POST | `/loss-reasons/reorder` | reorder |
| GET/PUT/PATCH/DELETE | `/loss-reasons/{id}` | detail / update / delete |

`php artisan route:list --path=api` prints the same list from the code.
