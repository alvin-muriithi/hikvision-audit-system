<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

try {
    $pdo = db();

    $camera = trim((string)($_GET['camera'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to']   ?? ''));   
    $download = (string)($_GET['download'] ?? '');

    $where = [];
    $params = [];

    if ($camera !== '') {
        $where[] = '(c.camera_name LIKE ? OR c.ip_address LIKE ?)';
        $like = '%' . $camera . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($from !== '') {
        $from = str_replace('T', ' ', $from);
        $where[] = 'e.created_at >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $to = str_replace('T', ' ', $to);
        $where[] = 'e.created_at <= ?';
        $params[] = $to;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare(
        "SELECT
            e.id,
            c.camera_name,
            c.ip_address,
            e.event_type,
            e.event_description,
            e.created_at
         FROM camera_events e
         INNER JOIN cameras c ON c.id = e.camera_id
         $sqlWhere
         ORDER BY e.id DESC
         LIMIT 5000"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($download === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="camera_audit_logs.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Log ID', 'Camera Name', 'IP Address', 'Event Type', 'Event Description', 'Timestamp']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['camera_name'], $r['ip_address'], $r['event_type'], $r['event_description'], $r['created_at']]);
        }
        fclose($out);
        exit;
    }
} catch (Throwable $t) {
    system_log('audit_logs error: ' . $t->getMessage(), 'ERROR');
    $rows = [];
    $camera = $camera ?? '';
    $from = $from ?? '';
    $to = $to ?? '';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Logs - Hikvision Audit</title>
    <link rel="stylesheet" href="css/style.css">
  </head>
  <body>
    <div class="layout">
      <aside class="sidebar">
        <div class="brand">
          <div class="logo"></div>
          <div>
            <div class="title">Camera Audit Trail</div>
            <span class="sub">Camera Logs</span>
          </div>
        </div>
        <nav class="nav">
          <a href="index.php">Dashboard</a>
          <a href="camera_availability.php">Camera Availability</a>
          <a class="active" href="audit_logs.php">Camera Logs <span class="pill">CSV</span></a>
        </nav>
        <div class="footer-note">
          This is an append-only audit trail generated from <span class="mono">camera_poll.php</span>.
        </div>
      </aside>

      <main class="main">
        <header class="header">
          <div>
            <div class="h1">Camera Logs</div>
            <div class="hint">Search for a specific camera and download its logs for a chosen time period.</div>
          </div>
        </header>

        <div class="container">
          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Search &amp; Download</div>
                <div class="panel-sub">Filter by camera name/IP and date range, then download results as CSV.</div>
              </div>
              <div class="panel-right">
                <a class="badge" href="<?php echo e('audit_logs.php?download=csv&camera=' . urlencode($camera ?? '') . '&from=' . urlencode($from ?? '') . '&to=' . urlencode($to ?? '')); ?>"><span class="dot"></span>Download CSV</a>
              </div>
            </div>

            <form class="controls" method="get" action="audit_logs.php">
              <input name="camera" type="search" placeholder="Camera name or IP address" value="<?php echo e($camera ?? ''); ?>">
              <input name="from" type="datetime-local" value="<?php echo e($from ?? ''); ?>">
              <input name="to" type="datetime-local" value="<?php echo e($to ?? ''); ?>">
              <button type="submit">Apply</button>
            </form>
          </section>

          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Event Log</div>
                <div class="panel-sub"><span class="muted">Rows:</span> <?php echo e((string)count($rows)); ?></div>
              </div>
            </div>

            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Log ID</th>
                    <th>Camera Name</th>
                    <th>IP Address</th>
                    <th>Event Type</th>
                    <th>Event Description</th>
                    <th>Timestamp</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="6" class="muted">No matching events.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td class="mono"><?php echo e((string)$r['id']); ?></td>
                      <td><?php echo e((string)$r['camera_name']); ?></td>
                      <td class="mono"><?php echo e((string)$r['ip_address']); ?></td>
                      <td class="mono"><?php echo e((string)$r['event_type']); ?></td>
                      <td><?php echo e((string)$r['event_description']); ?></td>
                      <td class="mono"><?php echo e((string)$r['created_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>
  </body>
</html>

