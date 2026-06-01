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
    private const COOKIE_NAME   = 'ynfinite-csrf-protection';
    private const COOKIE_LENGTH = 32; // bytes → 64 hex chars
    private const COOKIE_TTL    = 86400; // 24 hours

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
    }

    private static function isValidToken(string $token): bool
    {
        return strlen($token) === self::COOKIE_LENGTH * 2
            && ctype_xdigit($token);
    }
}
