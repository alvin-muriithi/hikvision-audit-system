<?php
declare(strict_types=1);

// Legacy camera table view is no longer linked from the main navigation,
// but kept for potential future use.
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cameras - Hikvision Audit</title>
    <link rel="stylesheet" href="css/style.css">
  </head>
  <body>
    <div class="layout">
      <aside class="sidebar">
        <div class="brand">
          <div class="logo"></div>
          <div>
            <div class="title">Camera Audit Trail</div>
            <span class="sub">Inventory & Status</span>
          </div>
        </div>
        <nav class="nav">
          <a href="index.php">Dashboard</a>
          <a href="camera_availability.php">Camera Availability</a>
          <a href="audit_logs.php">Camera Logs</a>
        </nav>
        <div class="footer-note">
          Add cameras into the <span class="mono">cameras</span> table to monitor them.
        </div>
      </aside>

      <main class="main">
        <header class="header">
          <div>
            <div class="h1">Cameras</div>
            <div class="hint">Search and filter camera health (auto-refresh every 180 seconds).</div>
          </div>
          <div class="right">
            <span class="badge unknown"><span class="dot"></span>Last refresh: <span id="lastRefresh" class="mono">—</span></span>
          </div>
        </header>

        <div class="container">
          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Filters</div>
                <div class="panel-sub">Narrow by status signals (offline, video loss, recording failed)</div>
              </div>
              <div class="panel-right">
                <span id="resultCount" class="badge unknown"><span class="dot"></span>—</span>
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
              <select id="filterRecording">
                <option value="">All recording</option>
                <option value="FAILED">Recording Failed</option>
                <option value="OK">OK</option>
                <option value="UNKNOWN">Unknown</option>
              </select>
              <button id="applyFilters" type="button">Apply</button>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Camera Status Table</div>
                <div class="panel-sub">Columns mirror common HikCentral health indicators</div>
              </div>
              <div class="panel-right">
                <a class="badge" href="audit_logs.php"><span class="dot"></span>Audit trail</a>
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

