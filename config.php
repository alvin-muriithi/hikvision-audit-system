<?php
declare(strict_types=1);

//Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'hikvision_audit');
define('DB_USER', 'root');
define('DB_PASS', 'Alvinmuriithi!8');
define('DB_CHARSET', 'utf8mb4');

//Hikvision ISAPI credentials
define('HIK_USERNAME', 'admin');
define('HIK_PASSWORD', '');

// Hikvision API settings
define('HIK_TIMEOUT_SECONDS', 8);
define('HIK_CONNECT_TIMEOUT_SECONDS', 4);
define('HIK_VERIFY_TLS', false); 

//101 main stream monitorring
define('HIK_STREAM_CHANNEL', '101');


//Application timezone
define('APP_TZ', 'Africa/Nairobi');
date_default_timezone_set(APP_TZ);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function system_log(string $message, string $severity = 'INFO'): void
{
    $severity = strtoupper($severity);
    if (!in_array($severity, ['INFO', 'WARNING', 'ERROR'], true)) {
        $severity = 'INFO';
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO system_logs (log_message, severity) VALUES (?, ?)');
        $stmt->execute([$message, $severity]);
    } catch (Throwable $t) {
        
    }
}

