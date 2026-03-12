<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Minimal Hikvision ISAPI client (Digest Authentication).
 * Returns normalized arrays suitable for dashboard & logging.
 *
 * Notes:
 * - ISAPI responses are typically XML.
 * - Status schemas vary slightly by device/firmware; this client is defensive and falls back to UNKNOWN.
 */
final class HikvisionApi
{
    public function getDeviceInfo(string $ip): array
    {
        $xml = $this->getXml($ip, '/ISAPI/System/deviceInfo');
        if ($xml === null) {
            return ['ok' => false, 'error' => 'No response'];
        }

        return [
            'ok' => true,
            'deviceName' => $this->xmlValue($xml, 'deviceName') ?? $this->xmlValue($xml, 'DeviceName'),
            'model' => $this->xmlValue($xml, 'model') ?? $this->xmlValue($xml, 'Model'),
            'serialNumber' => $this->xmlValue($xml, 'serialNumber') ?? $this->xmlValue($xml, 'SerialNumber'),
            'firmwareVersion' => $this->xmlValue($xml, 'firmwareVersion') ?? $this->xmlValue($xml, 'FirmwareVersion'),
        ];
    }

    public function getCameraStatus(string $ip): array
    {
        // Many devices expose /ISAPI/System/status.
        // If request fails, treat as OFFLINE.
        $xml = $this->getXml($ip, '/ISAPI/System/status');
        if ($xml === null) {
            return [
                'reachable' => false,
                'network' => 'OFFLINE',
                'communication' => 'EXCEPTION',
                'raw' => null,
            ];
        }

        // Heuristics: different firmware uses different nodes.
        $deviceUp = $this->xmlValue($xml, 'deviceUpTime');
        $status = $this->xmlValue($xml, 'status') ?? $this->xmlValue($xml, 'Status');
        $online = null;

        if (is_string($status)) {
            $s = strtoupper(trim($status));
            if (in_array($s, ['OK', 'ACTIVE', 'UP', 'ONLINE'], true)) {
                $online = true;
            } elseif (in_array($s, ['ERROR', 'DOWN', 'OFFLINE'], true)) {
                $online = false;
            }
        }
        if ($online === null && $deviceUp !== null) {
            $online = true;
        }
        if ($online === null) {
            $online = true; // We got a response, assume reachable.
        }

        return [
            'reachable' => true,
            'network' => $online ? 'ONLINE' : 'OFFLINE',
            'communication' => $online ? 'OK' : 'EXCEPTION',
            'raw' => $this->xmlToArrayShallow($xml),
        ];
    }

    public function getVideoStatus(string $ip, string $channel = HIK_STREAM_CHANNEL): array
    {
        // Common endpoint: /ISAPI/Streaming/channels/{id}/status
        $xml = $this->getXml($ip, '/ISAPI/Streaming/channels/' . rawurlencode($channel) . '/status');
        if ($xml === null) {
            return ['ok' => false, 'video' => 'UNKNOWN', 'raw' => null];
        }

        // Common nodes: "video" / "videoCodecType" / "enabled" / "signal" etc.
        $signal = $this->xmlValue($xml, 'signal') ?? $this->xmlValue($xml, 'videoSignal') ?? $this->xmlValue($xml, 'VideoSignal');
        $enabled = $this->xmlValue($xml, 'enabled') ?? $this->xmlValue($xml, 'Enabled');
        $video = 'UNKNOWN';

        if (is_string($signal)) {
            $sig = strtoupper(trim($signal));
            if (in_array($sig, ['OK', 'NORMAL', 'TRUE', 'PRESENT'], true)) {
                $video = 'OK';
            } elseif (in_array($sig, ['LOSS', 'LOST', 'FALSE', 'ABSENT', 'VIDEOLOSS', 'VIDEO_LOSS'], true)) {
                $video = 'VIDEO_LOSS';
            }
        } elseif (is_string($enabled)) {
            $en = strtoupper(trim($enabled));
            if (in_array($en, ['TRUE', '1', 'YES'], true)) {
                $video = 'OK';
            }
        }

        return ['ok' => true, 'video' => $video, 'raw' => $this->xmlToArrayShallow($xml)];
    }

    /**
     * Some deployments want recording exceptions. Cameras often don't expose "recording status"
     * in a uniform way; NVRs do. We return UNKNOWN here unless you customize per device.
     */
    public function getRecordingStatus(string $ip): array
    {
        return ['ok' => true, 'recording' => 'UNKNOWN'];
    }

    // ----------------------- Internal helpers -----------------------

    private function getXml(string $ip, string $path): ?SimpleXMLElement
    {
        $url = $this->buildUrl($ip, $path);
        [$ok, $body] = $this->curlGet($url);
        if (!$ok || $body === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return null;
        }
        return $xml;
    }

    private function buildUrl(string $ip, string $path): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        $path = '/' . ltrim($path, '/');
        // Most ISAPI integrations on LAN use HTTP.
        return 'http://' . $ip . $path;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [false, ''];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERPWD => HIK_USERNAME . ':' . HIK_PASSWORD,
            CURLOPT_TIMEOUT => HIK_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => HIK_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADER => false,
        ]);

        if (!HIK_VERIFY_TLS) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return [false, ''];
        }

        // ISAPI can return 401 first if auth handshake issues; Digest should handle it.
        if ($http < 200 || $http >= 300) {
            return [false, is_string($body) ? $body : ''];
        }

        return [true, is_string($body) ? $body : ''];
    }

    private function xmlValue(SimpleXMLElement $xml, string $nodeName): ?string
    {
        $res = $xml->xpath('//*[local-name()="' . $nodeName . '"]');
        if (is_array($res) && isset($res[0])) {
            $v = trim((string)$res[0]);
            return $v === '' ? null : $v;
        }
        return null;
    }

    private function xmlToArrayShallow(SimpleXMLElement $xml): array
    {
        $out = [];
        foreach ($xml->children() as $k => $v) {
            $out[(string)$k] = trim((string)$v);
        }
        return $out;
    }
}

