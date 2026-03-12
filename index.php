<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hikvision Camera Audit Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
  </head>
  <body>
    <div class="layout">
      <aside class="sidebar">
        <div class="brand">
          <div class="logo"></div>
          <div>
            <div class="title">Camera Audit Trail</div>
            <span class="sub">Hikvision ISAPI Monitoring</span>
          </div>
        </div>
        <nav class="nav">
          <a class="active" href="index.php">Dashboard <span class="pill">Live</span></a>
          <a href="cameras.php">Cameras</a>
          <a href="audit_logs.php">Audit Logs</a>
        </nav>
        <div class="footer-note">
          Polling is performed by <span class="mono">camera_poll.php</span> (schedule every 3 minutes).
        </div>
      </aside>

      <main class="main">
        <header class="header">
          <div>
            <div class="h1">Health Monitoring Dashboard</div>
            <div class="hint">Totals reflect the full inventory. Status table auto-refreshes every 180 seconds.</div>
          </div>
          <div class="right">
            <span class="badge unknown"><span class="dot"></span>Last refresh: <span id="lastRefresh" class="mono">—</span></span>
          </div>
        </header>

        <div class="container">
          <section class="cards">
            <div class="card">
              <div class="label">Total Cameras</div>
              <div class="value" id="kpiTotal">—</div>
              <div class="meta muted">Inventory in MySQL</div>
            </div>
            <div class="card">
              <div class="label">Online</div>
              <div class="value" id="kpiOnline">—</div>
              <div class="meta online"><span class="dot"></span> Healthy</div>
            </div>
            <div class="card">
              <div class="label">Offline</div>
              <div class="value" id="kpiOffline">—</div>
              <div class="meta offline"><span class="dot"></span> Unreachable</div>
            </div>
            <div class="card">
              <div class="label">Warning</div>
              <div class="value" id="kpiWarning">—</div>
              <div class="meta warning"><span class="dot"></span> Abnormal signals</div>
            </div>
            <div class="card">
              <div class="label">Video Loss</div>
              <div class="value" id="kpiVideoLoss">—</div>
              <div class="meta warning"><span class="dot"></span> Streaming exception</div>
            </div>
            <div class="card">
              <div class="label">Recording Failed</div>
              <div class="value" id="kpiRecFail">—</div>
              <div class="meta warning"><span class="dot"></span> NVR integration needed</div>
            </div>
          </section>

          <section class="panel kpi-row">
            <div>
              <div class="panel-head">
                <div>
                  <div class="panel-title">Online Ratio</div>
                  <div class="panel-sub">Quick view similar to HikCentral health panel</div>
                </div>
                <div class="panel-right">
                  <span class="badge online"><span class="dot"></span><span id="onlinePct">—</span></span>
                </div>
              </div>
              <div class="progress" aria-label="Online progress">
                <span id="onlineProgress"></span>
              </div>
              <div class="footer-note">
                Tip: Keep polling inside the closed LAN and restrict this web UI to trusted admin subnets.
              </div>
            </div>

            <div>
              <div class="panel-head">
                <div>
                  <div class="panel-title">Quick Filters</div>
                  <div class="panel-sub">Applies to the table below</div>
                </div>
              </div>
              <div class="controls">
                <input id="q" type="search" placeholder="Search name, IP, NVR, area">
                <select id="filterStatus">
                  <option value="">All statuses</option>
                  <option value="OFFLINE">Offline</option>
                  <option value="WARNING">Warning</option>
                  <option value="ONLINE">Online</option>
                  <option value="UNKNOWN">Unknown</option>
                </select>
                <select id="filterVideo">
                  <option value="">All video</option>
                  <option value="VIDEO_LOSS">Video Loss</option>
                  <option value="OK">OK</option>
                  <option value="UNKNOWN">Unknown</option>
                </select>
                <button id="applyFilters" type="button">Apply</button>
              </div>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Camera Status</div>
                <div class="panel-sub">Live view from last poll results</div>
              </div>
              <div class="panel-right">
                <span id="resultCount" class="badge unknown"><span class="dot"></span>—</span>
                <a class="badge" href="audit_logs.php"><span class="dot"></span>View audit trail</a>
              </div>
            </div>

            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>Camera Name</th>
                    <th>IP Address</th>
                    <th>Device/NVR</th>
                    <th>Area</th>
                    <th>Video Signal</th>
                    <th>Recording</th>
                    <th>Communication</th>
                    <th>Last Seen</th>
                    <th>Last Event</th>
                  </tr>
                </thead>
                <tbody id="cameraTbody">
                  <tr><td colspan="10" class="muted">Loading…</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="js/refresh.js"></script>
  </body>
</html>

