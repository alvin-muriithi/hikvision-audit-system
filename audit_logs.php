<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

try {
    $pdo = db();

    $q = trim((string)($_GET['q'] ?? ''));
    $event = strtoupper(trim((string)($_GET['event'] ?? '')));
    $download = (string)($_GET['download'] ?? '');

    $allowedEvent = [
        '',
        'CAMERA_OFFLINE',
        'VIDEO_LOSS',
        'COMMUNICATION_EXCEPTION',
        'RECORDING_FAILED',
        'DEVICE_RECONNECT',
        'STATUS_CHANGE',
        'VIDEO_RESTORED',
        'COMMUNICATION_RECOVERED',
        'RECORDING_RECOVERED',
    ];
    if (!in_array($event, $allowedEvent, true)) {
        $event = '';
    }

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(c.camera_name LIKE ? OR c.ip_address LIKE ? OR c.nvr_name LIKE ? OR c.area LIKE ? OR e.event_description LIKE ?)';
        $like = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    if ($event !== '') {
        $where[] = 'e.event_type = ?';
        $params[] = $event;
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
         LIMIT 2000"
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
            <span class="sub">Events & Exceptions</span>
          </div>
        </div>
        <nav class="nav">
          <a href="index.php">Dashboard</a>
          <a href="cameras.php">Cameras</a>
          <a class="active" href="audit_logs.php">Audit Logs <span class="pill">CSV</span></a>
        </nav>
        <div class="footer-note">
          This is an append-only audit trail generated from <span class="mono">camera_poll.php</span>.
        </div>
      </aside>

      <main class="main">
        <header class="header">
          <div>
            <div class="h1">Audit Trail Logs</div>
            <div class="hint">Showing the latest 2000 events. Use search to find a camera or incident.</div>
          </div>
        </header>

        <div class="container">
          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Search & Filter</div>
                <div class="panel-sub">Download filtered results as CSV</div>
              </div>
              <div class="panel-right">
                <a class="badge" href="<?php echo e('audit_logs.php?download=csv&q=' . urlencode($q) . '&event=' . urlencode($event)); ?>"><span class="dot"></span>Download CSV</a>
              </div>
            </div>

            <form class="controls" method="get" action="audit_logs.php">
              <input name="q" type="search" placeholder="Search camera name, IP, area, description" value="<?php echo e($q); ?>">
              <select name="event">
                <option value="">All event types</option>
                <?php foreach ([
                  'CAMERA_OFFLINE' => 'Camera Offline',
                  'DEVICE_RECONNECT' => 'Device Reconnect',
                  'VIDEO_LOSS' => 'Video Loss',
                  'VIDEO_RESTORED' => 'Video Restored',
                  'COMMUNICATION_EXCEPTION' => 'Communication Exception',
                  'COMMUNICATION_RECOVERED' => 'Communication Recovered',
                  'RECORDING_FAILED' => 'Recording Failed',
                  'RECORDING_RECOVERED' => 'Recording Recovered',
                  'STATUS_CHANGE' => 'Status Change',
                ] as $k => $label): ?>
                  <option value="<?php echo e($k); ?>" <?php echo $event === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
              </select>
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

