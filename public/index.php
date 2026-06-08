<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Utils\Cache\StaticCache;
use App\Middleware\CsrfCookieMiddleware;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

if($_ENV['STATIC_PAGES'] !== "false" && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cachedPage = StaticCache::getCache("PAGE");
    if ($cachedPage) {
        // Set CSRF cookie via PHP even on cache hits — no JS involvement needed.
        CsrfCookieMiddleware::ensureCookie();
        echo $cachedPage;
        exit;
    } 
}

if($_ENV['STATIC_REQUESTS'] !== "false" && $_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER["REQUEST_URI"] === "/yn-form/send" && $_POST["method"] !== "post") {
    $cachedData = StaticCache::getCache("REQUEST");
    if ($cachedData) {
        // Validate CSRF before serving the cached response — this early exit
        // bypasses Slim entirely, so without this check a bot can get a 200
        // response for any filter-form POST without running any security layer.
        $submittedToken = $_POST['_csrf_token'] ?? '';
        $cookieToken    = $_COOKIE[CsrfCookieMiddleware::COOKIE_NAME] ?? '';
        $validFormat    = static fn(string $t): bool => strlen($t) === 64 && ctype_xdigit($t);
        if ($submittedToken === '' || $cookieToken === ''
            || !$validFormat($submittedToken)
            || !$validFormat($cookieToken)
            || !hash_equals($cookieToken, $submittedToken)
        ) {
            header('Content-type: application/json');
            echo json_encode(['type' => 'error', 'message' => 'Invalid or missing CSRF token. Please reload the page and try again.']);
            exit;
        }
        header('Content-type: application/json');
        echo $cachedData;
        exit;
    }
}

(require __DIR__ . '/../config/bootstrap.php')->run();