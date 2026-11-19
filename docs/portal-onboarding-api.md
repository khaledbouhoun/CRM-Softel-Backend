# Website portal: onboarding, profile, wilayas, product checklist

All routes are under `/api/portal/v1` and need the `X-Portal-Key` header (server to server). Routes marked 🔒 also need the customer's bearer token.

## 1. Sign-in and first-login redirect
`POST auth/register`, `POST auth/login`, `POST auth/google` all return `data.onboarding`:

```json
{ "required": true, "is_first_login": true, "redirect_to": "new_request",
  "options": [ {"type": "demo", "label": "Presentation"}, {"type": "trial", "label": "Demo version"} ] }
```
`redirect_to` is `new_request` until the customer's first request is submitted, then `overview`.
Send `type: "demo"` for a Presentation (a time slot is held) and `type: "trial"` for the Demo version.

`POST auth/google` also accepts `avatar_url` (https only). It is saved to the account on every Google sign-in; a sign-in without a picture keeps the saved one.

## 2. Request form with autofill 🔒
`GET me` and `GET profile` return `request_defaults`: company, contact_name, job_title, phone, email, wilaya, city, business_activity. Show them pre-filled.
`POST requests` only needs `type` plus `product_id` or `product_ids`; anything left out is taken from the saved details, and what the customer typed replaces them.
Missing details with nothing saved still answer 422 with the field names.

## 3. Profile page 🔒
`GET profile` returns `account` (with `profile` and `avatar_url`), `onboarding`, `request_defaults`, `status`
(`account_type` visitor|prospect|customer, `open_requests`, `latest_request`, `owned_products`) and `requests` (history, newest first).
`PATCH me` accepts name, phone, locale, company, contact_name, job_title, wilaya, city, business_activity.
The avatar is read-only (it comes from Google). Intervention chat messages carry `sender.avatar_url`.

## 4. Wilayas
`GET catalog/wilayas` (no token) returns 58 items `{code, name, name_ar, label}`, e.g. `{"code":"16","name":"Alger","label":"16 - Alger"}`.
Staff: `GET /api/v1/lookups` → `data.wilayas`.
Send `wilaya` as the code or the label; the API also accepts the French or Arabic name and always stores/returns `"16 - Alger"`.
Unknown values answer 422 on `wilaya`.

## 5. Product checklist
`GET catalog/checklist` returns groups:
- `type: "pack"`, `selection: "single"`: radio behaviour. Each product has `exclusive_with` (ids to uncheck when it is ticked).
- `type: "independent"`, `selection: "multiple"`: plain checkboxes.

Submit the ticked ids as `product_ids`. Two products of the same pack answer 422 on `product_ids`. A time slot (`slot_start`) can only go with a single product.
