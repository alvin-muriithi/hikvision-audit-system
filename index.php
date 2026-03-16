<?php
declare(strict_types=1);

// Landing shell only – detailed views live on dedicated pages.
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
            <span class="sub">Monitoring & Analytics</span>
          </div>
        </div>
        <nav class="nav">
          <a class="active" href="index.php">Dashboard</a>
          <a href="camera_availability.php">Camera Availability</a>
          <a href="audit_logs.php">Camera Logs</a>
        </nav>
        <div class="footer-note">
          Polling is performed by <span class="mono">camera_poll.php</span> (schedule every 3 minutes).
        </div>
      </aside>

      <main class="main">
        <header class="header">
          <div>
            <div class="h1">Dashboard</div>
            <div class="hint">Use the navigation on the left to access camera availability analytics and detailed camera logs.</div>
          </div>
        </header>

        <div class="container">
          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Navigation</div>
                <div class="panel-sub">Core camera auditing functions</div>
              </div>
            </div>
            <div class="cards">
              <a class="card" href="camera_availability.php" style="text-decoration:none;">
                <div class="label">Camera Availability</div>
                <div class="meta muted">View weekly and daily availability, trends, and status distribution.</div>
              </a>
              <a class="card" href="audit_logs.php" style="text-decoration:none;">
                <div class="label">Camera Logs</div>
                <div class="meta muted">Search a specific camera and download logs for a chosen time period.</div>
              </a>
            </div>
          </section>
        </div>
      </main>
    </div>

  </body>
</html>

