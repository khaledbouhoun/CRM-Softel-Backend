# Security policy

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

Report it privately through GitHub's
[private vulnerability reporting](https://github.com/khaledbouhoun/CRM-Softel-Backend/security/advisories/new)
for this repository. Include the affected endpoint or file, the steps to reproduce,
and the impact you expect. You will receive an acknowledgement, and a fix will be
prepared before any details are made public.

## Supported versions

Only the latest commit on `main` receives security fixes.

## Security model

Understanding the model helps when deploying and reviewing changes:

| Surface | Protection |
|---|---|
| Staff API `/api/v1` | **No user login.** Requests are accepted only from IPs in `CRM_ALLOWED_NETWORKS`, with per-IP rate limiting. It must never be reachable from the internet. |
| Portal API `/api/portal/v1` | Shared `X-Portal-Key` (compared in constant time), optional IP allow-list, customer bearer tokens, stricter rate limit on sign-in routes. The portal is disabled while the key is empty. |
| Public API `/api/public/v1` | Read-only catalogue, rate limited. |

Secrets handling in the code:

- Portal tokens, password-reset tokens and e-mail verification codes are stored only as SHA-256 hashes; passwords use bcrypt.
- Uploaded files live on a private disk and are served only through endpoints that check access, with size and extension limits from `config/crm.php`.

## Deployment checklist

- `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`.
- `CRM_ALLOWED_NETWORKS` limited to the office subnet; never `*` on a reachable server.
- `CRM_PORTAL_SERVICE_KEY` set to a long random value, shared only with the website server; set `CRM_PORTAL_ALLOWED_NETWORKS` to that server's IP when possible.
- MySQL bound to `127.0.0.1` with a dedicated user ([docs/DEPLOYMENT_LAN.md](docs/DEPLOYMENT_LAN.md)).
- `.env` and backups never committed or shared; backups stored off the server ([docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md)).
- Keep PHP, MySQL and Composer dependencies up to date (`composer audit`).
