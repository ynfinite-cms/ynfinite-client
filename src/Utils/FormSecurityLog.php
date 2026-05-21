<?php

namespace App\Utils;

use Psr\Http\Message\ServerRequestInterface;

class FormSecurityLog
{
    private const LOG_DIR_NAME   = 'bot_protection_logs';
    private const RETENTION_DAYS = 14;

    public static function write(ServerRequestInterface $request, string $reason): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $body   = $request->getParsedBody();
        $formId = is_array($body) ? ($body['formId'] ?? '') : '';
        $params   = $request->getServerParams();
        $ip       = $params['REMOTE_ADDR'] ?? '';
        $ua       = $params['HTTP_USER_AGENT'] ?? '';
        $url      = (string) $request->getUri();
        $referrer = $params['HTTP_REFERER'] ?? '';

        $data = [
            'ts'     => date('c'),
            'reason' => $reason,
            'formId' => $formId,
            'ip'     => $ip,
            'ua'     => $ua,
        ];

        if ($url !== '') {
            $data['url'] = $url;
        }
        if ($referrer !== '') {
            $data['referrer'] = $referrer;
        }

        $entry = json_encode($data, JSON_UNESCAPED_SLASHES);

        $written = @error_log($entry . PHP_EOL, 3, self::dailyLogPath());
        if ($written === false) {
            error_log('[ynfinite-form-security] ' . $entry);
        }
    }

    public static function cleanup(): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = (new \DateTimeImmutable())->modify('-' . self::RETENTION_DAYS . ' days');
        $files  = glob($dir . '/*.yn-botprotection.log') ?: [];

        foreach ($files as $file) {
            $basename = basename($file, '.yn-botprotection.log'); // e.g. "2026-05-06"
            $fileDate = \DateTimeImmutable::createFromFormat('Y-m-d', $basename);
            if ($fileDate !== false && $fileDate < $cutoff) {
                @unlink($file);
            }
        }
    }

    private static function logDir(): string
    {
        // FormSecurityLog lives at src/Utils/ — 2 levels up = project root
        return dirname(__DIR__, 2) . '/tmp/' . self::LOG_DIR_NAME;
    }

    private static function dailyLogPath(): string
    {
        return self::logDir() . '/' . date('Y-m-d') . '.yn-botprotection.log';
    }
}
