<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

try {
    $pdo = db();

    $q = trim((string)($_GET['q'] ?? ''));
    $status = strtoupper(trim((string)($_GET['status'] ?? '')));
    $video = strtoupper(trim((string)($_GET['video'] ?? '')));
    $recording = strtoupper(trim((string)($_GET['recording'] ?? '')));

    $allowedStatus = ['ONLINE', 'OFFLINE', 'WARNING', 'UNKNOWN', ''];
    $allowedVideo = ['OK', 'VIDEO_LOSS', 'UNKNOWN', ''];
    $allowedRec = ['OK', 'FAILED', 'NO_SCHEDULE', 'UNKNOWN', ''];

    if (!in_array($status, $allowedStatus, true)) {
        $status = '';
    }
    if (!in_array($video, $allowedVideo, true)) {
        $video = '';
    }
    if (!in_array($recording, $allowedRec, true)) {
        $recording = '';
    }

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = '(camera_name LIKE ? OR ip_address LIKE ? OR nvr_name LIKE ? OR area LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($video !== '') {
        $where[] = 'video_signal_status = ?';
        $params[] = $video;
    }
    if ($recording !== '') {
        $where[] = 'recording_status = ?';
        $params[] = $recording;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Dashboard aggregates are based on full inventory (not filtered),
    // matching operational dashboards where top numbers are global.
    $agg = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(status IN ('ONLINE')) AS online,
            SUM(status IN ('OFFLINE')) AS offline,
            SUM(status IN ('WARNING')) AS warning,
            SUM(video_signal_status = 'VIDEO_LOSS') AS video_loss,
            SUM(communication_status = 'EXCEPTION') AS communication_exception,
            SUM(recording_status = 'FAILED') AS recording_failed
         FROM cameras"
    )->fetch() ?: [];

    $stmt = $pdo->prepare(
        "SELECT
            id,
            camera_name,
            ip_address,
            nvr_name,
            area,
            status,
            video_signal_status,
            recording_status,
            communication_status,
            last_seen,
            last_event_type,
            last_event_at
         FROM cameras
         $sqlWhere
         ORDER BY
            FIELD(status, 'OFFLINE', 'WARNING', 'ONLINE', 'UNKNOWN'),
            camera_name ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_response([
        'ok' => true,
        'summary' => [
            'total' => (int)($agg['total'] ?? 0),
            'online' => (int)($agg['online'] ?? 0),
            'offline' => (int)($agg['offline'] ?? 0),
            'warning' => (int)($agg['warning'] ?? 0),
            'video_loss' => (int)($agg['video_loss'] ?? 0),
            'communication_exception' => (int)($agg['communication_exception'] ?? 0),
            'recording_failed' => (int)($agg['recording_failed'] ?? 0),
        ],
        'cameras' => array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'camera_name' => (string)$r['camera_name'],
                'ip_address' => (string)$r['ip_address'],
                'nvr_name' => (string)($r['nvr_name'] ?? ''),
                'area' => (string)($r['area'] ?? ''),
                'status' => (string)$r['status'],
                'video_signal_status' => (string)$r['video_signal_status'],
                'recording_status' => (string)$r['recording_status'],
                'communication_status' => (string)$r['communication_status'],
                'last_seen' => $r['last_seen'],
                'last_event_type' => $r['last_event_type'],
                'last_event_at' => $r['last_event_at'],
            ];
        }, $rows),
    ]);
} catch (Throwable $t) {
    system_log('fetch_status error: ' . $t->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Server error'], 500);
}

