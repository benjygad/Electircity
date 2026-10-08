# SEMS — Implementation Plan & Database Design

## 1. Scope summary

Smart Electricity Management System (SEMS) is an **academic prototype** for managing
TANESCO/LUKU-style prepaid electricity data in Tanzania. It has:

- **Consumer mobile app** (Flutter) — meters, simulated token records, consumption
  monitoring, expenditure, fault reporting, support messaging, notifications.
- **Admin web dashboard** (HTML5/CSS3/JS + Chart.js) — customers, meters, consumption
  analytics, anomaly alerts, fault management, reports, audit logs.
- **Shared PHP REST API** (`/api/v1`) + **MySQL/MariaDB** database.

No real TANESCO/LUKU integration exists. All meter data is **simulated** and flagged
via a `source` column. Token operations are recorded/simulated only — the app never
claims to load tokens into physical meters.

## 2. Module build order

| # | Module | Deliverable |
|---|--------|-------------|
| 1 | Database | `database/schema_mysql.sql` (+SQLite variant for tests), installer |
| 2 | Backend core | config/env, PDO wrapper, router, JSON responses, errors |
| 3 | Auth & RBAC | register/login/logout/me, forgot+reset password, bearer tokens, rate limiting |
| 4 | Customers & meters | CRUD + assignment + ownership checks |
| 5 | Readings & consumption | reading ingestion, delta computation, daily consumption records, expenditure estimates |
| 6 | Token transactions | simulated purchase recording (masked token refs), filters |
| 7 | Anomaly engine | rule-based detection (spike, zero usage, missing readings, tamper, decreasing cumulative) |
| 8 | Faults & support | reports, statuses, assignment, messaging, notifications |
| 9 | Reports & audit | consumption/transactions/faults reports, audit log endpoints |
| 10 | Admin dashboard | responsive SPA over the API with Chart.js |
| 11 | Flutter app | full consumer app source against the same API |
| 12 | Simulator + tests | CLI simulator, seeded demo data, integration test suite |
| 13 | Docs | README, API doc, setup, testing, diagrams (architecture/ERD/use-case), future integration design |

## 3. Key design decisions

- **Auth**: bearer API tokens (random, stored hashed SHA-256, expiring). Works for both
  the mobile app and the dashboard; avoids CSRF-by-design since tokens go in the
  `Authorization` header. Passwords via `password_hash()`/`password_verify()`.
- **RBAC**: roles enforced **server-side** per route (`consumer`, `admin`; schema reserves
  `technician`, `support` for the future). Client-supplied role values are never trusted.
- **Ownership**: every consumer-scoped query joins `user_meters` for the authenticated
  user; IDs in URLs are re-checked, never trusted.
- **Meter integration layer**: `MeterGatewayInterface` with a `SimulatedGateway`
  implementation today and a documented `LukuGateway` stub for a future authorized
  integration — swapping gateways does not touch the API layer.
- **Honest data labelling**: `meter_readings.source`, `consumption_records.source`,
  `token_transactions.source` ∈ {`simulated`,`manual`,`imported`,`hardware`} — the UIs
  surface these labels; simulated values are never presented as live TANESCO data.
- **Credit**: computed as `Σ purchases − Σ(consumed kWh × demo tariff)`. Tariff is a
  configurable setting (default 400 TZS/kWh) and labelled a demonstration assumption.
- **Tokens**: users may enter 20-digit LUKU-style tokens, but only a **masked reference**
  (last 4 digits) is stored; full values are never persisted or logged.
- **Timezone**: all timestamps stored in UTC (ISO-8601); clients render locally
  (default Africa/Dar es Salaam).

## 4. Database design

Normalized to 3NF. All tables have PK `id`, FK constraints, indexes on lookup columns,
`created_at`/`updated_at` where rows mutate. History tables (readings, transactions,
consumption, audit) are append-only — updates never overwrite history.

### Tables

| Table | Purpose | Key constraints |
|---|---|---|
| `users` | All accounts (consumers, admins; future technician/support) | unique `email`; `role` ∈ consumer/admin/technician/support; `status` active/suspended |
| `meters` | Electricity meters | unique `meter_number`; `integration_status` ∈ connected/simulated/offline/integration_unavailable |
| `user_meters` | Customer↔meter association | unique(user_id,meter_id); `relationship` owner/tenant |
| `token_transactions` | Prepaid purchase records (simulated) | FK meter; `status` recorded/pending/failed; masked `token_reference`; `source` |
| `meter_readings` | Cumulative kWh readings (append-only) | FK meter; `source`; index(meter_id, reading_time); unique(meter_id, reading_time) |
| `consumption_records` | Daily energy derived from readings | FK meter; unique(meter_id, period_start); `source` inherited |
| `alerts` | Anomaly alerts (rule engine output) | FK meter; `status` new/under_investigation/confirmed/dismissed/resolved; `severity` info/warning/critical |
| `fault_reports` | Consumer fault/complaint reports | FK user, meter; `status` submitted/under_review/assigned/in_progress/resolved/closed |
| `support_messages` | Messages on a fault report | FK report, sender; append-only |
| `notifications` | In-app notifications per user | FK user; `read_at` nullable |
| `audit_logs` | Admin/system actions | FK actor; append-only |
| `auth_tokens` | Hashed bearer tokens | FK user; expires_at; unique token_hash |
| `password_resets` | Reset codes (hashed) | FK user; short TTL |
| `settings` | Key/value config (tariff, anomaly thresholds) | unique key |
| `rate_limits` | Auth rate limiting buckets | PK bucket key |

### Derivation rules

- `consumption_records.energy_kwh = reading(n).cumulative_kwh − reading(n−1).cumulative_kwh`
  for valid successive pairs; decreasing/duplicate/invalid readings are rejected and can
  raise a `data_integrity` alert — they never silently produce wrong consumption.
- Available credit (per meter) = `Σ token_transactions.amount (successful) −
  Σ consumption_records.energy_kwh × tariff`. Computed on demand, not stored destructively.

### Security-relevant schema rules

- FKs enforce every relationship; `ON DELETE` behaviour chosen to preserve history
  (RESTRICT on meters/customers with data; CASCADE only for auth-owned rows).
- Meter numbers unique; token references stored masked only.
- DB credentials live in `backend/.env` (never in frontend code; `.env.example` ships
  with placeholders).

## 5. Verification strategy

- CLI integration suite (`backend/tests/run_tests.php`) boots the API against the real
  MariaDB and asserts: auth flows, RBAC, consumer isolation, meter/reading validation,
  consumption math, anomaly rules, fault workflow, messaging, notifications, reports,
  DB constraint violations.
- Manual test scripts documented in `docs/TESTING.md`.
