<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
//require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hikvision_api.php';

$startedAt = microtime(true);

try {
    $pdo = db();
    $api = new HikvisionApi();

    $cameras = $pdo->query('SELECT * FROM cameras ORDER BY id ASC')->fetchAll();
    if (!$cameras) {
        system_log('camera_poll: no cameras in database', 'WARNING');
        exit(0);
    }

    $eventsInserted = 0;
    $updated = 0;

    // For daily availability aggregation.
    $statusCounts = [
        'total' => 0,
        'ONLINE' => 0,
        'OFFLINE' => 0,
        'WARNING' => 0,
        'UNKNOWN' => 0,
        'video_loss' => 0,
    ];

    foreach ($cameras as $cam) {
        $cameraId = (int)$cam['id'];
        $ip = (string)$cam['ip_address'];

        $prev = [
            'status' => (string)$cam['status'],
            'video_signal_status' => (string)$cam['video_signal_status'],
            'recording_status' => (string)$cam['recording_status'],
            'communication_status' => (string)$cam['communication_status'],
        ];

        $status = $api->getCameraStatus($ip);
        $reachable = (bool)($status['reachable'] ?? false);

        $video = $reachable ? $api->getVideoStatus($ip) : ['ok' => false, 'video' => 'UNKNOWN'];
        $rec = $reachable ? $api->getRecordingStatus($ip) : ['ok' => false, 'recording' => 'UNKNOWN'];

        $new = [
            'status' => $reachable ? (($status['network'] ?? 'UNKNOWN') === 'ONLINE' ? 'ONLINE' : 'OFFLINE') : 'OFFLINE',
            'video_signal_status' => $reachable ? (string)($video['video'] ?? 'UNKNOWN') : 'UNKNOWN',
            'recording_status' => $reachable ? (string)($rec['recording'] ?? 'UNKNOWN') : 'UNKNOWN',
            'communication_status' => $reachable ? (string)($status['communication'] ?? 'UNKNOWN') : 'EXCEPTION',
        ];

        // Derive WARNING if any exception-like signals exist.
        if ($new['status'] === 'ONLINE') {
            if ($new['video_signal_status'] === 'VIDEO_LOSS' || $new['communication_status'] === 'EXCEPTION' || $new['recording_status'] === 'FAILED') {
                $new['status'] = 'WARNING';
            }
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $lastSeen = $reachable ? $now : ($cam['last_seen'] ?? null);

        $eventType = null;
        $eventDesc = null;

        // Transition-based events (audit trail)
        if ($prev['status'] !== $new['status']) {
            if (in_array($prev['status'], ['ONLINE', 'WARNING'], true) && $new['status'] === 'OFFLINE') {
                $eventType = 'CAMERA_OFFLINE';
                $eventDesc = 'Camera became unreachable (network offline / comm exception).';
            } elseif ($prev['status'] === 'OFFLINE' && in_array($new['status'], ['ONLINE', 'WARNING'], true)) {
                $eventType = 'DEVICE_RECONNECT';
                $eventDesc = 'Camera reachable again (device reconnected).';
            } else {
                $eventType = 'STATUS_CHANGE';
                $eventDesc = sprintf('Status changed from %s to %s.', $prev['status'], $new['status']);
            }
        }

        if ($reachable && $prev['video_signal_status'] !== $new['video_signal_status']) {
            if ($new['video_signal_status'] === 'VIDEO_LOSS') {
                $eventType = $eventType ?? 'VIDEO_LOSS';
                $eventDesc = $eventDesc ?? 'Video signal loss detected on streaming channel.';
            } elseif ($prev['video_signal_status'] === 'VIDEO_LOSS' && $new['video_signal_status'] === 'OK') {
                $eventType = $eventType ?? 'VIDEO_RESTORED';
                $eventDesc = $eventDesc ?? 'Video signal restored on streaming channel.';
            }
        }

        if ($reachable && $prev['communication_status'] !== $new['communication_status']) {
            if ($new['communication_status'] === 'EXCEPTION') {
                $eventType = $eventType ?? 'COMMUNICATION_EXCEPTION';
                $eventDesc = $eventDesc ?? 'Communication exception reported.';
            } elseif ($prev['communication_status'] === 'EXCEPTION' && $new['communication_status'] === 'OK') {
                $eventType = $eventType ?? 'COMMUNICATION_RECOVERED';
                $eventDesc = $eventDesc ?? 'Communication recovered.';
            }
        }

        if ($reachable && $prev['recording_status'] !== $new['recording_status']) {
            if ($new['recording_status'] === 'FAILED') {
                $eventType = $eventType ?? 'RECORDING_FAILED';
                $eventDesc = $eventDesc ?? 'Recording failure detected.';
            } elseif ($prev['recording_status'] === 'FAILED' && $new['recording_status'] === 'OK') {
                $eventType = $eventType ?? 'RECORDING_RECOVERED';
                $eventDesc = $eventDesc ?? 'Recording recovered.';
            }
        }

        // Persist camera status snapshot
        $stmt = $pdo->prepare(
            'UPDATE cameras
             SET status = ?, video_signal_status = ?, recording_status = ?, communication_status = ?, last_seen = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $new['status'],
            $new['video_signal_status'],
            $new['recording_status'],
            $new['communication_status'],
            $lastSeen,
            $cameraId,
        ]);
        $updated++;

        // Track counts for daily availability.
        $statusCounts['total']++;
        $statusCounts[$new['status']] = ($statusCounts[$new['status']] ?? 0) + 1;
        if ($new['video_signal_status'] === 'VIDEO_LOSS') {
            $statusCounts['video_loss']++;
        }

        // Persist event (append-only) if any meaningful transition
        if ($eventType !== null && $eventDesc !== null) {
            $stmtEv = $pdo->prepare('INSERT INTO camera_events (camera_id, event_type, event_description) VALUES (?, ?, ?)');
            $stmtEv->execute([$cameraId, $eventType, $eventDesc]);
            $eventsInserted++;

            $stmtCamEv = $pdo->prepare('UPDATE cameras SET last_event_type = ?, last_event_at = ? WHERE id = ?');
            $stmtCamEv->execute([$eventType, $now, $cameraId]);
        }
    }

    // Update daily availability aggregates.
    if ($statusCounts['total'] > 0) {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        $stmtAgg = $pdo->prepare(
            'INSERT INTO camera_availability_daily (
                day_date,
                total_polls,
                total_cameras_sum,
                online_cameras_sum,
                offline_cameras_sum,
                warning_cameras_sum,
                unknown_cameras_sum,
                offline_peak,
                video_loss_events
             ) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_polls = total_polls + 1,
                total_cameras_sum = total_cameras_sum + VALUES(total_cameras_sum),
                online_cameras_sum = online_cameras_sum + VALUES(online_cameras_sum),
                offline_cameras_sum = offline_cameras_sum + VALUES(offline_cameras_sum),
                warning_cameras_sum = warning_cameras_sum + VALUES(warning_cameras_sum),
                unknown_cameras_sum = unknown_cameras_sum + VALUES(unknown_cameras_sum),
                offline_peak = GREATEST(offline_peak, VALUES(offline_peak)),
                video_loss_events = video_loss_events + VALUES(video_loss_events)'
        );

        $stmtAgg->execute([
            $today,
            $statusCounts['total'],
            $statusCounts['ONLINE'] ?? 0,
            $statusCounts['OFFLINE'] ?? 0,
            $statusCounts['WARNING'] ?? 0,
            $statusCounts['UNKNOWN'] ?? 0,
            $statusCounts['OFFLINE'] ?? 0,
            $statusCounts['video_loss'] ?? 0,
        ]);
    }

    $ms = (int)round((microtime(true) - $startedAt) * 1000);
    system_log(sprintf('camera_poll: checked=%d updated=%d events=%d time_ms=%d', count($cameras), $updated, $eventsInserted, $ms), 'INFO');
    exit(0);
} catch (Throwable $t) {
    system_log('camera_poll: fatal error: ' . $t->getMessage(), 'ERROR');
    // Non-zero exit helps schedulers detect failure.
    exit(1);
}

