/* SEMS admin dashboard — API client. Talks to /api/v1 on the same origin. */
'use strict';

const SEMS = {
  base: '/api/v1',
  get token() { return localStorage.getItem('sems_admin_token'); },
  set token(v) { v ? localStorage.setItem('sems_admin_token', v) : localStorage.removeItem('sems_admin_token'); },
  get user() { try { return JSON.parse(localStorage.getItem('sems_admin_user') || 'null'); } catch { return null; } },
  set user(v) { v ? localStorage.setItem('sems_admin_user', JSON.stringify(v)) : localStorage.removeItem('sems_admin_user'); },
};

SEMS.request = async function (method, path, body) {
  const headers = { 'Accept': 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (SEMS.token) headers['Authorization'] = 'Bearer ' + SEMS.token;
  const res = await fetch(SEMS.base + path, {
    method, headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  let json = {};
  try { json = await res.json(); } catch { /* non-JSON error */ }
  if (!res.ok) {
    const msg = json?.error?.message || ('HTTP ' + res.status);
    const err = new Error(msg);
    err.status = res.status; err.fields = json?.error?.fields || null;
    if (res.status === 401 && !path.startsWith('/auth/login')) { SEMS.token = null; SEMS.user = null; location.hash = '#/login'; }
    throw err;
  }
  return json;
};

SEMS.get = (p) => SEMS.request('GET', p);
SEMS.post = (p, b) => SEMS.request('POST', p, b);
SEMS.patch = (p, b) => SEMS.request('PATCH', p, b);

/* ---------- formatting helpers ---------- */
const fmt = {
  num: (n, dp = 0) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp }),
  tzs: (n) => 'TZS ' + fmt.num(n),
  kwh: (n) => fmt.num(n, 1) + ' kWh',
  dt(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleString('en-GB', { timeZone: 'Africa/Dar_es_Salaam', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  },
  day(iso) {
    if (!iso) return '—';
    const d = new Date(iso + (iso.length === 10 ? 'T12:00:00Z' : ''));
    return isNaN(d) ? iso : d.toLocaleDateString('en-GB', { timeZone: 'Africa/Dar_es_Salaam', day: '2-digit', month: 'short', year: 'numeric' });
  },
  esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; },
};

const STATUS_BADGES = {
  // alerts
  new: 'b-red', under_investigation: 'b-amber', confirmed: 'b-red', dismissed: 'b-gray', resolved: 'b-green',
  // fault reports
  submitted: 'b-blue', under_review: 'b-amber', assigned: 'b-purple', in_progress: 'b-amber', resolved: 'b-green', closed: 'b-gray',
  // meters
  connected: 'b-green', simulated: 'b-blue', offline: 'b-gray', integration_unavailable: 'b-red',
  // severities
  info: 'b-blue', warning: 'b-amber', critical: 'b-red',
  // users / transactions
  active: 'b-green', suspended: 'b-red', recorded: 'b-green', pending: 'b-amber', failed: 'b-red',
};
const badge = (v) => `<span class="badge ${STATUS_BADGES[v] || 'b-gray'}">${fmt.esc(String(v || '—').replace(/_/g, ' '))}</span>`;

const LABELS = {
  power_outage: 'Power outage', meter_problem: 'Meter problem',
  suspected_incorrect_reading: 'Suspected incorrect reading', supply_issue: 'Supply issue', other: 'Other',
  consumption_spike: 'Consumption spike', zero_consumption: 'Zero consumption', missing_readings: 'Missing readings',
  tamper_event: 'Tamper event', data_integrity: 'Data integrity',
  single_phase_prepaid: 'Single-phase prepaid', three_phase_prepaid: 'Three-phase prepaid',
};
const label = (v) => fmt.esc(LABELS[v] || String(v || '—').replace(/_/g, ' '));
