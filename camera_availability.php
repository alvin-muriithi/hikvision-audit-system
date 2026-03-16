<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$pdo = db();

// Helper: last N days (including today), oldest first.
function buildDateRange(int $days): array
{
    $out = [];
    $today = new DateTimeImmutable('today');
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[] = $today->sub(new DateInterval('P' . $i . 'D'));
    }
    return $out;
}

// Total cameras (current inventory).
$totalStmt = $pdo->query('SELECT COUNT(*) AS c FROM cameras');
$totalRow = $totalStmt->fetch() ?: ['c' => 0];
$totalCameras = (int)$totalRow['c'];

// Precise daily availability for last 7 days from camera_availability_daily.
$dailyRange = buildDateRange(7);
$dailyStats = [];

if ($totalCameras > 0) {
    $startDate = $dailyRange[0]->format('Y-m-d');
    $endDate = end($dailyRange)->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT day_date,
                total_polls,
                total_cameras_sum,
                online_cameras_sum,
                warning_cameras_sum,
                offline_cameras_sum,
                unknown_cameras_sum,
                offline_peak
         FROM camera_availability_daily
         WHERE day_date BETWEEN ? AND ?
         ORDER BY day_date ASC'
    );
    $stmt->execute([$startDate, $endDate]);
    $rows = $stmt->fetchAll();
    $byDate = [];
    foreach ($rows as $r) {
        $byDate[$r['day_date']] = $r;
    }

    foreach ($dailyRange as $date) {
        $key = $date->format('Y-m-d');
        $row = $byDate[$key] ?? null;

        if ($row && (int)$row['total_polls'] > 0 && (int)$row['total_cameras_sum'] > 0) {
            $numerator = (int)$row['online_cameras_sum'] + (int)$row['warning_cameras_sum'];
            $denominator = (int)$row['total_cameras_sum'];
            $availability = max(0.0, min(100.0, ($numerator / $denominator) * 100.0));
            $offlinePeak = (int)$row['offline_peak'];
        } else {
            $availability = 0.0;
            $offlinePeak = 0;
        }

        $dailyStats[] = [
            'date' => $key,
            'label' => $date->format('D'),
            'availability' => round($availability, 1),
            'offline_peak' => $offlinePeak,
        ];
    }
}

// Weekly aggregates (last 4 weeks) built from the daily stats above.
$weeklyStats = [];
if ($dailyStats && $totalCameras > 0) {
    $byWeek = [];
    foreach ($dailyStats as $d) {
        $weekKey = (new DateTimeImmutable($d['date']))->format('o-W');
        if (!isset($byWeek[$weekKey])) {
            $byWeek[$weekKey] = [
                'dates' => [],
                'availability_sum' => 0.0,
                'days' => 0,
            ];
        }
        $byWeek[$weekKey]['dates'][] = $d['date'];
        $byWeek[$weekKey]['availability_sum'] += $d['availability'];
        $byWeek[$weekKey]['days']++;
    }

    // Limit to last 4 weeks, sorted by week key.
    ksort($byWeek);
    $byWeek = array_slice($byWeek, -4, 4, true);

    foreach ($byWeek as $weekKey => $data) {
        $avgAvailability = $data['days'] > 0 ? round($data['availability_sum'] / $data['days'], 1) : 0.0;
        $dates = array_map(static fn (string $d): DateTimeImmutable => new DateTimeImmutable($d), $data['dates']);
        usort($dates, static fn ($a, $b) => $a <=> $b);
        $firstDay = reset($dates);
        $lastDay = end($dates);

        // Determine if this is the last week of its month (based on last day).
        $month = (int)$lastDay->format('n');
        $year = (int)$lastDay->format('Y');
        $lastOfMonth = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month');
        $isLastWeekOfMonth = $lastDay->format('o-W') === $lastOfMonth->format('o-W');

        $monthlyAvg = null;
        if ($isLastWeekOfMonth) {
            $monthStart = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-d');
            $monthEnd = $lastOfMonth->format('Y-m-d');
            $monthDays = array_filter(
                $dailyStats,
                static fn (array $d): bool => $d['date'] >= $monthStart && $d['date'] <= $monthEnd
            );
            if ($monthDays) {
                $sum = array_reduce(
                    $monthDays,
                    static fn (float $carry, array $d): float => $carry + $d['availability'],
                    0.0
                );
                $monthlyAvg = round($sum / count($monthDays), 1);
            }
        }

        $weeklyStats[] = [
            'week_label' => $firstDay->format('d M') . ' - ' . $lastDay->format('d M'),
            'total_cameras' => $totalCameras,
            'weekly_avg' => $avgAvailability,
            'monthly_avg' => $monthlyAvg,
        ];
    }
}

// Current status distribution (for pie chart).
$distRow = $pdo->query(
    "SELECT
        SUM(status = 'ONLINE') AS online,
        SUM(status = 'OFFLINE') AS offline,
        SUM(status = 'WARNING') AS warning,
        SUM(status = 'UNKNOWN') AS unknown_status,
        SUM(video_signal_status = 'VIDEO_LOSS') AS video_loss
     FROM cameras"
)->fetch() ?: [];

$statusDistribution = [
    'online' => (int)($distRow['online'] ?? 0),
    'offline' => (int)($distRow['offline'] ?? 0),
    'warning' => (int)($distRow['warning'] ?? 0),
    'unknown_status' => (int)($distRow['unknown_status'] ?? 0),
    'video_loss' => (int)($distRow['video_loss'] ?? 0),
];

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Camera Availability - Hikvision Audit</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  </head>
  <body>
    <div class="layout">
      <aside class="sidebar">
        <div class="brand">
          <div class="logo"></div>
          <div>
            <div class="title">Camera Audit Trail</div>
            <span class="sub">Availability Analytics</span>
          </div>
        </div>
        <nav class="nav">
          <a href="index.php">Dashboard</a>
          <a class="active" href="camera_availability.php">Camera Availability</a>
          <a href="audit_logs.php">Camera Logs</a>
        </nav>
        <div class="footer-note">
          Analytics are derived from HikCentral polling results stored in MySQL.
        </div>
      </aside>

      <main class="main">
        <header class="header">
          <div>
            <div class="h1">Camera Availability</div>
            <div class="hint">Weekly and daily availability trends, plus status distribution.</div>
          </div>
        </header>

        <div class="container">
          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Weekly Availability Summary</div>
                <div class="panel-sub">Per week: total cameras, weekly average availability %, and (in the last week of the month) the monthly average.</div>
              </div>
            </div>
            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Week</th>
                    <th>Total Cameras</th>
                    <th>Weekly Avg Availability (%)</th>
                    <th>Monthly Avg Availability (%)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$weeklyStats): ?>
                    <tr><td colspan="4" class="muted">No weekly data yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($weeklyStats as $w): ?>
                      <tr>
                        <td><?php echo e($w['week_label']); ?></td>
                        <td class="mono"><?php echo e((string)$w['total_cameras']); ?></td>
                        <td class="mono"><?php echo e(number_format($w['weekly_avg'], 1)); ?></td>
                        <td class="mono">
                          <?php echo $w['monthly_avg'] === null ? '—' : e(number_format($w['monthly_avg'], 1)); ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section class="panel kpi-row">
            <div>
              <div class="panel-head">
                <div>
                  <div class="panel-title">Weekly Availability Trend</div>
                  <div class="panel-sub">Last 7 days – X axis: day of week, Y axis: % availability.</div>
                </div>
              </div>
              <div style="position:relative; height:280px;">
              <canvas id="weeklyTrendChart" aria-label="Weekly availability trend" role="img"></canvas>
            </div>

            <div>
              <div class="panel-head">
                <div>
                  <div class="panel-title">Status Distribution</div>
                  <div class="panel-sub">Pie chart of camera status: online, offline, warning, video loss, unknown.</div>
                </div>
              </div>
              <div style="position:relative; height:280px;">
              <canvas id="statusPieChart" aria-label="Camera status distribution" role="img"></canvas>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head">
              <div>
                <div class="panel-title">Daily Camera Availability</div>
                <div class="panel-sub">Per day: average availability and offline cameras (peak, approximated from offline events).</div>
              </div>
            </div>
            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Day</th>
                    <th>Date</th>
                    <th>Average Availability (%)</th>
                    <th>Offline Cameras (Peak)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$dailyStats): ?>
                    <tr><td colspan="4" class="muted">No daily data yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($dailyStats as $d): ?>
                      <tr>
                        <td><?php echo e($d['label']); ?></td>
                        <td class="mono"><?php echo e($d['date']); ?></td>
                        <td class="mono"><?php echo e(number_format($d['availability'], 1)); ?></td>
                        <td class="mono"><?php echo e((string)$d['offline_peak']); ?></td>
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

    <script>
      const dailyStats = <?php echo json_encode($dailyStats, JSON_UNESCAPED_SLASHES); ?>;
      const statusDistribution = <?php echo json_encode($statusDistribution, JSON_UNESCAPED_SLASHES); ?>;

      (function () {
        if (!window.Chart || !Array.isArray(dailyStats)) {
          return;
        }

        // Weekly trend line chart (last 7 days).
        const trendCtx = document.getElementById('weeklyTrendChart');
        if (trendCtx && dailyStats.length) {
          const labels = dailyStats.map(d => d.label);
          const data = dailyStats.map(d => d.availability);

          new Chart(trendCtx, {
            type: 'line',
            data: {
              labels,
              datasets: [{
                label: 'Availability %',
                data,
                borderColor: '#1d9bf0',
                backgroundColor: 'rgba(29, 155, 240, 0.15)',
                tension: 0.3,
                fill: true,
                pointRadius: 4,
              }],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              resizeDelay: 100,
              scales: {
                y: {
                  min: 0,
                  max: 100,
                  ticks: {
                    callback: value => value + '%',
                  },
                },
              },
            },
          });
        }

        // Status distribution pie chart.
        const statusCtx = document.getElementById('statusPieChart');
        if (statusCtx) {
          const labels = ['Online', 'Offline', 'Warning', 'Video Loss', 'Unknown'];
          const data = [
            statusDistribution.online || 0,
            statusDistribution.offline || 0,
            statusDistribution.warning || 0,
            statusDistribution.video_loss || 0,
            statusDistribution.unknown_status || 0,
          ];

          new Chart(statusCtx, {
            type: 'doughnut',
            data: {
              labels,
              datasets: [{
                data,
                backgroundColor: [
                  '#16a34a',
                  '#dc2626',
                  '#f97316',
                  '#eab308',
                  '#6b7280',
                ],
              }],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
            },
          });
        }
      })();
    </script>
  </body>
</html>

