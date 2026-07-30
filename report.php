<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

if (isset($_GET['action'])) {
    requireAuthApi();
} else {
    requireAuthPage();
}

$db = getDB();

// Handle API requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'search') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $stmt = $db->prepare("
            SELECT id, name, subject, status, started_at, updated_at
            FROM campaigns
            WHERE subject ILIKE ? OR name ILIKE ?
            ORDER BY created_at DESC LIMIT 10
        ");
        $stmt->execute([$q, $q]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($_GET['action'] === 'report') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'No ID']); exit; }

        // Campaign info
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
        $stmt->execute([$id]);
        $campaign = $stmt->fetch();
        if (!$campaign) { echo json_encode(['error' => 'Campaign not found']); exit; }

        // Lists
        $stmt = $db->prepare("
            SELECT string_agg(l.name, ', ') as lists
            FROM campaign_lists cl JOIN lists l ON cl.list_id = l.id
            WHERE cl.campaign_id = ?
        ");
        $stmt->execute([$id]);
        $lists = $stmt->fetch()['lists'] ?? '—';

        // Subscribers with tracking
        $stmt = $db->prepare("
            SELECT
                s.id, s.email, s.name, s.status,
                MIN(cv.created_at) as first_open,
                COUNT(DISTINCT cv.id) as open_count,
                COUNT(DISTINCT lc.id) as click_count,
                MIN(lc.created_at) as first_click
            FROM subscribers s
            JOIN subscriber_lists sl ON s.id = sl.subscriber_id
            JOIN campaign_lists cl ON sl.list_id = cl.list_id AND cl.campaign_id = ?
            LEFT JOIN campaign_views cv ON cv.subscriber_id = s.id AND cv.campaign_id = ?
            LEFT JOIN link_clicks lc ON lc.subscriber_id = s.id AND lc.campaign_id = ?
            GROUP BY s.id, s.email, s.name, s.status
            ORDER BY first_open ASC NULLS LAST
        ");
        $stmt->execute([$id, $id, $id]);
        $subscribers = $stmt->fetchAll();

        // Opens timeline
        $stmt = $db->prepare("
            SELECT DATE_TRUNC('hour', created_at) as hour, COUNT(*) as opens
            FROM campaign_views WHERE campaign_id = ?
            GROUP BY hour ORDER BY hour ASC
        ");
        $stmt->execute([$id]);
        $timeline = $stmt->fetchAll();

        // Top links
        $stmt = $db->prepare("
            SELECT l.url, COUNT(lc.id) as clicks, COUNT(DISTINCT lc.subscriber_id) as unique_clicks
            FROM link_clicks lc JOIN links l ON lc.link_id = l.id
            WHERE lc.campaign_id = ?
            GROUP BY l.url ORDER BY clicks DESC LIMIT 5
        ");
        $stmt->execute([$id]);
        $links = $stmt->fetchAll();

        $total = count($subscribers);
        $opened = count(array_filter($subscribers, fn($s) => $s['first_open']));
        $clicked = count(array_filter($subscribers, fn($s) => $s['click_count'] > 0));

        echo json_encode([
            'campaign' => $campaign,
            'lists' => $lists,
            'subscribers' => $subscribers,
            'timeline' => $timeline,
            'links' => $links,
            'summary' => [
                'total' => $total,
                'opened' => $opened,
                'clicked' => $clicked,
                'not_opened' => $total - $opened
            ]
        ]);
        exit;
    }

    if ($_GET['action'] === 'export') {
        $id = (int)($_GET['id'] ?? 0);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="campaign_' . $id . '_report.csv"');
        $stmt = $db->prepare("
            SELECT s.email, s.name,
                CASE WHEN cv.created_at IS NOT NULL THEN 'Yes' ELSE 'No' END as opened,
                cv.created_at as opened_at,
                COALESCE(lc_c.clicks, 0) as clicks
            FROM subscribers s
            JOIN subscriber_lists sl ON s.id = sl.subscriber_id
            JOIN campaign_lists cl ON sl.list_id = cl.list_id AND cl.campaign_id = ?
            LEFT JOIN (SELECT subscriber_id, MIN(created_at) as created_at FROM campaign_views WHERE campaign_id = ? GROUP BY subscriber_id) cv ON s.id = cv.subscriber_id
            LEFT JOIN (SELECT subscriber_id, COUNT(*) as clicks FROM link_clicks WHERE campaign_id = ? GROUP BY subscriber_id) lc_c ON s.id = lc_c.subscriber_id
            ORDER BY cv.created_at ASC NULLS LAST
        ");
        $stmt->execute([$id, $id, $id]);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Email', 'Name', 'Opened', 'Opened At', 'Clicks']);
        while ($row = $stmt->fetch()) fputcsv($out, $row);
        fclose($out);
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campaign Report — ZODML Analytics</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#222;min-height:100vh}
.container{max-width:920px;margin:0 auto;padding:24px}
.search-card{background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,0.08);margin-bottom:24px}
.search-card h2{font-size:20px;font-weight:700;color:#1a1a2e;margin-bottom:4px}
.search-card p{font-size:12px;color:#aaa;margin-bottom:20px}
.search-row{display:flex;gap:12px}
.search-input{flex:1;padding:13px 18px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;transition:border-color 0.2s}
.search-input:focus{border-color:#ff9900}
.search-btn{padding:13px 32px;background:#ff9900;color:#000;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer}
.hint{font-size:11px;color:#aaa;margin-top:8px}
.hint.found{color:#2e7d32;font-weight:600}
.hint.error{color:#c62828;font-weight:600}
.spinner{display:none;text-align:center;padding:40px;color:#999}
.spin{width:32px;height:32px;border:3px solid #f0f0f0;border-top-color:#ff9900;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}
.report{display:none}
/* ACTIONS */
.actions{display:flex;gap:10px;margin-bottom:16px}
.btn{padding:10px 22px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;display:flex;align-items:center;gap:8px}
.btn-dark{background:#1a1a2e;color:#fff}
.btn-orange{background:#ff9900;color:#000}
.btn-green{background:#2e7d32;color:#fff}
/* REPORT HEADER */
.rpt-header{background:#1a1a2e;border-radius:12px;padding:28px 32px;margin-bottom:20px;color:#fff;position:relative;overflow:hidden}
.rpt-header::before{content:'';position:absolute;top:0;right:0;width:300px;height:100%;background:linear-gradient(135deg,transparent,rgba(255,153,0,0.07))}
.rh-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.35);margin-bottom:8px}
.rh-subject{font-size:22px;font-weight:700;line-height:1.3;margin-bottom:8px}
.rh-meta{font-size:12px;color:rgba(255,255,255,0.45);margin-bottom:4px}
.rh-date{font-size:12px;color:#ff9900}
.rh-badge{float:right;margin-top:-32px;background:rgba(46,125,50,0.3);border:1px solid rgba(46,125,50,0.5);color:#81c784;font-size:10px;font-weight:700;padding:4px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:1px}
/* STAT CARDS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat{background:#fff;border-radius:12px;padding:20px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.06);position:relative;overflow:hidden}
.stat::after{content:'';position:absolute;bottom:0;left:0;right:0;height:4px}
.stat.s1::after{background:#2e7d32}.stat.s2::after{background:#ff9900}
.stat.s3::after{background:#1565c0}.stat.s4::after{background:#ef5350}
.stat-num{font-size:32px;font-weight:700;line-height:1;margin-bottom:4px}
.stat.s1 .stat-num{color:#2e7d32}.stat.s2 .stat-num{color:#ff9900}
.stat.s3 .stat-num{color:#1565c0}.stat.s4 .stat-num{color:#ef5350}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:0.8px;color:#999;font-weight:600}
.stat-sub{font-size:12px;font-weight:600;margin-top:6px}
/* CHARTS ROW */
.charts{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.06)}
.card-title{font-size:13px;font-weight:600;color:#333;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid #f0f0f0}
/* DONUT */
.donut-wrap{display:flex;align-items:center;gap:20px}
.leg-row{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f8f8f8;font-size:12px}
.leg-row:last-child{border-bottom:none}
.leg-dot{width:10px;height:10px;border-radius:50%;margin-right:8px;display:inline-block}
.leg-name{display:flex;align-items:center}
.leg-pct{font-size:10px;color:#aaa;margin-left:4px}
/* PROGRESS */
.prog{margin-bottom:14px}
.prog-label{display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px}
.prog-bar{height:10px;background:#f0f0f0;border-radius:5px;overflow:hidden}
.prog-fill{height:100%;border-radius:5px;transition:width 1s ease}
/* TIMELINE */
.timeline-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.06);margin-bottom:20px}
/* TABLE */
.table-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.06);margin-bottom:20px}
.table-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #f0f0f0}
.table-title{font-size:13px;font-weight:600}
.filters{display:flex;gap:6px;flex-wrap:wrap}
.fbtn{padding:4px 12px;border:1px solid #e0e0e0;border-radius:20px;font-size:11px;cursor:pointer;background:#fff;transition:all 0.2s}
.fbtn.active{background:#ff9900;border-color:#ff9900;color:#000;font-weight:700}
table{width:100%;border-collapse:collapse;font-size:12px}
th{color:#aaa;font-weight:600;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;padding:0 8px 10px 0;text-align:left;border-bottom:2px solid #f0f0f0;white-space:nowrap}
td{padding:9px 8px 9px 0;border-bottom:1px solid #f8f8f8;vertical-align:middle}
tr:last-child td{border-bottom:none}
.badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
.b-opened{background:#e8f5e9;color:#2e7d32}
.b-clicked{background:#e3f2fd;color:#1565c0}
.b-not{background:#f5f5f5;color:#999}
/* FOOTER */
.rpt-footer{background:#fff;border-radius:12px;padding:14px 24px;box-shadow:0 2px 8px rgba(0,0,0,0.06);display:flex;justify-content:space-between;font-size:11px;color:#aaa}
.footer-brand{font-weight:700;color:#ff9900}
@media print{.search-card,.actions{display:none!important}body{background:#fff}.container{padding:0;max-width:100%}}
</style>
</head>
<body>
<div class="container">

  <div class="search-card">
    <h2>📊 Campaign Report Generator</h2>
    <p>Search by campaign subject or name to generate a shareable client report</p>
    <div class="search-row">
      <input type="text" class="search-input" id="q" placeholder="e.g. Poetry Prize, A student almost didn't enter, ZODML for Schools..." />
      <button class="search-btn" onclick="search()">Generate Report</button>
    </div>
    <div class="hint" id="hint">Enter a campaign name or subject above</div>
  </div>

  <div class="spinner" id="spinner"><div class="spin"></div><div>Generating report...</div></div>

  <div class="report" id="report">
    <div class="actions">
      <button class="btn btn-dark" onclick="window.print()">🖨️ Print / Save PDF</button>
      <button class="btn btn-orange" onclick="screenshotTip()">📸 Screenshot Tips</button>
      <button class="btn btn-green" id="exportBtn">⬇️ Export CSV</button>
    </div>

    <div class="rpt-header">
      <div class="rh-eyebrow">ZODML · Listmonk Analytics · Campaign Report</div>
      <span class="rh-badge" id="rBadge">Finished</span>
      <div class="rh-subject" id="rSubject">—</div>
      <div class="rh-meta">Campaign: <span id="rName">—</span> &nbsp;·&nbsp; List: <span id="rList">—</span></div>
      <div class="rh-date">Sent: <span id="rDate">—</span> &nbsp;·&nbsp; Generated: <span id="rGenerated">—</span></div>
    </div>

    <div class="stats">
      <div class="stat s1"><div class="stat-num" id="sSent">—</div><div class="stat-label">Total Sent</div><div class="stat-sub" style="color:#2e7d32" id="sListName">—</div></div>
      <div class="stat s2"><div class="stat-num" id="sOpens">—</div><div class="stat-label">Unique Opens</div><div class="stat-sub" style="color:#ff9900" id="sOpenRate">—</div></div>
      <div class="stat s3"><div class="stat-num" id="sClicks">—</div><div class="stat-label">Unique Clicks</div><div class="stat-sub" style="color:#1565c0" id="sClickRate">—</div></div>
      <div class="stat s4"><div class="stat-num" id="sNotOpened">—</div><div class="stat-label">Not Opened</div><div class="stat-sub" style="color:#ef5350" id="sNotRate">—</div></div>
    </div>

    <div class="charts">
      <div class="card">
        <div class="card-title">Campaign Engagement Overview</div>
        <div class="donut-wrap">
          <svg width="130" height="130" viewBox="0 0 130 130" id="donut">
            <circle cx="65" cy="65" r="52" fill="none" stroke="#f0f0f0" stroke-width="18"/>
            <circle cx="65" cy="65" r="52" fill="none" stroke="#e0e0e0" stroke-width="18" id="dNotOpened" stroke-dasharray="0 327" transform="rotate(-90 65 65)"/>
            <circle cx="65" cy="65" r="52" fill="none" stroke="#ff9900" stroke-width="18" id="dOpened" stroke-dasharray="0 327" transform="rotate(-90 65 65)"/>
            <circle cx="65" cy="65" r="52" fill="none" stroke="#1565c0" stroke-width="18" id="dClicked" stroke-dasharray="0 327" transform="rotate(-90 65 65)"/>
            <text x="65" y="60" text-anchor="middle" font-size="22" font-weight="700" fill="#1a1a2e" id="dCenter">—</text>
            <text x="65" y="76" text-anchor="middle" font-size="10" fill="#999">total sent</text>
          </svg>
          <div style="flex:1">
            <div class="leg-row"><span class="leg-name"><span class="leg-dot" style="background:#2e7d32"></span>Sent</span><span><strong id="ldSent">—</strong></span></div>
            <div class="leg-row"><span class="leg-name"><span class="leg-dot" style="background:#ff9900"></span>Opened</span><span><strong id="ldOpened">—</strong> <span class="leg-pct" id="ldOpenedPct"></span></span></div>
            <div class="leg-row"><span class="leg-name"><span class="leg-dot" style="background:#1565c0"></span>Clicked</span><span><strong id="ldClicked">—</strong> <span class="leg-pct" id="ldClickedPct"></span></span></div>
            <div class="leg-row"><span class="leg-name"><span class="leg-dot" style="background:#e0e0e0"></span>Not Opened</span><span><strong id="ldNotOpened">—</strong> <span class="leg-pct" id="ldNotPct"></span></span></div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-title">Performance Metrics</div>
        <div class="prog"><div class="prog-label"><span>Delivery Rate</span><span style="font-weight:700;color:#2e7d32" id="pDelivery">—</span></div><div class="prog-bar"><div class="prog-fill" id="pbDelivery" style="width:0;background:#2e7d32"></div></div></div>
        <div class="prog"><div class="prog-label"><span>Open Rate</span><span style="font-weight:700;color:#ff9900" id="pOpen">—</span></div><div class="prog-bar"><div class="prog-fill" id="pbOpen" style="width:0;background:#ff9900"></div></div></div>
        <div class="prog"><div class="prog-label"><span>Click Rate</span><span style="font-weight:700;color:#1565c0" id="pClick">—</span></div><div class="prog-bar"><div class="prog-fill" id="pbClick" style="width:0;background:#1565c0"></div></div></div>
        <div class="prog"><div class="prog-label"><span>Click-to-Open</span><span style="font-weight:700;color:#7b1fa2" id="pCTO">—</span></div><div class="prog-bar"><div class="prog-fill" id="pbCTO" style="width:0;background:#7b1fa2"></div></div></div>
        <div class="prog"><div class="prog-label"><span>Not Opened</span><span style="font-weight:700;color:#ef5350" id="pNot">—</span></div><div class="prog-bar"><div class="prog-fill" id="pbNot" style="width:0;background:#ef5350"></div></div></div>
      </div>
    </div>

    <div class="timeline-card">
      <div class="card-title">Opens Over Time</div>
      <canvas id="timelineChart" height="70"></canvas>
      <div id="timelineNote" style="font-size:11px;color:#aaa;margin-top:8px;"></div>
    </div>

    <div class="table-card">
      <div class="table-head">
        <span class="table-title">Individual Subscriber Tracking (<span id="subCount">0</span>)</span>
        <div class="filters">
          <button class="fbtn active" onclick="filter('all',this)">All</button>
          <button class="fbtn" onclick="filter('opened',this)">Opened</button>
          <button class="fbtn" onclick="filter('clicked',this)">Clicked</button>
          <button class="fbtn" onclick="filter('not',this)">Not Opened</button>
        </div>
      </div>
      <div style="overflow-x:auto;max-height:420px;overflow-y:auto;">
        <table>
          <thead>
            <tr><th>#</th><th>Email</th><th>Name</th><th>Status</th><th>Opens</th><th>Clicks</th><th>First Opened</th></tr>
          </thead>
          <tbody id="subBody"></tbody>
        </table>
      </div>
    </div>

    <div id="linksSection" style="display:none">
      <div class="card" style="margin-bottom:20px">
        <div class="card-title">Top Clicked Links</div>
        <table><thead><tr><th>URL</th><th>Clicks</th><th>Unique</th></tr></thead>
        <tbody id="linksBody"></tbody></table>
      </div>
    </div>

    <div class="rpt-footer">
      <div><span class="footer-brand">ZODML Listmonk Analytics</span> · Confidential campaign report</div>
      <div id="footerDate">Generated: —</div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
let allSubs = [], currentCampaignId = null, timelineChart = null;

async function search() {
  const q = document.getElementById('q').value.trim();
  if (!q) return;
  document.getElementById('spinner').style.display = 'block';
  document.getElementById('report').style.display = 'none';
  document.getElementById('hint').className = 'hint';
  document.getElementById('hint').textContent = 'Searching...';

  try {
    const res = await fetch(`report.php?action=search&q=${encodeURIComponent(q)}`);
    const campaigns = await res.json();
    if (!campaigns.length) {
      document.getElementById('spinner').style.display = 'none';
      document.getElementById('hint').className = 'hint error';
      document.getElementById('hint').textContent = `❌ No campaign found matching "${q}"`;
      return;
    }
    const c = campaigns[0];
    document.getElementById('hint').className = 'hint found';
    document.getElementById('hint').textContent = `✅ Found: "${c.name}" — loading report...`;
    const res2 = await fetch(`report.php?action=report&id=${c.id}`);
    const data = await res2.json();
    renderReport(data);
  } catch(e) {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('hint').className = 'hint error';
    document.getElementById('hint').textContent = '❌ Database connection error: ' + e.message;
  }
}

function renderReport(data) {
  const c = data.campaign, s = data.summary;
  currentCampaignId = c.id;
  const total = s.total, opened = s.opened, clicked = s.clicked, notOpened = s.not_opened;
  const openRate = total > 0 ? Math.round(opened/total*100*10)/10 : 0;
  const clickRate = total > 0 ? Math.round(clicked/total*100*10)/10 : 0;
  const ctoRate = opened > 0 ? Math.round(clicked/opened*100*10)/10 : 0;
  const notRate = total > 0 ? Math.round(notOpened/total*100*10)/10 : 0;
  const circ = 327;

  // Header
  document.getElementById('rSubject').textContent = c.subject || c.name;
  document.getElementById('rName').textContent = c.name;
  document.getElementById('rList').textContent = data.lists;
  document.getElementById('rDate').textContent = c.started_at ? fmtDT(c.started_at) : 'Not sent';
  document.getElementById('rGenerated').textContent = fmtDT(new Date().toISOString());
  document.getElementById('rBadge').textContent = '✓ ' + c.status;
  document.getElementById('footerDate').textContent = 'Generated: ' + fmtDT(new Date().toISOString());

  // Stats
  document.getElementById('sSent').textContent = total.toLocaleString();
  document.getElementById('sListName').textContent = data.lists;
  document.getElementById('sOpens').textContent = opened.toLocaleString();
  document.getElementById('sOpenRate').textContent = openRate + '% open rate';
  document.getElementById('sClicks').textContent = clicked.toLocaleString();
  document.getElementById('sClickRate').textContent = clickRate + '% click rate';
  document.getElementById('sNotOpened').textContent = notOpened.toLocaleString();
  document.getElementById('sNotRate').textContent = notRate + '% not opened';

  // Donut
  const dOpen = (opened/total)*circ;
  const dClick = (clicked/total)*circ;
  const dNot = (notOpened/total)*circ;
  document.getElementById('dNotOpened').setAttribute('stroke-dasharray', `${dNot} ${circ-dNot}`);
  document.getElementById('dOpened').setAttribute('stroke-dasharray', `${dOpen} ${circ-dOpen}`);
  document.getElementById('dOpened').setAttribute('stroke-dashoffset', `-${dNot}`);
  document.getElementById('dClicked').setAttribute('stroke-dasharray', `${dClick} ${circ-dClick}`);
  document.getElementById('dClicked').setAttribute('stroke-dashoffset', `-${dNot+dOpen}`);
  document.getElementById('dCenter').textContent = total.toLocaleString();
  document.getElementById('ldSent').textContent = total.toLocaleString();
  document.getElementById('ldOpened').textContent = opened.toLocaleString();
  document.getElementById('ldOpenedPct').textContent = openRate + '%';
  document.getElementById('ldClicked').textContent = clicked.toLocaleString();
  document.getElementById('ldClickedPct').textContent = clickRate + '%';
  document.getElementById('ldNotOpened').textContent = notOpened.toLocaleString();
  document.getElementById('ldNotPct').textContent = notRate + '%';

  // Progress bars
  setTimeout(() => {
    [['pDelivery','pbDelivery','100%',100],['pOpen','pbOpen',openRate+'%',openRate],
     ['pClick','pbClick',clickRate+'%',clickRate],['pCTO','pbCTO',ctoRate+'%',ctoRate],
     ['pNot','pbNot',notRate+'%',notRate]].forEach(([l,b,t,w]) => {
      document.getElementById(l).textContent = t;
      document.getElementById(b).style.width = w + '%';
    });
  }, 100);

  // Timeline chart
  if (timelineChart) timelineChart.destroy();
  const tl = data.timeline || [];
  if (tl.length > 0) {
    const ctx = document.getElementById('timelineChart').getContext('2d');
    timelineChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: tl.map(r => fmtDT(r.hour)),
        datasets: [{ label: 'Opens', data: tl.map(r => r.opens), backgroundColor: '#ff9900', borderRadius: 4 }]
      },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
    const peak = tl.reduce((a,b) => parseInt(b.opens) > parseInt(a.opens) ? b : a, tl[0]);
    document.getElementById('timelineNote').textContent = `💡 Peak opens at ${fmtDT(peak.hour)} (${peak.opens} opens)`;
  } else {
    document.getElementById('timelineChart').style.display = 'none';
    document.getElementById('timelineNote').textContent = 'No open tracking data available for this campaign.';
  }

  // Top links
  if (data.links && data.links.length > 0) {
    document.getElementById('linksSection').style.display = 'block';
    document.getElementById('linksBody').innerHTML = data.links.map(l => `
      <tr>
        <td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
          <a href="${l.url}" target="_blank" style="color:#1565c0;font-size:11px;">${l.url}</a>
        </td>
        <td><strong>${l.clicks}</strong></td>
        <td>${l.unique_clicks}</td>
      </tr>`).join('');
  }

  // Subscribers
  allSubs = data.subscribers || [];
  document.getElementById('subCount').textContent = allSubs.length.toLocaleString();
  renderTable(allSubs);

  // Export button
  document.getElementById('exportBtn').onclick = () => window.location = `report.php?action=export&id=${c.id}`;

  document.getElementById('spinner').style.display = 'none';
  document.getElementById('report').style.display = 'block';
  document.getElementById('hint').textContent = `✅ Showing report for: "${c.name}"`;
}

function renderTable(subs) {
  document.getElementById('subCount').textContent = subs.length.toLocaleString();
  document.getElementById('subBody').innerHTML = subs.map((s,i) => {
    const opened = !!s.first_open, clicked = s.click_count > 0;
    const badge = clicked ? '<span class="badge b-clicked">Clicked</span>'
      : opened ? '<span class="badge b-opened">Opened</span>'
      : '<span class="badge b-not">Not Opened</span>';
    return `<tr style="${opened?'':'opacity:0.55'}">
      <td style="color:#aaa">${i+1}</td>
      <td><strong>${s.email}</strong></td>
      <td style="color:#666">${s.name||'—'}</td>
      <td>${badge}</td>
      <td>${s.open_count>0?`<strong>${s.open_count}</strong>`:'—'}</td>
      <td>${s.click_count>0?`<strong style="color:#1565c0">${s.click_count}</strong>`:'—'}</td>
      <td style="color:#888;font-size:11px">${s.first_open?fmtDT(s.first_open):'—'}</td>
    </tr>`;
  }).join('');
}

function filter(type, btn) {
  document.querySelectorAll('.fbtn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const filtered = type==='all' ? allSubs
    : type==='opened' ? allSubs.filter(s=>!!s.first_open)
    : type==='clicked' ? allSubs.filter(s=>s.click_count>0)
    : allSubs.filter(s=>!s.first_open);
  renderTable(filtered);
}

function fmtDT(str) {
  if (!str) return '—';
  return new Date(str).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
}

function screenshotTip() {
  alert('Mac: Cmd+Shift+4 → drag to select\nWindows: Win+Shift+S → drag to select\n\nOr use Cmd+P / Ctrl+P → Save as PDF for a clean full report.');
}

document.getElementById('q').addEventListener('keypress', e => { if(e.key==='Enter') search(); });

// Auto-load a report directly when linked as report.php?id=<campaign_id>
async function loadById(id) {
  document.getElementById('spinner').style.display = 'block';
  document.getElementById('report').style.display = 'none';
  document.getElementById('hint').className = 'hint';
  document.getElementById('hint').textContent = 'Loading report...';
  try {
    const res = await fetch(`report.php?action=report&id=${encodeURIComponent(id)}`);
    const data = await res.json();
    if (data.error || !data.campaign) {
      document.getElementById('spinner').style.display = 'none';
      document.getElementById('hint').className = 'hint error';
      document.getElementById('hint').textContent = `❌ Campaign #${id} not found`;
      return;
    }
    renderReport(data);
  } catch(e) {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('hint').className = 'hint error';
    document.getElementById('hint').textContent = '❌ Database connection error: ' + e.message;
  }
}

(function initFromURL() {
  const id = new URLSearchParams(window.location.search).get('id');
  if (id) loadById(id);
})();
</script>
</body>
</html>
