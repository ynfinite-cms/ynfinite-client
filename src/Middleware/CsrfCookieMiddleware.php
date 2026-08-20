<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CsrfCookieMiddleware
 *
 * Sets a `ynfinite-csrf-protection` cookie containing a cryptographically random token.
 * The cookie is NOT HttpOnly so that JavaScript can read it for:
 *   - Proof-of-Work input:      SHA256(csrfToken + prevHash + ...)
 *   - Honeypot value:           yn_confirm_email must equal the cookie value
 *   - noBotProtection derivation: HMAC-SHA256(formId:nobot:csrfToken, apiKey)
 *
 * CSRF protection itself uses the double-submit cookie pattern: JS copies this
 * cookie value into the hidden _csrf_token form field; PHP validates they match.
 */
final class CsrfCookieMiddleware implements MiddlewareInterface
{
    public const  COOKIE_NAME   = 'ynfinite-csrf-protection';
    private const COOKIE_LENGTH = 32; // bytes → 64 hex chars
    private const COOKIE_TTL    = 86400; // 24 hours

    /**
     * Signed issue-timestamp cookie ("dwell" cookie): `<unix-ts>.<hmac-prefix>`.
     *
     * PHP requires a minimum time between a visitor's first request and a form
     * POST (see SendFormService::validateDwellTime). The timestamp lives in a
     * *separate* HttpOnly cookie instead of inside the CSRF cookie because the
     * CSRF value feeds the honeypot, the PoW digest, JS getCsrfToken() and the
     * index.php fast-path format check - changing its format would break all of
     * them. HttpOnly is fine here: JS never needs to read it.
     *
     * Cache-safe: like the CSRF cookie it is minted per visitor on their own
     * request (ensureCookie() covers static-cache hits), never embedded in HTML.
     * The value is only minted when absent or invalid - re-setting refreshes the
     * expiry but never the timestamp, so the dwell clock cannot be reset by
     * reloading.
     */
    public const  TS_COOKIE_NAME = 'ynfinite-form-ts';
    private const TS_SIG_LENGTH  = 32; // hex chars of the HMAC kept in the value

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // If the cookie is already present and is valid, reuse it.
        // Otherwise generate a fresh random token.
        $existing = $request->getCookieParams()[self::COOKIE_NAME] ?? '';
        if (!self::isValidToken($existing)) {
            $existing = bin2hex(random_bytes(self::COOKIE_LENGTH));
        }

        // Dwell cookie: keep a valid existing value (its timestamp must survive
        // refreshes), mint a fresh one otherwise.
        $existingTs = $request->getCookieParams()[self::TS_COOKIE_NAME] ?? '';
        $tsValue    = self::validateTsCookie($existingTs) !== null
            ? $existingTs
            : self::mintTsCookieValue(time());

        // Make the token available on the request for any downstream middleware
        $request = $request->withAttribute(self::COOKIE_NAME, $existing);

        $response = $handler->handle($request);

        // Always refresh the cookie TTL on every PHP-handled response.
        // Static-cached pages bypass this middleware — ensureCookie() below
        // is called from index.php before the cached page is echoed.
        $secure  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $expire  = time() + self::COOKIE_TTL;
        $flags   = '; Path=/; SameSite=Lax';
        if ($secure) {
            $flags .= '; Secure';
        }
        $response = $response->withAddedHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $existing . '; Expires=' . gmdate('D, d M Y H:i:s T', $expire) . $flags
        );
        $response = $response->withAddedHeader(
            'Set-Cookie',
            self::TS_COOKIE_NAME . '=' . $tsValue . '; Expires=' . gmdate('D, d M Y H:i:s T', $expire) . $flags . '; HttpOnly'
        );

        return $response;
    }

    /**
     * Sets (or refreshes) the CSRF cookie for static-cache hits in public/index.php,
     * which exit before Slim boots and therefore bypass process() entirely.
     */
    public static function ensureCookie(): void
    {
        $existing = $_COOKIE[self::COOKIE_NAME] ?? '';
        $token    = self::isValidToken($existing)
            ? $existing
            : bin2hex(random_bytes(self::COOKIE_LENGTH));

        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => $secure,
            'httponly' => false,
        ]);
        // Populate $_COOKIE immediately so any code further down in the same
        // request (e.g. logging) can read the token without a round-trip.
        $_COOKIE[self::COOKIE_NAME] = $token;

        // Dwell cookie - same rules as in process(): keep a valid value, only
        // mint when absent/invalid, refresh the expiry either way.
        $existingTs = $_COOKIE[self::TS_COOKIE_NAME] ?? '';
        $tsValue    = self::validateTsCookie($existingTs) !== null
            ? $existingTs
            : self::mintTsCookieValue(time());

        setcookie(self::TS_COOKIE_NAME, $tsValue, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => $secure,
            'httponly' => true,
        ]);
        $_COOKIE[self::TS_COOKIE_NAME] = $tsValue;
    }

    /**
     * Builds a signed dwell-cookie value for the given issue timestamp.
     */
    public static function mintTsCookieValue(int $ts): string
    {
        return $ts . '.' . substr(hash_hmac('sha256', (string) $ts, self::tsSecret()), 0, self::TS_SIG_LENGTH);
    }

    /**
     * Returns the issue timestamp of a valid dwell-cookie value, or null when
     * the value is malformed or the signature does not verify.
     */
    public static function validateTsCookie(string $value): ?int
    {
        if (!preg_match('/^(\d{9,12})\.([0-9a-f]{' . self::TS_SIG_LENGTH . '})$/', $value, $m)) {
            return null;
        }

        $expected = substr(hash_hmac('sha256', $m[1], self::tsSecret()), 0, self::TS_SIG_LENGTH);

        return hash_equals($expected, $m[2]) ? (int) $m[1] : null;
    }

    /**
     * HMAC key for the dwell cookie. Read from the environment (not the
     * container) because ensureCookie() runs before Slim boots on static-cache
     * hits; config/ynfinite.php sources auth.api_key from the same variable.
     */
    private static function tsSecret(): string
    {
        return (string) ($_ENV['YN_API_KEY'] ?? $_SERVER['YN_API_KEY'] ?? '');
    }

    private static function isValidToken(string $token): bool
    {
        return strlen($token) === self::COOKIE_LENGTH * 2
            && ctype_xdigit($token);
    }
}
