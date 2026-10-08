/* SEMS Admin Dashboard — views part 2 + router */
'use strict';

/* ========================================================== CONSUMPTION */
let consState = { meter: '', user: '', from: iso_days_ago(30), to: iso_today(), compare: false };
function iso_today() { return new Date().toISOString().slice(0, 10); }
function iso_days_ago(n) { return new Date(Date.now() - n * 86400000).toISOString().slice(0, 10); }

async function consumptionView() {
  const meters = (await SEMS.get('/meters?limit=100')).data;
  const customers = (await SEMS.get('/customers?limit=100')).data;
  view().innerHTML = `
  <div class="panel"><div class="head"><h2>Consumption analytics</h2>
    <div class="filters">
      <select id="cs-meter"><option value="">All meters</option>${meters.map(m => `<option value="${m.id}">${fmt.esc(m.meter_number)} · ${fmt.esc(m.service_location)}</option>`).join('')}</select>
      <select id="cs-user"><option value="">All customers</option>${customers.map(c => `<option value="${c.id}">${fmt.esc(c.full_name)}</option>`).join('')}</select>
      <input id="cs-from" type="date" value="${consState.from}"> <input id="cs-to" type="date" value="${consState.to}">
      <label style="display:flex;gap:6px;align-items:center;margin:0;font-weight:500"><input type="checkbox" id="cs-compare" style="width:auto"> Compare vs previous period</label>
      <button class="btn primary" id="cs-apply">Apply</button>
      <button class="btn ghost" id="cs-export">⬇ Export CSV</button>
    </div></div>
    <div class="body" id="cs-body">${loading()}</div></div>`;
  document.getElementById('cs-meter').value = consState.meter;
  document.getElementById('cs-user').value = consState.user;
  document.getElementById('cs-compare').checked = consState.compare;
  document.getElementById('cs-apply').onclick = () => {
    consState = {
      meter: document.getElementById('cs-meter').value, user: document.getElementById('cs-user').value,
      from: document.getElementById('cs-from').value, to: document.getElementById('cs-to').value,
      compare: document.getElementById('cs-compare').checked,
    };
    drawConsumption();
  };
  document.getElementById('cs-export').onclick = exportConsumptionCsv;
  await drawConsumption();
}

let lastConsReport = null;
async function drawConsumption() {
  const body = document.getElementById('cs-body');
  if (!body) return;
  body.innerHTML = loading();
  const qs = new URLSearchParams({ from: consState.from, to: consState.to });
  if (consState.meter) qs.set('meter_id', consState.meter);
  if (consState.user) qs.set('user_id', consState.user);
  const rep = (await SEMS.get('/reports/consumption?' + qs)).data;
  lastConsReport = rep;

  // optional previous-period comparison
  let prevTotal = null;
  if (consState.compare) {
    const spanDays = Math.round((new Date(consState.to) - new Date(consState.from)) / 86400000) + 1;
    const pf = iso_days_ago(Math.round((Date.now() - new Date(consState.from)) / 86400000) + spanDays);
    const pt = iso_days_ago(Math.round((Date.now() - new Date(consState.from)) / 86400000));
    const qs2 = new URLSearchParams({ from: pf, to: pt });
    if (consState.meter) qs2.set('meter_id', consState.meter);
    if (consState.user) qs2.set('user_id', consState.user);
    try { prevTotal = (await SEMS.get('/reports/consumption?' + qs2)).data.grand_total_kwh; } catch { prevTotal = null; }
  }
  const delta = prevTotal !== null && prevTotal > 0 ? ((rep.grand_total_kwh - prevTotal) / prevTotal * 100) : null;

  body.innerHTML = `
  <div class="cards" style="margin-bottom:16px">
    <div class="card stat"><div class="icon i-teal">⚡</div><div><div class="val">${fmt.kwh(rep.grand_total_kwh)}</div><div class="lbl">Energy ${fmt.day(consState.from)} → ${fmt.day(consState.to)}</div></div></div>
    <div class="card stat"><div class="icon i-blue">🔌</div><div><div class="val">${fmt.num(rep.by_meter.length)}</div><div class="lbl">Meters with consumption</div></div></div>
    ${prevTotal !== null ? `<div class="card stat"><div class="icon ${delta >= 0 ? 'i-red' : 'i-green'}">${delta >= 0 ? '▲' : '▼'}</div><div><div class="val">${delta === null ? '—' : (delta >= 0 ? '+' : '') + fmt.num(delta, 1) + '%'}</div><div class="lbl">vs previous period (${fmt.kwh(prevTotal)})</div></div></div>` : ''}
  </div>
  <div class="notice info">Reading sources are labelled per record: <b>simulated</b> (demo generator), <b>manual</b>, <b>imported</b>, <b>hardware</b>. This dataset is demonstration data.</div>
  <canvas id="ch-cons" height="90"></canvas>
  <h3 style="margin:20px 0 8px">High-consumption meters</h3>
  <table class="tbl"><thead><tr><th>Meter №</th><th>Location</th><th>Source</th><th class="num">Total kWh</th></tr></thead><tbody>
    ${rep.by_meter.map(m => `<tr><td class="mono"><a href="#/meters/${m.meter_id}">${fmt.esc(m.meter_number)}</a></td><td>${fmt.esc(m.service_location)}</td><td>${badge(m.primary_source)}</td><td class="num">${fmt.num(m.total_kwh, 1)}</td></tr>`).join('') || `<tr><td colspan="4">${emptyBox('📈', 'No consumption in this period.')}</td></tr>`}
  </tbody></table>`;
  chart('ch-cons', {
    type: 'line',
    data: { labels: rep.by_day.map(d => fmt.day(d.day)), datasets: [{ label: 'kWh (all selected meters)', data: rep.by_day.map(d => Number(d.total_kwh)), borderColor: '#0ea5b7', backgroundColor: 'rgba(14,165,183,.12)', fill: true, tension: .3, pointRadius: 3 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#edf1f7' } }, x: { grid: { display: false } } } },
  });
}
function exportConsumptionCsv() {
  if (!lastConsReport) return;
  const rows = [['day', 'total_kwh', 'active_meters'], ...lastConsReport.by_day.map(d => [d.day, d.total_kwh, d.active_meters])];
  const csv = rows.map(r => r.join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = `sems-consumption-${consState.from}-to-${consState.to}.csv`;
  a.click();
}

/* ================================================================ ALERTS */
let alertFilters = { status: '', severity: '', page: 1 };
async function alertsView() {
  const draw = async () => {
    const qs = new URLSearchParams({ page: alertFilters.page, limit: 15 });
    if (alertFilters.status) qs.set('status', alertFilters.status);
    if (alertFilters.severity) qs.set('severity', alertFilters.severity);
    const j = await SEMS.get('/alerts?' + qs);
    document.getElementById('al-body').innerHTML = j.data.length ? `
      <table class="tbl"><thead><tr><th>Detected</th><th>Meter</th><th>Category</th><th>Severity</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>
      ${j.data.map(a => `<tr>
        <td style="white-space:nowrap">${fmt.dt(a.created_at)}</td>
        <td class="mono"><a href="#/meters/${a.meter_id}">${fmt.esc(a.meter_number)}</a></td>
        <td>${label(a.alert_type)}</td><td>${badge(a.severity)}</td>
        <td style="max-width:340px">${fmt.esc(a.description)}</td><td>${badge(a.status)}</td>
        <td class="num"><a class="btn ghost sm" href="#/alerts/${a.id}">Review</a></td></tr>`).join('')}
      </tbody></table>${pager(j.meta, (p) => { alertFilters.page = p; draw(); })}`
      : emptyBox('🚨', 'No alerts match these filters.');
    bindPager(document.getElementById('al-body'), (p) => { alertFilters.page = p; draw(); });
  };
  view().innerHTML = `
  <div class="notice info">🛡️ Alerts flag <b>potential anomalies for review</b>. They never automatically accuse a customer of wrongdoing — statuses move through new → under investigation → confirmed / dismissed / resolved.</div>
  <div class="panel"><div class="head"><h2>Anomaly alerts</h2>
    <div class="filters">
      <select id="al-status"><option value="">All statuses</option><option>new</option><option>under_investigation</option><option>confirmed</option><option>dismissed</option><option>resolved</option></select>
      <select id="al-sev"><option value="">All severities</option><option>info</option><option>warning</option><option>critical</option></select>
      <button class="btn ghost" id="al-scan">Run detection scan now</button>
    </div></div>
    <div class="body flush" id="al-body">${loading()}</div></div>`;
  document.getElementById('al-status').value = alertFilters.status;
  document.getElementById('al-sev').value = alertFilters.severity;
  document.getElementById('al-status').onchange = (e) => { alertFilters.status = e.target.value; alertFilters.page = 1; draw(); };
  document.getElementById('al-sev').onchange = (e) => { alertFilters.severity = e.target.value; alertFilters.page = 1; draw(); };
  document.getElementById('al-scan').onclick = async (e) => {
    e.target.disabled = true; e.target.textContent = 'Scan runs when readings arrive (see simulate.php)…';
    setTimeout(() => { e.target.disabled = false; e.target.textContent = 'Run detection scan now'; }, 2200);
  };
  await draw();
}

async function alertDetailView(id) {
  view().innerHTML = loading();
  const a = (await SEMS.get('/alerts/' + id)).data;
  const staff = (await SEMS.get('/customers?limit=100')).data;
  view().innerHTML = `
  <p><a href="#/alerts">← Alerts</a></p>
  <div class="grid3">
    <div class="panel"><div class="head"><h2>${label(a.alert_type)} ${badge(a.severity)}</h2>${badge(a.status)}</div>
      <div class="body">
        <p style="margin-top:0">${fmt.esc(a.description)}</p>
        <dl class="kv">
          <dt>Alert ID</dt><dd>#${a.id}</dd>
          <dt>Meter</dt><dd><a href="#/meters/${a.meter_id}" class="mono">${fmt.esc(a.meter_number)}</a> · ${fmt.esc(a.service_location)}</dd>
          <dt>Detected</dt><dd>${fmt.dt(a.created_at)}</dd>
          <dt>Data source</dt><dd>${fmt.esc(a.data_source)}</dd>
          <dt>Assigned to</dt><dd>${a.assigned_to ? fmt.esc(a.assigned_to_name || ('user #' + a.assigned_to)) : '—'}</dd>
          <dt>Resolved at</dt><dd>${a.resolved_at ? fmt.dt(a.resolved_at) : '—'}</dd>
        </dl>
        <div class="notice warn">Determine facts before drawing conclusions. An anomaly has many benign causes (vacancy, meter replacement, wiring changes, appliance additions).</div>
      </div></div>
    <div class="panel"><div class="head"><h2>Review</h2></div><div class="body">
      <label>Status</label>
      <select id="av-status"><option>new</option><option>under_investigation</option><option>confirmed</option><option>dismissed</option><option>resolved</option></select>
      <label>Assign to</label>
      <select id="av-assign"><option value="0">— unassigned —</option>${staff.map(s => `<option value="${s.id}">${fmt.esc(s.full_name)}</option>`).join('')}</select>
      <label>Administrator notes</label>
      <textarea id="av-notes" rows="5" placeholder="Investigation notes, site visit findings, resolution steps…">${fmt.esc(a.admin_notes || '')}</textarea>
      <div id="av-err"></div>
      <div style="margin-top:14px;display:flex;justify-content:flex-end"><button class="btn primary" id="av-save">Save review</button></div>
    </div></div>
  </div>`;
  document.getElementById('av-status').value = a.status;
  document.getElementById('av-assign').value = a.assigned_to || '0';
  document.getElementById('av-save').onclick = async () => {
    try {
      await SEMS.patch('/alerts/' + id, {
        status: document.getElementById('av-status').value,
        assigned_to: Number(document.getElementById('av-assign').value),
        admin_notes: document.getElementById('av-notes').value,
      });
      alertDetailView(id);
    } catch (e) { document.getElementById('av-err').innerHTML = `<div class="notice err">${fmt.esc(e.message)}</div>`; }
  };
}

/* ================================================================ FAULTS */
let faultFilters = { status: '', category: '', q: '', page: 1 };
async function faultsView() {
  const draw = async () => {
    const qs = new URLSearchParams({ page: faultFilters.page, limit: 15 });
    for (const [k, v] of Object.entries(faultFilters)) if (v && k !== 'page') qs.set(k, v);
    const j = await SEMS.get('/fault-reports?' + qs);
    document.getElementById('fr-body').innerHTML = j.data.length ? `
      <table class="tbl"><thead><tr><th>#</th><th>Submitted</th><th>Reporter</th><th>Category</th><th>Meter</th><th>Status</th><th>💬</th><th></th></tr></thead><tbody>
      ${j.data.map(f => `<tr>
        <td>#${f.id}</td><td style="white-space:nowrap">${fmt.dt(f.created_at)}</td>
        <td>${fmt.esc(f.reporter_name)}</td><td>${label(f.category)}</td>
        <td class="mono">${fmt.esc(f.meter_number || '—')}</td>
        <td>${badge(f.status)}</td><td>${fmt.num(f.message_count)}</td>
        <td class="num"><a class="btn ghost sm" href="#/faults/${f.id}">Open</a></td></tr>`).join('')}
      </tbody></table>${pager(j.meta, (p) => { faultFilters.page = p; draw(); })}` : emptyBox('🛠️', 'No fault reports match.');
    bindPager(document.getElementById('fr-body'), (p) => { faultFilters.page = p; draw(); });
  };
  view().innerHTML = `
  <div class="panel"><div class="head"><h2>Fault & complaint reports</h2>
    <div class="filters">
      <input id="fr-q" placeholder="Search description" value="${fmt.esc(faultFilters.q)}">
      <select id="fr-status"><option value="">All statuses</option><option>submitted</option><option>under_review</option><option>assigned</option><option>in_progress</option><option>resolved</option><option>closed</option></select>
      <select id="fr-cat"><option value="">All categories</option><option value="power_outage">Power outage</option><option value="meter_problem">Meter problem</option><option value="suspected_incorrect_reading">Suspected incorrect reading</option><option value="supply_issue">Supply issue</option><option value="other">Other</option></select>
    </div></div>
    <div class="body flush" id="fr-body">${loading()}</div></div>`;
  document.getElementById('fr-status').value = faultFilters.status;
  document.getElementById('fr-cat').value = faultFilters.category;
  let t; document.getElementById('fr-q').oninput = (e) => { clearTimeout(t); t = setTimeout(() => { faultFilters.q = e.target.value; faultFilters.page = 1; draw(); }, 300); };
  document.getElementById('fr-status').onchange = (e) => { faultFilters.status = e.target.value; faultFilters.page = 1; draw(); };
  document.getElementById('fr-cat').onchange = (e) => { faultFilters.category = e.target.value; faultFilters.page = 1; draw(); };
  await draw();
}

async function faultDetailView(id) {
  const sendMsg = async () => {
    const inp = document.getElementById('fm-input');
    const txt = inp.value.trim();
    if (!txt) return;
    inp.value = '';
    try { await SEMS.post(`/fault-reports/${id}/messages`, { message: txt }); faultDetailView(id); }
    catch (e) { alert(e.message); }
  };
  view().innerHTML = loading();
  const f = (await SEMS.get('/fault-reports/' + id)).data;
  const staff = (await SEMS.get('/customers?limit=100')).data;
  view().innerHTML = `
  <p><a href="#/faults">← Fault reports</a></p>
  <div class="grid3">
    <div>
      <div class="panel"><div class="head"><h2>Report #${f.id} ${badge(f.status)}</h2></div><div class="body">
        <dl class="kv">
          <dt>Reporter</dt><dd>${fmt.esc(f.reporter_name)}</dd>
          <dt>Category</dt><dd>${label(f.category)}</dd>
          <dt>Meter</dt><dd>${f.meter_id ? `<a href="#/meters/${f.meter_id}" class="mono">${fmt.esc(f.meter_number || '')}</a>` : '—'}</dd>
          <dt>Submitted</dt><dd>${fmt.dt(f.created_at)}</dd>
          <dt>Assigned to</dt><dd>${fmt.esc(f.assigned_to_name || '—')}</dd>
        </dl>
        <label style="margin-top:14px">Description</label>
        <div style="background:#f8fafd;border:1px solid var(--line);border-radius:10px;padding:12px">${fmt.esc(f.description)}</div>
      </div></div>
      <div class="panel"><div class="head"><h2>Conversation with consumer</h2></div><div class="body">
        <div class="chat" id="fm-chat">
          ${(f.messages || []).map(msg => `<div class="bubble ${msg.sender_role === 'admin' ? 'right' : 'left'}"><div class="who">${fmt.esc(msg.sender_name)} · ${fmt.dt(msg.created_at)}</div>${fmt.esc(msg.message)}</div>`).join('') || '<div class="muted">No messages yet.</div>'}
        </div>
        <div style="display:flex;gap:8px;margin-top:12px">
          <input id="fm-input" placeholder="Write a response to the consumer…" style="flex:1">
          <button class="btn primary" id="fm-send">Send</button>
        </div>
      </div></div>
    </div>
    <div class="panel"><div class="head"><h2>Manage</h2></div><div class="body">
      <label>Status</label>
      <select id="fm-status"><option>submitted</option><option>under_review</option><option>assigned</option><option>in_progress</option><option>resolved</option><option>closed</option></select>
      <label>Assign to</label>
      <select id="fm-assign"><option value="0">— unassigned —</option>${staff.map(s => `<option value="${s.id}">${fmt.esc(s.full_name)}</option>`).join('')}</select>
      <div id="fm-err"></div>
      <div style="margin-top:14px"><button class="btn primary" id="fm-save" style="width:100%;justify-content:center">Update report</button></div>
      <div class="notice info" style="margin-top:16px">Status changes notify the consumer automatically inside their SEMS mobile app.</div>
    </div></div>
  </div>`;
  document.getElementById('fm-status').value = f.status;
  document.getElementById('fm-assign').value = f.assigned_to || '0';
  document.getElementById('fm-send').onclick = sendMsg;
  document.getElementById('fm-input').onkeydown = (e) => { if (e.key === 'Enter') sendMsg(); };
  document.getElementById('fm-save').onclick = async () => {
    try {
      await SEMS.patch('/fault-reports/' + id, { status: document.getElementById('fm-status').value, assigned_to: Number(document.getElementById('fm-assign').value) });
      faultDetailView(id);
    } catch (e) { document.getElementById('fm-err').innerHTML = `<div class="notice err">${fmt.esc(e.message)}</div>`; }
  };
  const chat = document.getElementById('fm-chat'); chat.scrollTop = chat.scrollHeight;
}

/* =============================================================== REPORTS */
async function reportsView() {
  view().innerHTML = `
  <div class="panel"><div class="head"><h2>Reports</h2>
    <div class="filters">
      <input id="rp-from" type="date" value="${iso_days_ago(30)}"> <input id="rp-to" type="date" value="${iso_today()}">
      <button class="btn primary" id="rp-apply">Generate</button>
    </div></div>
    <div class="body" id="rp-body">${loading()}</div></div>`;
  document.getElementById('rp-apply').onclick = drawReports;
  await drawReports();
}
async function drawReports() {
  const from = document.getElementById('rp-from').value, to = document.getElementById('rp-to').value;
  const body = document.getElementById('rp-body');
  body.innerHTML = loading();
  const [cons, txs, faults] = (await Promise.all([
    SEMS.get(`/reports/consumption?from=${from}&to=${to}`),
    SEMS.get(`/reports/transactions?from=${from}&to=${to}`),
    SEMS.get(`/reports/faults?from=${from}&to=${to}`),
  ])).map(j => j.data);

  const statusMap = Object.fromEntries(faults.by_status.map(r => [r.status, r.cnt]));
  body.innerHTML = `
  <div class="cards" style="margin-bottom:16px">
    <div class="card stat"><div class="icon i-teal">⚡</div><div><div class="val">${fmt.kwh(cons.grand_total_kwh)}</div><div class="lbl">Consumption</div></div></div>
    <div class="card stat"><div class="icon i-purple">💰</div><div><div class="val">${fmt.tzs(txs.total_amount_tzs)}</div><div class="lbl">Purchases recorded (${fmt.num(txs.count)})</div></div></div>
    <div class="card stat"><div class="icon i-amber">🛠️</div><div><div class="val">${fmt.num(faults.recent.length)}</div><div class="lbl">Fault reports</div></div></div>
  </div>
  <div class="grid2">
    <div><h3 style="margin-top:0">Fault reports by status</h3>
      <table class="tbl"><thead><tr><th>Status</th><th class="num">Count</th></tr></thead><tbody>
      ${Object.entries(statusMap).map(([s, c]) => `<tr><td>${badge(s)}</td><td class="num">${c}</td></tr>`).join('') || '<tr><td colspan="2">None</td></tr>'}
      </tbody></table>
      <div style="margin-top:8px"><button class="btn ghost sm" id="rp-csv-cons">⬇ Consumption CSV</button>
      <button class="btn ghost sm" id="rp-csv-tx">⬇ Transactions CSV</button></div></div>
    <div><h3 style="margin-top:0">Consumption trend</h3><canvas id="ch-rep" height="180"></canvas></div>
  </div>
  <h3>Token transaction records (latest ${Math.min(txs.count, 500)})</h3>
  <table class="tbl"><thead><tr><th>Date</th><th>Meter</th><th class="num">Amount</th><th>Token ref</th><th>Status</th><th>Source</th></tr></thead><tbody>
    ${txs.transactions.slice(0, 12).map(x => `<tr><td>${fmt.dt(x.created_at)}</td><td class="mono">${fmt.esc(x.meter_number)}</td><td class="num">${fmt.tzs(x.amount)}</td><td class="mono">${fmt.esc(x.token_reference || '—')}</td><td>${badge(x.status)}</td><td>${badge(x.source)}</td></tr>`).join('')}
  </tbody></table>
  <h3>Recent fault reports</h3>
  <table class="tbl"><thead><tr><th>#</th><th>Submitted</th><th>Reporter</th><th>Category</th><th>Meter</th><th>Status</th></tr></thead><tbody>
    ${faults.recent.slice(0, 10).map(f => `<tr><td><a href="#/faults/${f.id}">#${f.id}</a></td><td>${fmt.dt(f.created_at)}</td><td>${fmt.esc(f.reporter_name)}</td><td>${label(f.category)}</td><td class="mono">${fmt.esc(f.meter_number || '—')}</td><td>${badge(f.status)}</td></tr>`).join('') || '<tr><td colspan="6">None</td></tr>'}
  </tbody></table>`;

  chart('ch-rep', {
    type: 'bar',
    data: { labels: cons.by_day.map(d => fmt.day(d.day)), datasets: [{ label: 'kWh', data: cons.by_day.map(d => Number(d.total_kwh)), backgroundColor: '#0ea5b7', borderRadius: 4 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#edf1f7' } }, x: { grid: { display: false } } } },
  });
  const csvDownload = (name, rows) => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([rows.map(r => r.join(',')).join('\n')], { type: 'text/csv' }));
    a.download = name; a.click();
  };
  document.getElementById('rp-csv-cons').onclick = () => csvDownload(`sems-consumption-${from}-${to}.csv`,
    [['day', 'total_kwh', 'active_meters'], ...cons.by_day.map(d => [d.day, d.total_kwh, d.active_meters])]);
  document.getElementById('rp-csv-tx').onclick = () => csvDownload(`sems-transactions-${from}-${to}.csv`,
    [['id', 'meter', 'amount_tzs', 'token_ref', 'status', 'source', 'created_at'], ...txs.transactions.map(x => [x.id, x.meter_number, x.amount, x.token_reference || '', x.status, x.source, x.created_at])]);
}

/* ================================================================= AUDIT */
async function auditView() {
  let page = 1;
  const draw = async () => {
    const j = await SEMS.get(`/admin/audit-logs?page=${page}&limit=25`);
    document.getElementById('au-body').innerHTML = j.data.length ? `
      <table class="tbl"><thead><tr><th>Time (EAT)</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead><tbody>
      ${j.data.map(l => `<tr><td style="white-space:nowrap">${fmt.dt(l.created_at)}</td><td>${fmt.esc(l.actor_name || 'system')}</td><td><span class="badge b-teal">${fmt.esc(l.action)}</span></td><td>${fmt.esc(l.entity_type)}${l.entity_id ? ' #' + l.entity_id : ''}</td><td class="muted">${fmt.esc(l.details || '')}</td></tr>`).join('')}
      </tbody></table>${pager(j.meta, (p) => { page = p; draw(); })}` : emptyBox('🧾', 'No audit entries.');
    bindPager(document.getElementById('au-body'), (p) => { page = p; draw(); });
  };
  view().innerHTML = `<div class="panel"><div class="head"><h2>Audit logs</h2><span class="muted">Append-only record of administrative & security actions</span></div><div class="body flush" id="au-body">${loading()}</div></div>`;
  await draw();
}

/* ============================================================== SETTINGS */
async function settingsView() {
  view().innerHTML = loading();
  const settings = Object.fromEntries((await SEMS.get('/admin/settings')).data.map(s => [s.key, s.value]));
  const gw = (await SEMS.get('/system/gateway')).data;
  view().innerHTML = `
  <div class="grid2">
    <div class="panel"><div class="head"><h2>Tariff & detection settings</h2></div><div class="body">
      <div id="st-msg"></div>
      <label>Demo tariff (TZS per kWh)</label><input id="st-tariff" type="number" step="1" min="0" value="${fmt.esc(settings.tariff_per_kwh_tzs || '400')}">
      <div class="notice warn">⚠️ This tariff is a <b>demonstration assumption</b>. Verify the applicable published tariff before using any cost figures outside this prototype.</div>
      <label>Low-credit threshold (TZS)</label><input id="st-low" type="number" step="100" min="0" value="${fmt.esc(settings.low_credit_threshold_tzs || '2000')}">
      <label>Spike multiplier (× baseline)</label><input id="st-spike" type="number" step="0.1" min="1" value="${fmt.esc(settings.anomaly_spike_multiplier || '2.0')}">
      <label>Spike minimum (kWh/day)</label><input id="st-min" type="number" step="0.5" min="0" value="${fmt.esc(settings.anomaly_spike_min_kwh || '2.0')}">
      <label>Zero-consumption days</label><input id="st-zero" type="number" step="1" min="1" value="${fmt.esc(settings.anomaly_zero_days || '3')}">
      <label>Missing-reading threshold (hours)</label><input id="st-miss" type="number" step="1" min="1" value="${fmt.esc(settings.anomaly_missing_hours || '36')}">
      <div style="margin-top:16px"><button class="btn primary" id="st-save">Save settings</button></div>
    </div></div>
    <div>
      <div class="panel"><div class="head"><h2>Meter integration gateway</h2></div><div class="body">
        <dl class="kv">
          <dt>Active gateway</dt><dd><span class="badge b-blue">${fmt.esc(gw.gateway)}</span></dd>
          <dt>Live utility connection</dt><dd>${gw.live ? badge('connected') : badge('integration_unavailable')}</dd>
        </dl>
        <div class="notice info">${fmt.esc(gw.notice)}</div>
        <p class="muted" style="font-size:12.5px">A future authorized TANESCO/LUKU interface (or compatible hardware gateway) plugs into the same <span class="mono">MeterGatewayInterface</span> without redesigning the system.</p>
      </div></div>
      <div class="panel"><div class="head"><h2>Demo data tools</h2></div><div class="body">
        <p class="muted" style="margin-top:0">Simulation and demo-reset are CLI operations on the server:</p>
        <pre class="mono" style="background:#0d1b2e;color:#cfe3ff;padding:12px;border-radius:10px;overflow:auto;font-size:12px">php backend/scripts/simulate.php --days=1
php backend/scripts/simulate.php --scenario=spike
php backend/scripts/reset_demo.php</pre>
      </div></div>
    </div>
  </div>`;
  document.getElementById('st-save').onclick = async () => {
    const msg = document.getElementById('st-msg');
    try {
      await SEMS.patch('/admin/settings', {
        tariff_per_kwh_tzs: Number(document.getElementById('st-tariff').value),
        low_credit_threshold_tzs: Number(document.getElementById('st-low').value),
        anomaly_spike_multiplier: Number(document.getElementById('st-spike').value),
        anomaly_spike_min_kwh: Number(document.getElementById('st-min').value),
        anomaly_zero_days: Number(document.getElementById('st-zero').value),
        anomaly_missing_hours: Number(document.getElementById('st-miss').value),
      });
      msg.innerHTML = '<div class="notice info">✅ Settings saved (change recorded in the audit log).</div>';
    } catch (e) { msg.innerHTML = `<div class="notice err">${fmt.esc(e.message)}</div>`; }
  };
}

/* ================================================================ ROUTER */
const ROUTES = {
  dashboard: ['Dashboard', dashboardView],
  customers: ['Customers', customersView],
  meters: ['Meters', metersView],
  consumption: ['Consumption analytics', consumptionView],
  alerts: ['Anomaly alerts', alertsView],
  faults: ['Fault reports', faultsView],
  reports: ['Reports', reportsView],
  audit: ['Audit logs', auditView],
  settings: ['Settings', settingsView],
};

async function route() {
  const h = (location.hash || '#/dashboard').replace(/^#\//, '');
  const [name, arg] = h.split('/');
  if (!SEMS.token) { loginView(); return; }
  if (name === 'login') { location.hash = '#/dashboard'; return; }
  if (!ROUTES[name]) { location.hash = '#/dashboard'; return; }

  if (!document.getElementById('nav')) renderShell(name);
  setActiveNav(name);
  document.getElementById('page-title').textContent = ROUTES[name][0];
  document.getElementById('sidebar')?.classList.remove('open');
  try {
    if (name === 'customers' && arg) await customerDetailView(arg);
    else if (name === 'meters' && arg) await meterDetailView(arg);
    else if (name === 'alerts' && arg) await alertDetailView(arg);
    else if (name === 'faults' && arg) await faultDetailView(arg);
    else await ROUTES[name][1]();
  } catch (e) {
    if (e.status === 401) return; // redirected to login already
    showError(e);
  }
}
window.addEventListener('hashchange', route);
window.addEventListener('DOMContentLoaded', () => {
  if (!location.hash) location.hash = SEMS.token ? '#/dashboard' : '#/login';
  route();
});
