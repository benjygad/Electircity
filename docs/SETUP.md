# SEMS — Installation & Setup

Tested with PHP 8.4, MariaDB 11.x (MySQL 8 also fine), Flutter 3.22+.
XAMPP users: the same steps apply — use XAMPP's PHP and MariaDB, and place
`backend/` + `admin-dashboard/` under `htdocs` or point the built-in server at them.

## 1. Database

```bash
# create database + application user (adjust the password!)
mysql -u root -p <<'SQL'
CREATE DATABASE sems CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sems_app'@'localhost' IDENTIFIED BY 'CHOOSE_A_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON sems.* TO 'sems_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# apply schema
mysql -u sems_app -p sems < database/schema_mysql.sql
```

## 2. Backend configuration

```bash
cd backend
cp .env.example .env
# edit .env: DB_USER/DB_PASS, CORS_ALLOWED_ORIGIN (dashboard origin), APP_ENV
```

In production set `APP_ENV=production`, `APP_DEBUG=0` and
`CORS_ALLOWED_ORIGIN=https://your-dashboard-host`.

## 3. Run (development)

```bash
# from the project root
php -S 0.0.0.0:8000 -t admin-dashboard server.php
# → dashboard: http://localhost:8000
# → API:       http://localhost:8000/api/v1
```

### Apache alternative

Point a vhost's document root at `admin-dashboard/` and alias `/api/v1` to
`backend/public/index.php` (set `SEMS_API_BASE` accordingly), or run two vhosts.

## 4. Create the administrator (secure path)

Credentials are **never hardcoded**. Create the first admin via CLI:

```bash
php backend/scripts/create_admin.php --email=you@example.com --name="Your Name"
# prompts for a password interactively (hidden input)
```

## 5. Demonstration data (optional)

```bash
php backend/scripts/seed_demo.php
```

Seeds demo accounts and 35 days of simulated readings/anomalies:

| Account | Email | Password | Role |
|---|---|---|---|
| Demo admin | `admin@sems.test` | `Admin@SEMS123` | admin |
| Neema Massawe | `neema@sems.test` | `Consumer@123` | consumer (2 meters) |
| Joseph Mushi | `joseph@sems.test` | `Consumer@123` | consumer (1 meter) |
| Amina Juma | `amina@sems.test` | `Consumer@123` | consumer (1 meter) |

> These are **demonstration-only** credentials. Remove them (`reset_demo.php
> --accounts`) before any non-academic use.

## 6. Simulator

```bash
php backend/scripts/simulate.php                    # advance all meters 1 day
php backend/scripts/simulate.php --days=7           # advance a week
php backend/scripts/simulate.php --meter=07210001234 --scenario=spike
# scenarios: spike | zero | outage | tamper
```

Detection rules run automatically when readings are ingested and after each
simulation run.

## 7. Reset demo data (keeps manual/real records)

```bash
php backend/scripts/reset_demo.php            # simulated readings/purchases/alerts
php backend/scripts/reset_demo.php --accounts # also remove @sems.test accounts
```

## 8. Flutter app

```bash
cd mobile_app
flutter pub get
flutter run --dart-define=SEMS_API_URL=http://10.0.2.2:8000/api/v1   # Android emulator
```

See `mobile_app/README.md` for physical-device networking notes.

## 9. Tests

```bash
php backend/tests/run_tests.php    # self-contained: uses a private SQLite DB
```

See `docs/TESTING.md` for manual test scripts.
