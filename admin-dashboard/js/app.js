/* SEMS Admin Dashboard — single page app over /api/v1 */
'use strict';

const view = () => document.getElementById('view');
const charts = {};
function chart(id, cfg) {
  if (charts[id]) { charts[id].destroy(); delete charts[id]; }
  const el = document.getElementById(id);
  if (!el || typeof Chart === 'undefined') return null;
  charts[id] = new Chart(el.getContext('2d'), cfg);
  return charts[id];
}
Chart.defaults.font.family = '"Segoe UI", system-ui, sans-serif';
Chart.defaults.color = '#61708c';

const NAV = [
  ['dashboard', '⚡', 'Dashboard'],
  ['customers', '👥', 'Customers'],
  ['meters', '🔌', 'Meters'],
  ['consumption', '📈', 'Consumption'],
  ['alerts', '🚨', 'Alerts'],
  ['faults', '🛠️', 'Fault Reports'],
  ['reports', '📄', 'Reports'],
  ['audit', '🧾', 'Audit Logs'],
  ['settings', '⚙️', 'Settings'],
];

function renderShell(active) {
  document.getElementById('app').innerHTML = `
  <div class="shell">
    <aside class="sidebar" id="sidebar">
      <div class="brand"><div class="bolt">⚡</div><div><b>SEMS</b><small>Admin · Tanzania</small></div></div>
      <nav class="nav" id="nav">
        ${NAV.map(([k, ic, t]) => `<a href="#/${k}" data-k="${k}"><span class="ic">${ic}</span>${t}<span class="pill" id="pill-${k}" style="display:none"></span></a>`).join('')}
      </nav>
      <div class="foot">Academic prototype.<br>Meter data shown is <b>simulated</b> — no live TANESCO connection.</div>
    </aside>
    <div class="main">
      <header class="topbar">
        <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
        <h1 id="page-title">Dashboard</h1>
        <div class="who">
          <div class="avatar">${fmt.esc((SEMS.user?.full_name || 'A').trim()[0].toUpperCase())}</div>
          <span>${fmt.esc(SEMS.user?.full_name || 'Administrator')}</span>
          <button class="btn ghost sm" id="logout-btn">Logout</button>
        </div>
      </header>
      <main class="content" id="view"></main>
    </div>
  </div>`;
  document.getElementById('logout-btn').onclick = async () => {
    try { await SEMS.post('/auth/logout', {}); } catch { /* token may already be dead */ }
    SEMS.token = null; SEMS.user = null; location.hash = '#/login';
  };
  setActiveNav(active);
  refreshPills();
}
function setActiveNav(k) {
  document.querySelectorAll('#nav a').forEach(a => a.classList.toggle('active', a.dataset.k === k));
}
async function refreshPills() {
  try {
    const [a, f] = await Promise.all([SEMS.get('/alerts?status=new&limit=1'), SEMS.get('/fault-reports?status=submitted&limit=1')]);
    setPill('alerts', a.meta?.total); setPill('faults', f.meta?.total);
  } catch { /* ignore */ }
}
function setPill(k, n) {
  const p = document.getElementById('pill-' + k);
  if (!p) return;
  p.style.display = n > 0 ? '' : 'none';
  p.textContent = n;
}
const loading = () => `<div class="loading"><span class="spin"></span>Loading…</div>`;
const emptyBox = (icon, msg) => `<div class="empty"><div class="big">${icon}</div>${fmt.esc(msg)}</div>`;
function showError(e) { view().innerHTML = `<div class="notice err">⚠️ ${fmt.esc(e.message)}</div>`; }
function pager(meta, onPage) {
  const pages = Math.max(1, Math.ceil((meta.total || 0) / (meta.limit || 20)));
  if (pages <= 1 && meta.total <= (meta.limit || 20)) return '';
  return `<div class="pager">Page ${meta.page} of ${pages} · ${meta.total} records
    <button class="btn ghost sm" ${meta.page <= 1 ? 'disabled' : ''} data-pg="${meta.page - 1}">‹ Prev</button>
    <button class="btn ghost sm" ${meta.page >= pages ? 'disabled' : ''} data-pg="${meta.page + 1}">Next ›</button></div>`;
}
function bindPager(container, onPage) {
  container.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => onPage(Number(b.dataset.pg)));
}

/* ================================================================ LOGIN */
function loginView() {
  document.getElementById('app').innerHTML = `
  <div class="login-wrap"><div class="login-card">
    <div class="bolt">⚡</div>
    <h1>SEMS Administration</h1>
    <p class="sub">Smart Electricity Management System — Tanzania (academic prototype, simulated data)</p>
    <div id="login-err"></div>
    <label>Email</label><input id="li-email" type="email" autocomplete="username" placeholder="admin@example.com">
    <label>Password</label><input id="li-pass" type="password" autocomplete="current-password" placeholder="••••••••">
    <div style="margin-top:18px"><button class="btn primary" style="width:100%;justify-content:center" id="li-btn">Sign in</button></div>
    <p class="sub" style="margin-top:16px;font-size:11.5px">Demo account: <span class="mono">admin@sems.test / Admin@SEMS123</span></p>
  </div></div>`;
  const go = async () => {
    const btn = document.getElementById('li-btn');
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>Signing in…';
    try {
      const j = await SEMS.post('/auth/login', { email: document.getElementById('li-email').value, password: document.getElementById('li-pass').value });
      if (j.data.user.role !== 'admin') { SEMS.token = null; throw new Error('This account does not have administrator access.'); }
      SEMS.token = j.data.token; SEMS.user = j.data.user;
      location.hash = '#/dashboard';
    } catch (e) {
      document.getElementById('login-err').innerHTML = `<div class="notice err">${fmt.esc(e.message)}</div>`;
      btn.disabled = false; btn.textContent = 'Sign in';
    }
  };
  document.getElementById('li-btn').onclick = go;
  document.getElementById('li-pass').onkeydown = (e) => { if (e.key === 'Enter') go(); };
}

/* ============================================================ DASHBOARD */
async function dashboardView() {
  view().innerHTML = loading();
  const s = (await SEMS.get('/admin/summary')).data;
  const byStatus = Object.fromEntries((s.meters_by_status || []).map(r => [r.integration_status, Number(r.cnt)]));
  view().innerHTML = `
  <div class="notice info">📌 All statistics below are computed from the SEMS database. In this academic prototype the database is seeded with <b>simulated demonstration data</b> — it is not live TANESCO/LUKU data.</div>
  <div class="cards">
    <div class="card stat"><div class="icon i-blue">👥</div><div><div class="val">${fmt.num(s.consumers)}</div><div class="lbl">Registered consumers</div></div></div>
    <div class="card stat"><div class="icon i-teal">🔌</div><div><div class="val">${fmt.num(s.meters)}</div><div class="lbl">Registered meters (${fmt.num(byStatus.simulated || 0)} simulated · ${fmt.num(byStatus.connected || 0)} connected)</div></div></div>
    <div class="card stat"><div class="icon i-green">⚡</div><div><div class="val">${fmt.kwh(s.consumption_last_7d_kwh)}</div><div class="lbl">Consumption · last 7 days</div></div></div>
    <div class="card stat"><div class="icon i-purple">💰</div><div><div class="val">${fmt.tzs(s.purchases_last_30d_tzs)}</div><div class="lbl">Token purchases · last 30 days</div></div></div>
    <div class="card stat"><div class="icon i-red">🚨</div><div><div class="val">${fmt.num(s.open_alerts)}</div><div class="lbl">Unresolved alerts (${fmt.num(s.critical_alerts)} critical)</div></div></div>
    <div class="card stat"><div class="icon i-amber">🛠️</div><div><div class="val">${fmt.num(s.open_fault_reports)}</div><div class="lbl">Open fault reports</div></div></div>
  </div>
  <div class="grid3">
    <div class="panel"><div class="head"><h2>Consumption — last 14 days (all meters)</h2><span class="badge b-teal">simulated</span></div>
      <div class="body"><canvas id="ch-dash" height="110"></canvas></div></div>
    <div class="panel"><div class="head"><h2>Recent system activity</h2></div><div class="body flush">
      <table class="tbl"><tbody>
        ${(s.recent_activity || []).map(a => `<tr><td><b>${fmt.esc(a.action.replace(/_/g, ' '))}</b><div class="muted" style="font-size:11.5px">${fmt.esc(a.entity_type)}${a.entity_id ? ' #' + a.entity_id : ''} · ${fmt.esc(a.actor_name || 'system')}</div></td><td class="num muted">${fmt.dt(a.created_at)}</td></tr>`).join('') || `<tr><td>${emptyBox('🧾', 'No activity yet.')}</td></tr>`}
      </tbody></table></div></div>
  </div>
  <div class="panel"><div class="head"><h2>Recently registered consumers</h2><a class="btn ghost sm" href="#/customers">View all</a></div>
    <div class="body flush"><table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Registered</th></tr></thead><tbody>
      ${(s.recent_registrations || []).map(r => `<tr><td>${fmt.esc(r.full_name)}</td><td>${fmt.esc(r.email)}</td><td>${fmt.dt(r.created_at)}</td></tr>`).join('')}
    </tbody></table></div></div>`;
  const days = s.consumption_by_day_14d || [];
  chart('ch-dash', {
    type: 'line',
    data: { labels: days.map(d => fmt.day(d.day)), datasets: [{ label: 'kWh', data: days.map(d => Number(d.kwh)), borderColor: '#0ea5b7', backgroundColor: 'rgba(14,165,183,.14)', fill: true, tension: .35, pointRadius: 3 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#edf1f7' } }, x: { grid: { display: false } } } },
  });
}

/* ============================================================ CUSTOMERS */
let custFilters = { q: '', status: '', page: 1 };
async function customersView() {
  const draw = async () => {
    const qs = new URLSearchParams({ page: custFilters.page, limit: 12 });
    if (custFilters.q) qs.set('q', custFilters.q);
    if (custFilters.status) qs.set('status', custFilters.status);
    const j = (await SEMS.get('/customers?' + qs));
    const rows = j.data;
    document.getElementById('cust-body').innerHTML = rows.length ? `
      <table class="tbl"><thead><tr><th>Customer</th><th>Contact</th><th>Meters</th><th>Status</th><th>Registered</th><th></th></tr></thead><tbody>
      ${rows.map(c => `<tr>
        <td><b>${fmt.esc(c.full_name)}</b><div class="muted" style="font-size:11.5px">${fmt.esc(c.role)}</div></td>
        <td>${fmt.esc(c.email)}<div class="muted" style="font-size:11.5px">${fmt.esc(c.phone || '')}</div></td>
        <td>${fmt.num(c.meter_count)}</td>
        <td>${badge(c.status)}</td><td>${fmt.day(c.created_at)}</td>
        <td class="num"><a class="btn ghost sm" href="#/customers/${c.id}">Open</a></td></tr>`).join('')}
      </tbody></table>${pager(j.meta, (p) => { custFilters.page = p; draw(); })}` : emptyBox('👥', 'No customers match your filters.');
    bindPager(document.getElementById('cust-body'), (p) => { custFilters.page = p; draw(); });
  };
  view().innerHTML = `
  <div class="panel"><div class="head"><h2>Customers</h2>
    <div class="filters">
      <input id="cust-q" placeholder="Search name / email / phone" value="${fmt.esc(custFilters.q)}">
      <select id="cust-status"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select>
      <button class="btn primary" id="cust-add">+ Register customer</button>
    </div></div>
    <div class="body flush" id="cust-body">${loading()}</div></div>
  <div id="modal-root"></div>`;
  document.getElementById('cust-status').value = custFilters.status;
  let t; document.getElementById('cust-q').oninput = (e) => { clearTimeout(t); t = setTimeout(() => { custFilters.q = e.target.value; custFilters.page = 1; draw(); }, 300); };
  document.getElementById('cust-status').onchange = (e) => { custFilters.status = e.target.value; custFilters.page = 1; draw(); };
  document.getElementById('cust-add').onclick = () => customerModal(draw);
  await draw();
}

function customerModal(onDone, existing) {
  const root = document.getElementById('modal-root');
  root.innerHTML = `<div style="position:fixed;inset:0;background:rgba(13,27,46,.55);display:grid;place-items:center;z-index:99;padding:16px">
    <div class="card" style="width:100%;max-width:480px;padding:22px 22px 18px">
      <h2 style="margin:0 0 4px">${existing ? 'Edit customer' : 'Register customer'}</h2>
      <div id="cm-err"></div>
      <div class="form-grid">
        <div><label>Full name</label><input id="cm-name" value="${fmt.esc(existing?.full_name || '')}"></div>
        <div><label>Phone (+255…)</label><input id="cm-phone" value="${fmt.esc(existing?.phone || '')}"></div>
      </div>
      <label>Email</label><input id="cm-email" ${existing ? 'disabled' : ''} value="${fmt.esc(existing?.email || '')}">
      ${existing ? '' : '<label>Initial password</label><input id="cm-pass" type="password">'}
      ${existing ? `<label>Account status</label><select id="cm-status"><option value="active">Active</option><option value="suspended">Suspended</option></select>` : ''}
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
        <button class="btn ghost" id="cm-cancel">Cancel</button>
        <button class="btn primary" id="cm-save">${existing ? 'Save changes' : 'Create customer'}</button>
      </div>
    </div></div>`;
  if (existing) document.getElementById('cm-status').value = existing.status;
  document.getElementById('cm-cancel').onclick = () => root.innerHTML = '';
  document.getElementById('cm-save').onclick = async () => {
    const body = { full_name: document.getElementById('cm-name').value, phone: document.getElementById('cm-phone').value };
    try {
      if (existing) {
        body.status = document.getElementById('cm-status').value;
        if (!body.phone) body.phone = '';
        await SEMS.patch('/customers/' + existing.id, body);
      } else {
        body.email = document.getElementById('cm-email').value;
        body.password = document.getElementById('cm-pass').value;
        await SEMS.post('/customers', body);
      }
      root.innerHTML = ''; onDone && onDone();
    } catch (e) {
      const fields = e.fields ? Object.entries(e.fields).map(([k, v]) => `${k}: ${v}`).join(' · ') : '';
      document.getElementById('cm-err').innerHTML = `<div class="notice err">${fmt.esc(e.message)}${fields ? '<br>' + fmt.esc(fields) : ''}</div>`;
    }
  };
}

async function customerDetailView(id) {
  view().innerHTML = loading();
  const c = (await SEMS.get('/customers/' + id)).data;
  view().innerHTML = `
  <p><a href="#/customers">← Customers</a></p>
  <div class="grid3">
    <div>
      <div class="panel"><div class="head"><h2>${fmt.esc(c.full_name)} ${badge(c.status)}</h2>
        <button class="btn ghost sm" id="c-edit">Edit</button></div>
        <div class="body"><dl class="kv">
          <dt>Email</dt><dd>${fmt.esc(c.email)}</dd>
          <dt>Phone</dt><dd>${fmt.esc(c.phone || '—')}</dd>
          <dt>Role</dt><dd>${fmt.esc(c.role)}</dd>
          <dt>Registered</dt><dd>${fmt.dt(c.created_at)}</dd>
        </dl></div></div>
      <div class="panel"><div class="head"><h2>Meters (${c.meters.length})</h2></div><div class="body flush">
        <table class="tbl"><thead><tr><th>Meter №</th><th>Location</th><th>Integration</th><th></th></tr></thead><tbody>
        ${c.meters.map(m => `<tr><td class="mono">${fmt.esc(m.meter_number)}</td><td>${fmt.esc(m.service_location)}</td><td>${badge(m.integration_status)}</td><td class="num"><a class="btn ghost sm" href="#/meters/${m.id}">Open</a></td></tr>`).join('') || `<tr><td colspan="4">${emptyBox('🔌', 'No meters assigned yet. Use Meters → Assign.')}</td></tr>`}
        </tbody></table></div></div>
    </div>
    <div class="panel"><div class="head"><h2>Consumption (30 days)</h2></div><div class="body"><canvas id="ch-cust" height="220"></canvas></div></div>
  </div>`;
  document.getElementById('c-edit').onclick = () => customerModal(() => customerDetailView(id), c);
  // aggregate consumption across the customer's meters
  const rep = (await SEMS.get(`/reports/consumption?user_id=${c.id}`)).data;
  chart('ch-cust', {
    type: 'bar',
    data: { labels: rep.by_day.map(d => fmt.day(d.day)), datasets: [{ label: 'kWh', data: rep.by_day.map(d => Number(d.total_kwh)), backgroundColor: '#0ea5b7', borderRadius: 5 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#edf1f7' } }, x: { grid: { display: false } } } },
  });
}

/* ================================================================ METERS */
let meterFilters = { q: '', status: '', page: 1 };
async function metersView() {
  const draw = async () => {
    const qs = new URLSearchParams({ page: meterFilters.page, limit: 12 });
    if (meterFilters.q) qs.set('q', meterFilters.q);
    if (meterFilters.status) qs.set('integration_status', meterFilters.status);
    const j = await SEMS.get('/meters?' + qs);
    document.getElementById('met-body').innerHTML = j.data.length ? `
      <table class="tbl"><thead><tr><th>Meter №</th><th>Type</th><th>Service location</th><th>Integration</th><th>Customers</th><th>Registered</th><th></th></tr></thead><tbody>
      ${j.data.map(m => `<tr>
        <td class="mono"><b>${fmt.esc(m.meter_number)}</b></td>
        <td>${label(m.meter_type)}</td><td>${fmt.esc(m.service_location)}</td>
        <td>${badge(m.integration_status)}</td>
        <td>${fmt.num(m.customer_links)}</td><td>${fmt.day(m.created_at)}</td>
        <td class="num"><a class="btn ghost sm" href="#/meters/${m.id}">Open</a></td></tr>`).join('')}
      </tbody></table>${pager(j.meta, (p) => { meterFilters.page = p; draw(); })}` : emptyBox('🔌', 'No meters found.');
    bindPager(document.getElementById('met-body'), (p) => { meterFilters.page = p; draw(); });
  };
  view().innerHTML = `
  <div class="panel"><div class="head"><h2>Meters</h2>
    <div class="filters">
      <input id="met-q" placeholder="Search number / location" value="${fmt.esc(meterFilters.q)}">
      <select id="met-status"><option value="">All integration states</option>
        <option value="connected">Connected</option><option value="simulated">Simulated</option>
        <option value="offline">Offline</option><option value="integration_unavailable">Integration unavailable</option></select>
      <button class="btn primary" id="met-add">+ Register meter</button>
    </div></div>
    <div class="body flush" id="met-body">${loading()}</div></div>
  <div id="modal-root"></div>`;
  document.getElementById('met-status').value = meterFilters.status;
  let t; document.getElementById('met-q').oninput = (e) => { clearTimeout(t); t = setTimeout(() => { meterFilters.q = e.target.value; meterFilters.page = 1; draw(); }, 300); };
  document.getElementById('met-status').onchange = (e) => { meterFilters.status = e.target.value; meterFilters.page = 1; draw(); };
  document.getElementById('met-add').onclick = () => meterModal(draw);
  await draw();
}

async function meterModal(onDone) {
  const customers = (await SEMS.get('/customers?limit=100')).data;
  const root = document.getElementById('modal-root');
  root.innerHTML = `<div style="position:fixed;inset:0;background:rgba(13,27,46,.55);display:grid;place-items:center;z-index:99;padding:16px">
    <div class="card" style="width:100%;max-width:500px;padding:22px">
      <h2 style="margin:0 0 4px">Register meter</h2>
      <div id="mm-err"></div>
      <div class="form-grid">
        <div><label>Meter number (LUKU-style)</label><input id="mm-num" placeholder="07210001234"></div>
        <div><label>Meter type</label><select id="mm-type"><option value="single_phase_prepaid">Single-phase prepaid</option><option value="three_phase_prepaid">Three-phase prepaid</option></select></div>
      </div>
      <label>Service location</label><input id="mm-loc" placeholder="Kaloleni, Arusha">
      <div class="form-grid">
        <div><label>Integration status</label><select id="mm-status"><option value="simulated" selected>Simulated</option><option value="connected">Connected</option><option value="offline">Offline</option><option value="integration_unavailable">Integration unavailable</option></select></div>
        <div><label>Assign to customer (optional)</label><select id="mm-user"><option value="">— not assigned —</option>${customers.map(c => `<option value="${c.id}">${fmt.esc(c.full_name)} (${fmt.esc(c.email)})</option>`).join('')}</select></div>
      </div>
      <div class="notice info">Meters registered here are managed inside SEMS only. No physical device is contacted.</div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
        <button class="btn ghost" id="mm-cancel">Cancel</button><button class="btn primary" id="mm-save">Register meter</button>
      </div></div></div>`;
  document.getElementById('mm-cancel').onclick = () => root.innerHTML = '';
  document.getElementById('mm-save').onclick = async () => {
    const body = {
      meter_number: document.getElementById('mm-num').value,
      meter_type: document.getElementById('mm-type').value,
      service_location: document.getElementById('mm-loc').value,
      integration_status: document.getElementById('mm-status').value,
    };
    const uid = document.getElementById('mm-user').value;
    if (uid) body.user_id = Number(uid);
    try { await SEMS.post('/meters', body); root.innerHTML = ''; onDone && onDone(); }
    catch (e) {
      const fields = e.fields ? Object.entries(e.fields).map(([k, v]) => `${k}: ${v}`).join(' · ') : '';
      document.getElementById('mm-err').innerHTML = `<div class="notice err">${fmt.esc(e.message)}${fields ? '<br>' + fmt.esc(fields) : ''}</div>`;
    }
  };
}

async function meterDetailView(id) {
  view().innerHTML = loading();
  const m = (await SEMS.get('/meters/' + id)).data;
  const readings = (await SEMS.get(`/meters/${id}/readings?limit=8`)).data;
  const txs = (await SEMS.get(`/meters/${id}/transactions?limit=6`)).data;
  view().innerHTML = `
  <p><a href="#/meters">← Meters</a></p>
  <div class="grid3">
    <div>
      <div class="panel"><div class="head"><h2>Meter <span class="mono">${fmt.esc(m.meter_number)}</span> ${badge(m.integration_status)}</h2></div>
        <div class="body"><dl class="kv">
          <dt>Type</dt><dd>${label(m.meter_type)}</dd>
          <dt>Service location</dt><dd>${fmt.esc(m.service_location)}</dd>
          <dt>Integration status</dt><dd>${badge(m.integration_status)} <span class="muted" style="font-weight:400">${m.integration_status === 'simulated' ? '(readings generated by the SEMS simulator)' : m.integration_status === 'integration_unavailable' ? '(no authorized utility interface)' : ''}</span></dd>
          <dt>Latest reading</dt><dd>${m.latest_reading ? `${fmt.kwh(m.latest_reading.cumulative_kwh)} <span class="muted" style="font-weight:400">@ ${fmt.dt(m.latest_reading.reading_time)} · source: ${badge(m.latest_reading.source)}</span>` : '—'}</dd>
          <dt>Reading freshness</dt><dd>${m.reading_freshness ? badge(m.reading_freshness === 'fresh' ? 'active' : m.reading_freshness) : 'no readings'}</dd>
          <dt>Estimated credit</dt><dd>${fmt.tzs(m.credit.estimated_credit_tzs)} <span class="muted" style="font-weight:400">(demo tariff ${fmt.num(m.credit.tariff_per_kwh_tzs)} TZS/kWh — assumption)</span></dd>
          <dt>Units consumed</dt><dd>${fmt.kwh(m.credit.total_units_consumed_kwh)}</dd>
          <dt>Purchases total</dt><dd>${fmt.tzs(m.credit.total_purchases_tzs)}</dd>
        </dl>
        <div class="notice info">💳 Credit is an <b>estimate</b>: purchases minus (consumed units × demo tariff). Token purchases recorded here are <b>simulated records</b> — they are never loaded onto a physical LUKU meter.</div>
        </div></div>
      <div class="panel"><div class="head"><h2>Assigned customers</h2></div><div class="body flush">
        <table class="tbl"><tbody>${(m.owners || []).map(o => `<tr><td>${fmt.esc(o.full_name)}</td><td class="muted">${fmt.esc(o.email)}</td><td>${badge(o.relationship)}</td></tr>`).join('') || `<tr><td>${emptyBox('👥', 'Not assigned to any customer.')}</td></tr>`}</tbody></table></div></div>
      <div class="panel"><div class="head"><h2>Recent token transactions</h2><a class="btn ghost sm" href="#/reports">Reports</a></div><div class="body flush">
        <table class="tbl"><thead><tr><th>Date</th><th class="num">Amount</th><th>Token ref</th><th>Status</th><th>Source</th></tr></thead><tbody>
        ${txs.map(x => `<tr><td>${fmt.dt(x.created_at)}</td><td class="num">${fmt.tzs(x.amount)}</td><td class="mono">${fmt.esc(x.token_reference || '—')}</td><td>${badge(x.status)}</td><td>${badge(x.source)}</td></tr>`).join('') || `<tr><td colspan="5">${emptyBox('💳', 'No transactions recorded.')}</td></tr>`}
        </tbody></table></div></div>
    </div>
    <div>
      <div class="panel"><div class="head"><h2>Consumption — 30 days</h2></div><div class="body"><canvas id="ch-meter" height="200"></canvas></div></div>
      <div class="panel"><div class="head"><h2>Latest readings</h2><button class="btn ghost sm" id="add-reading">+ Add reading</button></div><div class="body flush">
        <table class="tbl"><thead><tr><th>Time (EAT)</th><th class="num">Cumulative kWh</th><th>Source</th></tr></thead><tbody>
        ${readings.map(r => `<tr><td>${fmt.dt(r.reading_time)}</td><td class="num">${fmt.num(r.cumulative_kwh, 3)}</td><td>${badge(r.source)}</td></tr>`).join('') || `<tr><td colspan="3">${emptyBox('📟', 'No readings yet.')}</td></tr>`}
        </tbody></table></div></div>
    </div>
  </div>
  <div id="modal-root"></div>`;

  const cons = (await SEMS.get(`/meters/${id}/consumption`)).data;
  chart('ch-meter', {
    type: 'bar',
    data: { labels: cons.series.map(d => fmt.day(d.day)), datasets: [{ label: 'kWh', data: cons.series.map(d => Number(d.kwh)), backgroundColor: '#2563eb', borderRadius: 5 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#edf1f7' } }, x: { grid: { display: false } } } },
  });

  document.getElementById('add-reading').onclick = () => {
    const root = document.getElementById('modal-root');
    const last = m.latest_reading ? Number(m.latest_reading.cumulative_kwh) : 0;
    root.innerHTML = `<div style="position:fixed;inset:0;background:rgba(13,27,46,.55);display:grid;place-items:center;z-index:99;padding:16px">
      <div class="card" style="width:100%;max-width:440px;padding:22px">
        <h2 style="margin:0 0 4px">Add meter reading</h2>
        <div id="rd-err"></div>
        <label>Reading time (UTC)</label><input id="rd-time" type="datetime-local" value="${new Date().toISOString().slice(0, 16)}">
        <label>Cumulative kWh (last: ${fmt.num(last, 3)})</label><input id="rd-kwh" type="number" step="0.001" min="0" value="${(last + 3).toFixed(3)}">
        <label>Source</label><select id="rd-src"><option value="manual" selected>manual</option><option value="simulated">simulated</option><option value="imported">imported</option></select>
        <div class="notice warn">Readings that go backwards or duplicate an existing timestamp are rejected by the server.</div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px"><button class="btn ghost" id="rd-cancel">Cancel</button><button class="btn primary" id="rd-save">Save reading</button></div>
      </div></div>`;
    document.getElementById('rd-cancel').onclick = () => root.innerHTML = '';
    document.getElementById('rd-save').onclick = async () => {
      try {
        await SEMS.post(`/meters/${id}/readings`, {
          reading_time: document.getElementById('rd-time').value.replace('T', 'T') + ':00',
          cumulative_kwh: Number(document.getElementById('rd-kwh').value),
          source: document.getElementById('rd-src').value,
        });
        root.innerHTML = ''; meterDetailView(id);
      } catch (e) {
        document.getElementById('rd-err').innerHTML = `<div class="notice err">${fmt.esc(e.message)}</div>`;
      }
    };
  };
}
