# SEMS — Testing

## Automated suite

```bash
php backend/tests/run_tests.php
```

The harness spins up its **own** API instance on port 8123 backed by a fresh
SQLite database (it never touches the demo MySQL data), then runs ~70
end-to-end HTTP tests plus unit checks. Exit code 0 = all pass.

### Coverage matrix

| Area | Tests |
|---|---|
| Registration & login | 201 on register, field validation, duplicate email 409, wrong password 401, token issue, `/auth/me`, garbage token 401 |
| Role escalation resistance | client-supplied `role=admin` at registration is ignored |
| RBAC | consumer blocked from `/customers`, `/alerts`, reports, settings (403); admin allowed |
| Consumer data isolation | consumer A cannot read meter/transactions/reports/notifications of consumer B (403) |
| Meter registration | validation of LUKU-style number, duplicate 409, assignment, duplicate assignment 409 |
| Reading validation | invalid time 400, duplicate timestamp 409, decreasing cumulative 422 (+ `data_integrity` alert), consumer forbidden from ingest |
| Consumption math | `end − start` over successive readings (5 + 4.5 = 9.5 kWh asserted) |
| Transactions | masked token storage (`****-****-5555`), negative amount 400, malformed token 400, status filter, "NOT sent to physical meter" notice |
| Anomaly rules | spike detection vs 8-day baseline, alert review flow, invalid status 400 |
| Fault reporting | submit, invalid category 400, list isolation, foreign report 403, admin status update, two-way messaging |
| Notifications | creation on genuine events, mark-read, ownership 403 |
| Reports | consumption / transactions / faults endpoints return aggregates |
| Audit | admin actions (`meter_create`, `settings_update`) appear in the audit log |
| Session | logout revokes token (subsequent request 401) |
| Input handling | non-JSON body 400, unknown endpoint 404 |
| Units | token masking, meter-number rule, TZ phone rule, password rule |

### Manual test scripts (dashboard & app)

Prerequisite: seeded demo data (`php backend/scripts/seed_demo.php`).

1. **Admin dashboard happy path** — open `http://localhost:8000`, sign in
   `admin@sems.test / Admin@SEMS123`. Verify: summary cards match
   `/api/v1/admin/summary`; 14-day chart renders; Alerts shows the seeded
   *zero consumption* and *consumption spike* alerts; open one, move it to
   *under investigation*, save, confirm audit log entry.
2. **Customer management** — register a customer, assign a new meter
   (`Meters → Register meter`), verify it appears under the customer.
3. **Reading ingest rules** — on a meter detail page add a reading lower than
   the latest; expect the server error "Reading rejected: cumulative value is
   lower…". Add a valid reading; confirm the consumption chart updates.
4. **Consumer isolation** — log into the Flutter app as `joseph@sems.test`;
   confirm only meter `07210009012` is visible; Neema's meters are not.
5. **Token purchase flow** — in the app record a purchase with token
   `1234 5678 9012 3456 7890`; verify history shows ref `****-****-7890` and
   the confirmation states nothing was sent to a physical meter.
6. **Support loop** — submit a fault in the app; as admin open it, change
   status to *in progress* and reply; verify the consumer receives both the
   status-change and reply notifications; answer from the app.
7. **Rate limiting** — set `RATE_LIMIT_MAX=3` in `.env`, restart, then fail
   login 4 times quickly; the 4th attempt must return HTTP 429.
8. **Demo reset** — `php backend/scripts/reset_demo.php`; verify simulated
   readings disappear while manually added readings remain.
