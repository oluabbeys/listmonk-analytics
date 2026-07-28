<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZODML Listmonk Analytics</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/dashboard.css" rel="stylesheet">
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand-icon">📊</span>
    <span class="brand-text">ZODML Analytics</span>
  </div>
  <nav class="sidebar-nav">
    <a href="#" class="nav-item active" data-page="overview"><i class="fas fa-tachometer-alt"></i> Overview</a>
    <a href="#" class="nav-item" data-page="campaigns"><i class="fas fa-paper-plane"></i> Campaigns</a>
    <a href="#" class="nav-item" data-page="live"><i class="fas fa-circle text-danger"></i> Live Feed</a>
    <a href="#" class="nav-item" data-page="trends"><i class="fas fa-chart-line"></i> Trends</a>
    <a href="#" class="nav-item" data-page="lists"><i class="fas fa-list"></i> Lists</a>
    <a href="#" class="nav-item" data-page="subscribers"><i class="fas fa-users"></i> Subscribers</a>
    <a href="#" class="nav-item" data-page="domains"><i class="fas fa-globe"></i> Domains</a>
    <a href="#" class="nav-item" data-page="heatmap"><i class="fas fa-fire"></i> Send Heatmap</a>
  </nav>
  <div class="sidebar-footer">
    <div class="refresh-indicator">
      <span class="pulse"></span>
      <span id="lastRefresh">Refreshing...</span>
    </div>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

  <!-- TOP BAR -->
  <div class="topbar">
    <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle">
      <i class="fas fa-bars"></i>
    </button>
    <h5 class="topbar-title" id="pageTitle">Overview</h5>
    <div class="topbar-right">
      <span class="badge bg-success" id="liveIndicator">● LIVE</span>
      <span class="text-muted small ms-3" id="refreshCountdown"></span>
    </div>
  </div>

  <!-- PAGE: OVERVIEW -->
  <div class="page active" id="page-overview">
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
          <div class="stat-value" id="stat-subscribers">—</div>
          <div class="stat-label">Active Subscribers</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon bg-success"><i class="fas fa-paper-plane"></i></div>
          <div class="stat-value" id="stat-campaigns">—</div>
          <div class="stat-label">Total Campaigns</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon bg-warning"><i class="fas fa-eye"></i></div>
          <div class="stat-value" id="stat-opens-today">—</div>
          <div class="stat-label">Opens Today</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon bg-danger"><i class="fas fa-ban"></i></div>
          <div class="stat-value" id="stat-blocklisted">—</div>
          <div class="stat-label">Blocklisted</div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-8">
        <div class="card-panel">
          <div class="panel-header">
            <h6>30-Day Open & Click Trends</h6>
          </div>
          <canvas id="trendsChart" height="120"></canvas>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card-panel">
          <div class="panel-header"><h6>Subscriber Status</h6></div>
          <canvas id="subscriberChart" height="200"></canvas>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-md-6">
        <div class="card-panel">
          <div class="panel-header"><h6>Recent Campaigns</h6></div>
          <div id="recentCampaigns"></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-panel">
          <div class="panel-header"><h6>Top Email Domains</h6></div>
          <canvas id="domainsChart" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- PAGE: CAMPAIGNS -->
  <div class="page" id="page-campaigns">
    <div class="card-panel">
      <div class="panel-header">
        <h6>All Campaigns</h6>
        <input type="text" class="form-control form-control-sm w-25" id="campaignSearch" placeholder="Search campaigns...">
      </div>
      <div class="table-responsive">
        <table class="table table-hover" id="campaignsTable">
          <thead>
            <tr>
              <th>Campaign</th>
              <th>Status</th>
              <th>Recipients</th>
              <th>Opens</th>
              <th>Open Rate</th>
              <th>Clicks</th>
              <th>Click Rate</th>
              <th>Sent</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="campaignsList"></tbody>
        </table>
      </div>
    </div>

    <!-- Campaign Detail Modal -->
    <div class="modal fade" id="campaignModal" tabindex="-1">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="campaignModalTitle">Campaign Detail</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="campaignModalBody">
            <div class="text-center py-4"><div class="spinner-border"></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PAGE: LIVE FEED -->
  <div class="page" id="page-live">
    <div class="row g-3">
      <div class="col-md-8">
        <div class="card-panel">
          <div class="panel-header">
            <h6><span class="pulse me-2"></span>Live Activity (Last 60 Minutes)</h6>
            <span class="badge bg-danger" id="liveCount">0 events</span>
          </div>
          <div id="liveFeed" style="max-height:600px;overflow-y:auto;"></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card-panel">
          <div class="panel-header"><h6>Activity Stats</h6></div>
          <div id="liveStats"></div>
        </div>
        <div class="card-panel mt-3">
          <div class="panel-header"><h6>Opens per Minute</h6></div>
          <canvas id="liveChart" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- PAGE: TRENDS -->
  <div class="page" id="page-trends">
    <div class="row g-3">
      <div class="col-12">
        <div class="card-panel">
          <div class="panel-header">
            <h6>Opens & Clicks — Last 30 Days</h6>
          </div>
          <canvas id="trendsDetailChart" height="100"></canvas>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-panel">
          <div class="panel-header"><h6>Subscriber Growth — Last 30 Days</h6></div>
          <canvas id="growthChart" height="200"></canvas>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-panel">
          <div class="panel-header"><h6>Best Day to Send</h6></div>
          <canvas id="bestDayChart" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- PAGE: LISTS -->
  <div class="page" id="page-lists">
    <div class="card-panel">
      <div class="panel-header"><h6>All Lists</h6></div>
      <div id="listsContent"></div>
    </div>
  </div>

  <!-- PAGE: SUBSCRIBERS -->
  <div class="page" id="page-subscribers">
    <div class="card-panel">
      <div class="panel-header">
        <h6>Subscriber Lookup</h6>
      </div>
      <div class="input-group mb-3 w-50">
        <input type="email" class="form-control" id="subscriberEmail" placeholder="Enter email address...">
        <button class="btn btn-primary" id="searchSubscriber">
          <i class="fas fa-search"></i> Search
        </button>
      </div>
      <div id="subscriberResult"></div>
    </div>
  </div>

  <!-- PAGE: DOMAINS -->
  <div class="page" id="page-domains">
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card-panel">
          <div class="panel-header"><h6>Top 20 Domains</h6></div>
          <canvas id="domainsDetailChart" height="300"></canvas>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-panel">
          <div class="panel-header"><h6>Domain Breakdown</h6></div>
          <div id="domainsTable"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- PAGE: HEATMAP -->
  <div class="page" id="page-heatmap">
    <div class="card-panel">
      <div class="panel-header"><h6>Best Time to Send — Email Opens Heatmap</h6></div>
      <p class="text-muted small">Based on when subscribers actually open emails. Darker = more opens.</p>
      <div id="heatmapContent"></div>
    </div>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
