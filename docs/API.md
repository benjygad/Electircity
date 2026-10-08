# SEMS REST API Documentation (v1)

Base URL: `/api/v1` · JSON requests/responses · Bearer-token authentication.

## Conventions

- **Envelope**: success → `{ "success": true, "data": …, "meta": {pagination?} }`;
  error → `{ "success": false, "error": { "message": …, "fields": {…}? } }`.
- **Auth**: `Authorization: Bearer <token>` issued by `POST /auth/login`.
  Tokens are stored server-side hashed (SHA-256) and expire (`TOKEN_TTL_HOURS`).
- **Roles**: `consumer`, `admin` (enforced server-side; `technician`/`support`
  reserved for the future). Clients cannot escalate — a `role` field sent by a
  client at registration is ignored.
- **Pagination**: `?page=1&limit=20` (max 100) → `meta.total`, `meta.page`, `meta.limit`.
- **Timestamps**: stored UTC; serialized `YYYY-MM-DDTHH:MM:SSZ`. Clients render locally.
- **Errors**: 400 validation · 401 auth · 403 authorization/ownership ·
  404 missing · 409 conflict · 422 semantic rejection (e.g. decreasing reading) ·
  429 rate-limited. Stack traces are hidden unless `APP_DEBUG=1`.

## Endpoints

| Method | Path | Access | Purpose | Validation notes |
|---|---|---|---|---|
| GET | `/health` | public | Liveness | — |
| GET | `/system/gateway` | any user | Active meter gateway + honesty notice | — |
| POST | `/auth/register` | public | Consumer self-registration | name, valid email, password ≥8 with letter+number, TZ phone optional; role forced to `consumer` |
| POST | `/auth/login` | public | Login, issues bearer token | rate-limited |
| POST | `/auth/forgot-password` | public | Issue 6-digit reset code (30 min TTL) | rate-limited; no account enumeration; code returned only when `APP_ENV=development` |
| POST | `/auth/reset-password` | public | Reset password with code | revokes all user tokens |
| POST | `/auth/logout` | any | Revoke current token | — |
| GET | `/auth/me` | any | Current profile (+consumer's meters) | — |
| PATCH | `/auth/me` | any | Update name/phone/password | server-side validation |
| GET | `/customers` | admin | List/search customers (`q`, `status`) | — |
| POST | `/customers` | admin | Create customer (or technician/support) | unique email; cannot create admins |
| GET | `/customers/{id}` | admin | Customer detail + meters | — |
| PATCH | `/customers/{id}` | admin | Update name/phone/status | — |
| GET | `/meters` | any | Admin: all (filters `q`, `integration_status`). Consumer: own only | — |
| POST | `/meters` | admin | Register meter (optionally assign `user_id`) | LUKU-style meter number, unique |
| GET | `/meters/{id}` | owner or admin | Meter detail + credit estimate + owners | ownership enforced |
| POST | `/meters/{id}/assign` | admin | Assign/unassign customer (`action: assign|unassign`) | FK-checked |
| GET | `/meters/{id}/readings` | owner or admin | Reading history (paginated) | — |
| POST | `/meters/{id}/readings` | admin | Ingest reading (`reading_time`, `cumulative_kwh`, `source`) | rejects duplicates (409), decreases (422 + data-integrity alert), invalid input (400); triggers anomaly scan + low-credit check |
| GET | `/meters/{id}/consumption` | owner or admin | Daily series + totals (`from`, `to`) | YYYY-MM-DD |
| GET | `/meters/{id}/transactions` | owner or admin | Token purchase records (`status`, `from`, `to`) | — |
| POST | `/meters/{id}/transactions` | owner or admin | Record SIMULATED purchase (`amount`, optional `token`) | token masked before storage; gateway result included |
| GET | `/alerts` | admin | Alerts (`status`, `severity`, `meter_id`) | — |
| GET | `/alerts/{id}` | admin | Alert detail | — |
| PATCH | `/alerts/{id}` | admin | Review: status / assigned_to / admin_notes | statuses: new, under_investigation, confirmed, dismissed, resolved |
| GET | `/fault-reports` | any | Consumer: own reports. Admin: all (`status`, `category`, `q`) | — |
| POST | `/fault-reports` | any | Submit report | category enum, meter must belong to consumer |
| GET | `/fault-reports/{id}` | owner or admin | Detail + message thread | — |
| PATCH | `/fault-reports/{id}` | admin | Status/assignment | consumer notified |
| GET | `/fault-reports/{id}/messages` | owner or admin | Thread | — |
| POST | `/fault-reports/{id}/messages` | owner or admin | Reply | other party notified |
| GET | `/notifications` | any | Own notifications | `meta.unread` |
| PATCH | `/notifications/{id}` | any | Mark read | ownership enforced |
| POST | `/notifications/read-all` | any | Mark all read | — |
| GET | `/reports/consumption` | admin | Daily + per-meter aggregates (`from`, `to`, `meter_id`, `user_id`) | — |
| GET | `/reports/transactions` | admin | Purchase records + totals | — |
| GET | `/reports/faults` | admin | By status/category + recent | — |
| GET | `/admin/summary` | admin | Dashboard statistics | — |
| GET | `/admin/audit-logs` | admin | Audit trail (paginated) | — |
| GET | `/admin/settings` | admin | Settings list | — |
| PATCH | `/admin/settings` | admin | Update tariff/thresholds (audited) | numeric ≥ 0 |

## Example

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"neema@sems.test","password":"Consumer@123"}' | jq -r .data.token)

curl localhost:8000/api/v1/meters -H "Authorization: Bearer $TOKEN"
```
