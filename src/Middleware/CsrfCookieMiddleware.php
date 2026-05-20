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
 * Sets a `_yncsrf` cookie containing a cryptographically random token.
 * The cookie is NOT HttpOnly so that JavaScript can read it for:
 *   - Proof-of-Work input:      SHA256(csrfToken + prevHash + ...)
 *   - Honeypot value:           yn_confirm_email must equal the cookie value
 *   - noBotProtection derivation: HMAC-SHA256(formId:nobot:csrfToken, apiKey)
 *
 * CSRF protection itself is handled by a session-based synchronizer token
 * (_yn_csrf_token Twig function + validateCsrfToken in SendFormService).
 */
final class CsrfCookieMiddleware implements MiddlewareInterface
{
    private const COOKIE_NAME   = '_yncsrf';
    private const COOKIE_LENGTH = 32; // bytes → 64 hex chars
    private const COOKIE_TTL    = 3600; // 1 hour

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // If the cookie is already present and is valid, reuse it.
        // Otherwise generate a fresh random token.
        $existing = $request->getCookieParams()[self::COOKIE_NAME] ?? '';
        if (!$this->isValidToken($existing)) {
            $existing = bin2hex(random_bytes(self::COOKIE_LENGTH));
        }

        // Make the token available on the request for any downstream middleware
        $request = $request->withAttribute(self::COOKIE_NAME, $existing);

        $response = $handler->handle($request);

        // Only set the cookie when not already present (avoids overwriting a
        // valid cookie on every request and saves header overhead)
        $currentCookies = $request->getCookieParams();
        if (empty($currentCookies[self::COOKIE_NAME])) {
            $secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $expire   = time() + self::COOKIE_TTL;
            $flags    = '; Path=/; SameSite=Lax';
            if ($secure) {
                $flags .= '; Secure';
            }
            $response = $response->withAddedHeader(
                'Set-Cookie',
                self::COOKIE_NAME . '=' . $existing . '; Expires=' . gmdate('D, d M Y H:i:s T', $expire) . $flags
            );
        }

        return $response;
    }

    private function isValidToken(string $token): bool
    {
        return strlen($token) === self::COOKIE_LENGTH * 2
            && ctype_xdigit($token);
    }
}
