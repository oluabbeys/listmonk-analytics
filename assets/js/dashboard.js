const API = 'api/data.php';
const REFRESH = 30; // seconds
let charts = {};
let refreshTimer;
let countdown;
let liveChart;
let liveData = { labels: [], opens: [], clicks: [] };

// ── NAVIGATION ────────────────────────────────────────────────────────
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', e => {
    e.preventDefault();
    const page = item.dataset.page;
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    item.classList.add('active');
    document.getElementById('page-' + page).classList.add('active');
    document.getElementById('pageTitle').textContent = item.textContent.trim();
    loadPage(page);
  });
});

document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('open');
});

// ── PAGE LOADER ───────────────────────────────────────────────────────
function loadPage(page) {
  switch (page) {
    case 'overview':  loadOverview(); break;
    case 'campaigns': loadCampaigns(); break;
    case 'live':      loadLive(); break;
    case 'trends':    loadTrends(); break;
    case 'lists':     loadLists(); break;
    case 'domains':   loadDomains(); break;
    case 'heatmap':   loadHeatmap(); break;
  }
}

// ── FETCH HELPER ──────────────────────────────────────────────────────
async function api(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const res = await fetch(`${API}?${qs}`);
  return res.json();
}

// ── OVERVIEW ──────────────────────────────────────────────────────────
async function loadOverview() {
  const [overview, trends, domains, campaigns] = await Promise.all([
    api('overview'), api('trends'), api('domains'), api('campaigns')
  ]);

  // Stat cards
  document.getElementById('stat-subscribers').textContent =
    (overview.subscribers?.enabled || 0).toLocaleString();
  document.getElementById('stat-campaigns').textContent =
    Object.values(overview.campaigns || {}).reduce((a, b) => a + b, 0).toLocaleString();
  document.getElementById('stat-opens-today').textContent =
    (overview.opens_today || 0).toLocaleString();
  document.getElementById('stat-blocklisted').textContent =
    (overview.total_blocklisted || 0).toLocaleString();

  // Trends chart
  destroyChart('trendsChart');
  const ctx = document.getElementById('trendsChart').getContext('2d');
  charts.trends = new Chart(ctx, {
    type: 'line',
    data: {
      labels: trends.opens.map(r => formatDate(r.day)),
      datasets: [
        {
          label: 'Opens',
          data: trends.opens.map(r => r.opens),
          borderColor: '#ff9900',
          backgroundColor: 'rgba(255,153,0,0.1)',
          tension: 0.4, fill: true
        },
        {
          label: 'Clicks',
          data: trends.clicks.map(r => r.clicks),
          borderColor: '#1a1a2e',
          backgroundColor: 'rgba(26,26,46,0.1)',
          tension: 0.4, fill: true
        }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: { y: { beginAtZero: true } }
    }
  });

  // Subscriber doughnut
  destroyChart('subscriberChart');
  const sub = overview.subscribers || {};
  const ctx2 = document.getElementById('subscriberChart').getContext('2d');
  charts.subscriber = new Chart(ctx2, {
    type: 'doughnut',
    data: {
      labels: ['Enabled', 'Blocklisted', 'Disabled'],
      datasets: [{
        data: [sub.enabled || 0, sub.blocklisted || 0, sub.disabled || 0],
        backgroundColor: ['#ff9900', '#ef5350', '#90a4ae'],
        borderWidth: 2
      }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
  });

  // Recent campaigns table
  const recent = (campaigns || []).slice(0, 5);
  document.getElementById('recentCampaigns').innerHTML = `
    <table class="table table-sm">
      <thead><tr><th>Campaign</th><th>Open Rate</th><th>Clicks</th></tr></thead>
      <tbody>
        ${recent.map(c => `
          <tr>
            <td><span class="text-truncate d-inline-block" style="max-width:180px" title="${c.name}">${c.name}</span></td>
            <td>
              <div class="mini-progress"><div class="mini-progress-bar" style="width:${c.open_rate}%"></div></div>
              <small>${c.open_rate}%</small>
            </td>
            <td>${c.unique_clicks}</td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;

  // Domains chart
  destroyChart('domainsChart');
  const topDomains = (domains || []).slice(0, 8);
  const ctx3 = document.getElementById('domainsChart').getContext('2d');
  charts.domains = new Chart(ctx3, {
    type: 'bar',
    data: {
      labels: topDomains.map(d => d.domain),
      datasets: [{
        label: 'Subscribers',
        data: topDomains.map(d => d.active),
        backgroundColor: '#ff9900'
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
}

// ── CAMPAIGNS ─────────────────────────────────────────────────────────
async function loadCampaigns() {
  const campaigns = await api('campaigns');
  const tbody = document.getElementById('campaignsList');
  tbody.innerHTML = campaigns.map(c => `
    <tr>
      <td>
        <a href="report.php?id=${c.id}" target="_blank" rel="noopener" class="fw-semibold d-block text-decoration-none" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${c.subject}">${c.name}</a>
        <a href="report.php?id=${c.id}" target="_blank" rel="noopener" class="text-muted text-decoration-none d-block" style="font-size:0.8em;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${c.subject || ''}</a>
      </td>
      <td><span class="badge-status badge-${c.status}">${c.status}</span></td>
      <td>${(c.total_recipients || 0).toLocaleString()}</td>
      <td>${c.unique_opens || 0}</td>
      <td>
        <div class="mini-progress"><div class="mini-progress-bar" style="width:${c.open_rate}%"></div></div>
        <small>${c.open_rate}%</small>
      </td>
      <td>${c.unique_clicks || 0}</td>
      <td><small>${c.click_rate}%</small></td>
      <td><small>${c.started_at ? formatDate(c.started_at) : '—'}</small></td>
      <td>
        <button class="btn btn-xs btn-outline-primary" onclick="showCampaignDetail(${c.id}, '${escHtml(c.name)}')">
          <i class="fas fa-chart-bar"></i>
        </button>
        <a href="${API}?action=export&campaign_id=${c.id}" class="btn btn-xs btn-outline-success">
          <i class="fas fa-download"></i>
        </a>
      </td>
    </tr>
  `).join('');

  // Search
  document.getElementById('campaignSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    tbody.querySelectorAll('tr').forEach(tr => {
      tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

async function showCampaignDetail(id, name) {
  document.getElementById('campaignModalTitle').textContent = name;
  document.getElementById('campaignModalBody').innerHTML =
    '<div class="text-center py-4"><div class="spinner-border text-warning"></div><p class="mt-2">Loading...</p></div>';

  const modal = new bootstrap.Modal(document.getElementById('campaignModal'));
  modal.show();

  const data = await api('campaign_detail', { id });
  const s = data.summary;
  const openRate = s.total > 0 ? Math.round(s.opened / s.total * 100) : 0;
  const clickRate = s.total > 0 ? Math.round(s.clicked / s.total * 100) : 0;

  document.getElementById('campaignModalBody').innerHTML = `
    <div class="row g-3 mb-4">
      <div class="col-3 text-center">
        <div class="fw-bold fs-3 text-warning">${s.total}</div><small class="text-muted">Total</small>
      </div>
      <div class="col-3 text-center">
        <div class="fw-bold fs-3 text-success">${s.opened}</div>
        <small class="text-muted">Opened (${openRate}%)</small>
        <div class="mini-progress mt-1"><div class="mini-progress-bar" style="width:${openRate}%;background:#2e7d32"></div></div>
      </div>
      <div class="col-3 text-center">
        <div class="fw-bold fs-3 text-primary">${s.clicked}</div>
        <small class="text-muted">Clicked (${clickRate}%)</small>
        <div class="mini-progress mt-1"><div class="mini-progress-bar" style="width:${clickRate}%"></div></div>
      </div>
      <div class="col-3 text-center">
        <div class="fw-bold fs-3 text-danger">${s.not_opened}</div>
        <small class="text-muted">Not Opened</small>
      </div>
    </div>

    ${data.opens_timeline.length > 0 ? `
    <canvas id="modalTimeline" height="80" class="mb-4"></canvas>
    ` : ''}

    ${data.top_links.length > 0 ? `
    <h6 class="mb-2">Top Clicked Links</h6>
    <table class="table table-sm mb-4">
      <thead><tr><th>URL</th><th>Clicks</th><th>Unique</th></tr></thead>
      <tbody>
        ${data.top_links.map(l => `
          <tr>
            <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <a href="${l.url}" target="_blank" class="text-truncate">${l.url}</a>
            </td>
            <td>${l.clicks}</td>
            <td>${l.unique_clicks}</td>
          </tr>
        `).join('')}
      </tbody>
    </table>
    ` : ''}

    <h6 class="mb-2">Individual Subscriber Tracking</h6>
    <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
      <table class="table table-sm table-hover">
        <thead style="position:sticky;top:0;background:#fff;">
          <tr>
            <th>Email</th><th>Name</th><th>Opens</th><th>Clicks</th><th>First Opened</th>
          </tr>
        </thead>
        <tbody>
          ${data.subscribers.map(sub => `
            <tr class="${sub.first_open ? '' : 'text-muted'}">
              <td><small>${sub.email}</small></td>
              <td><small>${sub.name || '—'}</small></td>
              <td>${sub.open_count > 0 ? `<span class="badge bg-success">${sub.open_count}</span>` : '—'}</td>
              <td>${sub.click_count > 0 ? `<span class="badge bg-primary">${sub.click_count}</span>` : '—'}</td>
              <td><small>${sub.first_open ? formatDateTime(sub.first_open) : '<span class="text-danger">Not opened</span>'}</small></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;

  // Timeline chart inside modal
  if (data.opens_timeline.length > 0) {
    setTimeout(() => {
      const ctx = document.getElementById('modalTimeline')?.getContext('2d');
      if (ctx) {
        new Chart(ctx, {
          type: 'bar',
          data: {
            labels: data.opens_timeline.map(r => formatDateTime(r.hour)),
            datasets: [{
              label: 'Opens',
              data: data.opens_timeline.map(r => r.opens),
              backgroundColor: '#ff9900'
            }]
          },
          options: { responsive: true, plugins: { legend: { display: false } } }
        });
      }
    }, 100);
  }
}

// ── LIVE FEED ─────────────────────────────────────────────────────────
let liveInterval;
async function loadLive() {
  clearInterval(liveInterval);
  await refreshLive();
  liveInterval = setInterval(refreshLive, 10000);

  // Init live mini chart
  destroyChart('liveChart');
  const ctx = document.getElementById('liveChart').getContext('2d');
  liveChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: liveData.labels,
      datasets: [
        { label: 'Opens', data: liveData.opens, borderColor: '#ff9900', tension: 0.4 },
        { label: 'Clicks', data: liveData.clicks, borderColor: '#1a1a2e', tension: 0.4 }
      ]
    },
    options: {
      responsive: true,
      animation: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true } }
    }
  });
}

async function refreshLive() {
  const events = await api('live_feed');
  document.getElementById('liveCount').textContent = events.length + ' events';

  const opens = events.filter(e => e.type === 'open').length;
  const clicks = events.filter(e => e.type === 'click').length;

  document.getElementById('liveStats').innerHTML = `
    <div class="d-flex justify-content-around mt-2">
      <div class="text-center">
        <div class="fs-3 fw-bold text-warning">${opens}</div>
        <small class="text-muted">Opens</small>
      </div>
      <div class="text-center">
        <div class="fs-3 fw-bold text-primary">${clicks}</div>
        <small class="text-muted">Clicks</small>
      </div>
    </div>
    <hr>
    <small class="text-muted">Last updated: ${new Date().toLocaleTimeString()}</small>
  `;

  // Update live chart data
  const now = new Date().toLocaleTimeString();
  liveData.labels.push(now);
  liveData.opens.push(opens);
  liveData.clicks.push(clicks);
  if (liveData.labels.length > 20) {
    liveData.labels.shift();
    liveData.opens.shift();
    liveData.clicks.shift();
  }
  if (liveChart) liveChart.update();

  // Feed items
  document.getElementById('liveFeed').innerHTML = events.length === 0
    ? '<div class="text-center text-muted py-5"><i class="fas fa-satellite-dish fa-2x mb-3"></i><br>No activity in the last 60 minutes</div>'
    : events.map(e => `
      <div class="live-event">
        <div class="live-event-icon ${e.type === 'open' ? 'live-open' : 'live-click'}">
          <i class="fas fa-${e.type === 'open' ? 'eye' : 'mouse-pointer'}"></i>
        </div>
        <div class="live-event-body">
          <div class="live-event-email">${e.email}</div>
          <div class="live-event-campaign">${e.type === 'open' ? 'Opened' : 'Clicked'}: ${e.campaign}</div>
        </div>
        <div class="live-event-time">${timeSince(e.time)}</div>
      </div>
    `).join('');
}

// ── TRENDS ────────────────────────────────────────────────────────────
async function loadTrends() {
  const [trends, bestTimes] = await Promise.all([api('trends'), api('best_times')]);

  destroyChart('trendsDetailChart');
  const ctx = document.getElementById('trendsDetailChart').getContext('2d');
  charts.trendsDetail = new Chart(ctx, {
    type: 'line',
    data: {
      labels: trends.opens.map(r => formatDate(r.day)),
      datasets: [
        {
          label: 'Opens',
          data: trends.opens.map(r => r.opens),
          borderColor: '#ff9900',
          backgroundColor: 'rgba(255,153,0,0.15)',
          tension: 0.4, fill: true
        },
        {
          label: 'Clicks',
          data: trends.clicks.map(r => r.clicks),
          borderColor: '#1a1a2e',
          backgroundColor: 'rgba(26,26,46,0.1)',
          tension: 0.4, fill: true
        }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: { y: { beginAtZero: true } }
    }
  });

  destroyChart('growthChart');
  const ctx2 = document.getElementById('growthChart').getContext('2d');
  charts.growth = new Chart(ctx2, {
    type: 'bar',
    data: {
      labels: trends.growth.map(r => formatDate(r.day)),
      datasets: [{
        label: 'New Subscribers',
        data: trends.growth.map(r => r.new_subscribers),
        backgroundColor: '#ff9900'
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });

  // Best day chart
  const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const dayTotals = Array(7).fill(0);
  bestTimes.forEach(t => { dayTotals[parseInt(t.day_of_week)] += parseInt(t.opens); });

  destroyChart('bestDayChart');
  const ctx3 = document.getElementById('bestDayChart').getContext('2d');
  charts.bestDay = new Chart(ctx3, {
    type: 'bar',
    data: {
      labels: days,
      datasets: [{
        label: 'Opens',
        data: dayTotals,
        backgroundColor: dayTotals.map(v => v === Math.max(...dayTotals) ? '#ff9900' : '#e0e0e0')
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
}

// ── LISTS ─────────────────────────────────────────────────────────────
async function loadLists() {
  const lists = await api('lists');
  document.getElementById('listsContent').innerHTML = `
    <table class="table table-hover">
      <thead>
        <tr>
          <th>List Name</th><th>Type</th><th>Active</th><th>Blocklisted</th>
          <th>Total</th><th>Health</th><th>Created</th>
        </tr>
      </thead>
      <tbody>
        ${lists.map(l => {
          const health = l.total > 0 ? Math.round((l.active / l.total) * 100) : 0;
          return `
            <tr>
              <td><strong>${l.name}</strong></td>
              <td><span class="badge bg-secondary">${l.type}</span></td>
              <td class="text-success">${parseInt(l.active || 0).toLocaleString()}</td>
              <td class="text-danger">${parseInt(l.blocklisted || 0).toLocaleString()}</td>
              <td>${parseInt(l.total || 0).toLocaleString()}</td>
              <td>
                <div class="mini-progress" style="width:80px;">
                  <div class="mini-progress-bar" style="width:${health}%;background:${health > 80 ? '#2e7d32' : health > 60 ? '#ff9900' : '#ef5350'}"></div>
                </div>
                <small>${health}%</small>
              </td>
              <td><small>${formatDate(l.created_at)}</small></td>
            </tr>
          `;
        }).join('')}
      </tbody>
    </table>
  `;
}

// ── SUBSCRIBER LOOKUP ─────────────────────────────────────────────────
document.getElementById('searchSubscriber').addEventListener('click', async () => {
  const email = document.getElementById('subscriberEmail').value.trim();
  if (!email) return;

  document.getElementById('subscriberResult').innerHTML =
    '<div class="text-center py-3"><div class="spinner-border text-warning"></div></div>';

  const data = await api('subscriber', { email });
  if (data.error) {
    document.getElementById('subscriberResult').innerHTML =
      `<div class="alert alert-danger">${data.error}</div>`;
    return;
  }

  const sub = data.subscriber;
  const score = data.engagement_score;
  const scoreColor = score >= 70 ? '#2e7d32' : score >= 40 ? '#ff9900' : '#ef5350';

  document.getElementById('subscriberResult').innerHTML = `
    <div class="row g-3">
      <div class="col-md-4">
        <div class="card-panel text-center">
          <div class="engagement-ring mb-3" style="background:${scoreColor};">${score}</div>
          <div class="fw-bold">${sub.name || 'Unknown'}</div>
          <div class="text-muted small">${sub.email}</div>
          <div class="mt-2">
            <span class="badge bg-${sub.status === 'enabled' ? 'success' : 'danger'}">${sub.status}</span>
          </div>
          <hr>
          <div class="text-start">
            <small class="text-muted">Lists:</small>
            ${data.lists.map(l => `<div><span class="badge bg-secondary mt-1">${l.name}</span></div>`).join('')}
          </div>
        </div>
      </div>
      <div class="col-md-8">
        <div class="card-panel">
          <h6>Campaign History (${data.history.length} campaigns)</h6>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Campaign</th><th>Sent</th><th>Opens</th><th>Clicks</th><th>First Opened</th></tr></thead>
              <tbody>
                ${data.history.map(h => `
                  <tr>
                    <td><small>${h.name}</small></td>
                    <td><small>${h.started_at ? formatDate(h.started_at) : '—'}</small></td>
                    <td>${h.open_count > 0 ? `<span class="badge bg-success">${h.open_count}</span>` : '—'}</td>
                    <td>${h.click_count > 0 ? `<span class="badge bg-primary">${h.click_count}</span>` : '—'}</td>
                    <td><small>${h.opened_at ? formatDateTime(h.opened_at) : '<span class="text-muted">Not opened</span>'}</small></td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  `;
});

document.getElementById('subscriberEmail').addEventListener('keypress', e => {
  if (e.key === 'Enter') document.getElementById('searchSubscriber').click();
});

// ── DOMAINS ───────────────────────────────────────────────────────────
async function loadDomains() {
  const domains = await api('domains');

  destroyChart('domainsDetailChart');
  const ctx = document.getElementById('domainsDetailChart').getContext('2d');
  charts.domainsDetail = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: domains.map(d => d.domain),
      datasets: [
        { label: 'Active', data: domains.map(d => d.active), backgroundColor: '#ff9900' },
        { label: 'Blocklisted', data: domains.map(d => d.blocklisted), backgroundColor: '#ef5350' }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
    }
  });

  document.getElementById('domainsTable').innerHTML = `
    <table class="table table-sm">
      <thead><tr><th>Domain</th><th>Active</th><th>Blocklisted</th><th>Total</th></tr></thead>
      <tbody>
        ${domains.map(d => `
          <tr>
            <td>${d.domain}</td>
            <td class="text-success">${d.active}</td>
            <td class="text-danger">${d.blocklisted}</td>
            <td><strong>${d.total}</strong></td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
}

// ── HEATMAP ───────────────────────────────────────────────────────────
async function loadHeatmap() {
  const data = await api('best_times');
  const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const hours = Array.from({length: 24}, (_, i) => i);

  // Build matrix
  const matrix = {};
  let maxVal = 0;
  data.forEach(r => {
    const key = `${r.day_of_week}-${r.hour}`;
    matrix[key] = parseInt(r.opens);
    if (matrix[key] > maxVal) maxVal = matrix[key];
  });

  let html = '<div style="overflow-x:auto;"><div class="heatmap-grid" style="grid-template-columns:50px repeat(24, 1fr);gap:3px;min-width:700px;">';

  // Hour headers
  html += '<div></div>';
  hours.forEach(h => {
    html += `<div class="heatmap-hour">${h}h</div>`;
  });

  // Day rows
  days.forEach((day, d) => {
    html += `<div class="heatmap-label">${day}</div>`;
    hours.forEach(h => {
      const val = matrix[`${d}-${h}`] || 0;
      const intensity = maxVal > 0 ? val / maxVal : 0;
      const alpha = 0.1 + intensity * 0.9;
      const bg = `rgba(255, 153, 0, ${alpha})`;
      const textColor = intensity > 0.6 ? '#fff' : '#333';
      html += `
        <div class="heatmap-cell" style="background:${bg};color:${textColor};" title="${day} ${h}:00 — ${val} opens">
          ${val > 0 ? val : ''}
        </div>`;
    });
  });

  html += '</div></div>';
  html += `<p class="text-muted small mt-3">Darker orange = more email opens at that day/hour combination. Best time to send is the darkest cell.</p>`;
  document.getElementById('heatmapContent').innerHTML = html;
}

// ── AUTO REFRESH ──────────────────────────────────────────────────────
function startRefresh() {
  let secs = REFRESH;
  clearInterval(refreshTimer);
  clearInterval(countdown);

  countdown = setInterval(() => {
    secs--;
    document.getElementById('refreshCountdown').textContent = `Refresh in ${secs}s`;
    document.getElementById('lastRefresh').textContent = `Refresh in ${secs}s`;
    if (secs <= 0) {
      secs = REFRESH;
      const activePage = document.querySelector('.nav-item.active')?.dataset.page;
      if (activePage) loadPage(activePage);
    }
  }, 1000);
}

// ── HELPERS ───────────────────────────────────────────────────────────
function destroyChart(id) {
  if (charts[id]) { charts[id].destroy(); delete charts[id]; }
}

function formatDate(str) {
  if (!str) return '—';
  return new Date(str).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(str) {
  if (!str) return '—';
  return new Date(str).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function timeSince(str) {
  const seconds = Math.floor((new Date() - new Date(str)) / 1000);
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds/60)}m ago`;
  return `${Math.floor(seconds/3600)}h ago`;
}

function escHtml(str) {
  return str?.replace(/'/g, "\\'") || '';
}

// ── INIT ──────────────────────────────────────────────────────────────
loadPage('overview');
startRefresh();
