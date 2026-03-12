<?php
declare(strict_types=1);

/**
 * Camera polling service (run via Windows Task Scheduler or cron).
 *
 * Recommended schedule: every 3 minutes.
 * Example (CLI):
 *   php camera_poll.php
 */

require_once __DIR__ . '/config.php';
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

        // Persist event (append-only) if any meaningful transition
        if ($eventType !== null && $eventDesc !== null) {
            $stmtEv = $pdo->prepare('INSERT INTO camera_events (camera_id, event_type, event_description) VALUES (?, ?, ?)');
            $stmtEv->execute([$cameraId, $eventType, $eventDesc]);
            $eventsInserted++;

            $stmtCamEv = $pdo->prepare('UPDATE cameras SET last_event_type = ?, last_event_at = ? WHERE id = ?');
            $stmtCamEv->execute([$eventType, $now, $cameraId]);
        }
    }

    $ms = (int)round((microtime(true) - $startedAt) * 1000);
    system_log(sprintf('camera_poll: checked=%d updated=%d events=%d time_ms=%d', count($cameras), $updated, $eventsInserted, $ms), 'INFO');
    exit(0);
} catch (Throwable $t) {
    system_log('camera_poll: fatal error: ' . $t->getMessage(), 'ERROR');
    // Non-zero exit helps schedulers detect failure.
    exit(1);
}

