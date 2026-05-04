<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Utils\Cache\StaticCache;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

// Start the session before any cache bypass so the honeypot token is always
// present in the user's session. Without this, users who receive a cached page
// never get a yn_honeypot_token, causing every form submission to fail.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['yn_honeypot_token'])) {
    $_SESSION['yn_honeypot_token'] = bin2hex(random_bytes(16));
}

if($_ENV['STATIC_PAGES'] !== "false" && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cachedPage = StaticCache::getCache("PAGE");
    if ($cachedPage) {
        // The cached HTML contains a stale data-yn-honeypot-expected value from
        // whichever session originally rendered the page. Replace it with the
        // current user's session token so the honeypot check passes on submit.
        $cachedPage = preg_replace(
            '/data-yn-honeypot-expected="[^"]*"/',
            'data-yn-honeypot-expected="' . htmlspecialchars($_SESSION['yn_honeypot_token'], ENT_QUOTES, 'UTF-8') . '"',
            $cachedPage
        );
        echo $cachedPage;
        exit;
    } 
}

if($_ENV['STATIC_REQUESTS'] !== "false" && $_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER["REQUEST_URI"] === "/yn-form/send" && $_POST["method"] !== "post") {
    $cachedData = StaticCache::getCache("REQUEST");
    if ($cachedData) {
        header('Content-type: application/json');
        echo $cachedData;
        exit;
    }
}

(require __DIR__ . '/../config/bootstrap.php')->run();