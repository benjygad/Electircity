# SEMS — Smart Electricity Management System (Tanzania)

An **academic full-stack prototype** for managing electricity records around
TANESCO/LUKU-style prepaid meters: a Flutter consumer app, a responsive
administrator web dashboard, and a shared PHP REST API over MySQL/MariaDB.

> ⚠️ **Honesty statement.** This is not an official TANESCO application.
> There is **no live utility integration**: all meter data is *simulated* and
> labelled as such (`source = simulated`), and token "purchases" are *records
> only* — the system never claims to load tokens onto a physical LUKU meter.
> A real integration can later plug into the same gateway interface
> (see `docs/FUTURE_INTEGRATION.md`).

## Stack

| Layer | Technology |
|---|---|
| Consumer app | Flutter (Dart, Material 3, fl_chart, flutter_secure_storage) |
| Admin dashboard | HTML5 + CSS3 + vanilla JS, Chart.js (vendored), responsive |
| Backend | PHP 8.x, RESTful JSON API under `/api/v1`, PDO prepared statements |
| Database | MySQL/MariaDB (SQLite variant for tests/portability) |
| Security | `password_hash()`/`password_verify()`, hashed bearer tokens, server-side RBAC, rate limiting, audit logging |

## Repository layout

```
sems/
├── backend/                  PHP REST API
│   ├── public/index.php      front controller
│   ├── src/                  Core, Controllers, Services, MeterIntegration
│   ├── scripts/              install, create_admin, seed_demo, simulate, reset_demo
│   ├── tests/run_tests.php   self-contained integration suite (~70 tests)
│   └── .env.example          configuration template (no real secrets)
├── admin-dashboard/          static SPA (served next to the API)
├── mobile_app/               Flutter consumer application
├── database/                 schema_mysql.sql · schema_sqlite.sql
├── docs/                     API.md · SETUP.md · TESTING.md · PLAN.md ·
│                             FUTURE_INTEGRATION.md · diagrams/ (arch, ERD, use-case)
└── server.php                dev router: php -S 0.0.0.0:8000 -t admin-dashboard server.php
```

## Quick start

```bash
# 1. database
mysql -u root -p < database/schema_mysql.sql        # after creating the sems DB/user (docs/SETUP.md)

# 2. backend config
cd backend && cp .env.example .env && cd ..          # set DB credentials etc.

# 3. serve dashboard + API together (dev)
php -S 0.0.0.0:8000 -t admin-dashboard server.php

# 4. first administrator (secure, non-hardcoded)
php backend/scripts/create_admin.php --email=admin@example.com --name="Site Admin"

# 5. optional demonstration data (simulated)
php backend/scripts/seed_demo.php

# 6. tests (isolated SQLite instance, no demo data touched)
php backend/tests/run_tests.php
```

Dashboard: `http://localhost:8000` · API: `http://localhost:8000/api/v1/health`.
Seeded demo logins: `admin@sems.test / Admin@SEMS123`, `neema@sems.test / Consumer@123`.
Flutter app: `cd mobile_app && flutter run --dart-define=SEMS_API_URL=http://10.0.2.2:8000/api/v1`.

## Feature highlights

**Consumer app** — registration/login/forgot-password, home dashboard with
(simulated) credit estimate, consumption charts (7/30/90 days), expenditure
breakdown, simulated token purchase recording with masked references, fault
reporting with categories/status tracking, two-way support chat, notifications.

**Admin dashboard** — live statistics, customer & meter management with
assignment, reading ingestion with validation, consumption analytics with
period comparison + CSV export, anomaly alert review workflow, fault-report
management with consumer messaging, generated reports, audit trail,
configurable tariff/detection thresholds.

**Backend** — role-based access control enforced server-side (a client cannot
self-promote), per-consumer ownership checks on every meter-scoped resource,
reading validation (duplicates/decreases rejected, never silently skewed),
rule-based anomaly engine (spike, zero-usage, missing readings, tamper events,
data integrity), low-credit & status notifications fired only on genuine
events, append-only audit log, rate-limited auth endpoints.

## Assumptions & limitations

1. **Tariff**: cost figures use a configurable demo tariff (default
   400 TZS/kWh) — an assumption until a verified tariff is available.
2. **Credit**: estimated as purchases − (units × tariff); not an authoritative
   utility balance.
3. **Tokens**: full token values are never stored/logged — only a masked
   reference (`****-****-1234`).
4. **Reset-password codes**: in `APP_ENV=development` the code is returned in
   the API response for testability; a real deployment must deliver it via
   email/SMS.
5. **Demo credentials** from the seeder are demonstration-only; remove them
   with `scripts/reset_demo.php --accounts` before any real use.
6. **Smart-home control**: not implemented by design; future device support
   must go through the gateway abstraction and be honestly labelled.

## Security checklist implemented

✔ Secure password hashing · ✔ Server-side RBAC (no client-trusted roles) ·
✔ Prepared statements everywhere · ✔ Input validation + output escaping in the
dashboard (`fmt.esc`) · ✔ Auth rate limiting · ✔ Hashed expiring bearer tokens ·
✔ Restricted CORS (`CORS_ALLOWED_ORIGIN`) · ✔ Secrets in `.env` only ·
✔ Audit logging of admin actions · ✔ Ownership checks on all consumer
resources · ✔ Safe JSON errors (no stack traces in production).

## Documentation index

- `docs/PLAN.md` — implementation plan & database design rationale
- `docs/API.md` — every endpoint, permissions, validation rules
- `docs/SETUP.md` — full installation guide (XAMPP-friendly)
- `docs/TESTING.md` — automated coverage matrix + manual test scripts
- `docs/FUTURE_INTEGRATION.md` — authorized LUKU/hardware gateway design
- `docs/diagrams/` — `architecture.svg`, `erd.svg`, `usecase.svg`
