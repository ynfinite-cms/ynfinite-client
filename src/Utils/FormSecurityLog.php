<?php

namespace App\Utils;

use Psr\Http\Message\ServerRequestInterface;

class FormSecurityLog
{
    private const LOG_DIR_NAME   = 'bot_protection_logs';
    private const RETENTION_DAYS = 14;

    public static function write(ServerRequestInterface $request, string $reason, array $context = []): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $body   = $request->getParsedBody();
        $formId = is_array($body) ? ($body['formId'] ?? '') : '';
        $params   = $request->getServerParams();
        $ip       = (string) ($params['HTTP_X_FORWARDED_FOR'] ?? $params['REMOTE_ADDR'] ?? '');
        // X-Forwarded-For can be a chain - the client is the first entry.
        $ip       = trim(explode(',', $ip)[0]);
        $ua       = (string) ($params['HTTP_USER_AGENT'] ?? '');
        $url      = (string) $request->getUri();
        $referrer = $params['HTTP_REFERER'] ?? '';

        $data = [
            'ts'     => date('c'),
            'reason' => $reason,
            'formId' => $formId,
            // Pseudonymous, daily-rotating client id instead of the raw IP.
            'client' => self::clientId($ip),
            'ua'     => mb_substr($ua, 0, 255),
        ];

        if ($url !== '') {
            $data['url'] = $url;
        }
        if ($referrer !== '') {
            $data['referrer'] = $referrer;
        }

        // Tuning data, attached to every line (log-only, never enforced):
        // client-reported score/codes plus header-sanity signals. Real-world
        // distributions decide future thresholds and whether header checks can
        // be promoted to enforcement.
        $data += self::telemetry($body, $params);

        foreach ($context as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $data[$key] = is_string($value) ? mb_substr($value, 0, 64) : $value;
            }
        }

        $entry = json_encode($data, JSON_UNESCAPED_SLASHES);

        $written = @error_log($entry . PHP_EOL, 3, self::dailyLogPath());
        if ($written === false) {
            error_log('[ynfinite-form-security] ' . $entry);
        }
    }

    /**
     * Sanitized, log-only telemetry: the client-reported bot score/codes and
     * header-sanity flags. Everything here is attacker-controlled input, so it
     * is validated hard and never used for enforcement decisions.
     */
    private static function telemetry($body, array $params): array
    {
        $out = [];

        if (is_array($body)) {
            $score = $body['botScore'] ?? null;
            if (is_numeric($score)) {
                $out['botScore'] = max(0, min(999, (int) $score));
            }

            $codes = $body['botCodes'] ?? null;
            if (is_string($codes) && $codes !== '' && preg_match('/^[0-9,]{1,64}$/', $codes)) {
                $out['botCodes'] = $codes;
            }
        }

        $secFetchSite = (string) ($params['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($secFetchSite !== '') {
            $out['secFetchSite'] = mb_substr($secFetchSite, 0, 20);
        }
        $secFetchMode = (string) ($params['HTTP_SEC_FETCH_MODE'] ?? '');
        if ($secFetchMode !== '') {
            $out['secFetchMode'] = mb_substr($secFetchMode, 0, 20);
        }
        $out['acceptLanguage'] = ($params['HTTP_ACCEPT_LANGUAGE'] ?? '') !== '';

        // Dwell age in seconds, when the signed issue-timestamp cookie is valid.
        $tsCookie = (string) ($_COOKIE[\App\Middleware\CsrfCookieMiddleware::TS_COOKIE_NAME] ?? '');
        if ($tsCookie !== '') {
            $issuedAt = \App\Middleware\CsrfCookieMiddleware::validateTsCookie($tsCookie);
            if ($issuedAt !== null) {
                $out['dwellAge'] = max(0, time() - $issuedAt);
            }
        }

        return $out;
    }

    /**
     * Pseudonymous client identifier.
     *
     * A raw IP address is personal data under the GDPR no matter how benign the
     * purpose, so we never persist one. Instead we store a truncated keyed hash
     * of the IP salted with the current date:
     *
     *   - within one day the same client always yields the same id, so "the same
     *     bot hit this form 400 times" is still visible in the log,
     *   - the id changes every midnight, so no long-term profile can be built,
     *   - it is keyed with the install's API key, so it cannot be reversed by
     *     hashing the whole IPv4 space without knowing that key.
     *
     * Combined with the 14 day retention this keeps the log usable for abuse
     * defence (Art. 6(1)(f) legitimate interest) without storing identifiers.
     */
    private static function clientId(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        $secret = (string) ($_ENV['YN_API_KEY'] ?? $_SERVER['YN_API_KEY'] ?? '');

        return substr(hash_hmac('sha256', $ip, $secret . '|' . date('Y-m-d')), 0, 16);
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
