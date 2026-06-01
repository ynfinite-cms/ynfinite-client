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
        header('Content-type: application/json');
        echo $cachedData;
        exit;
    }
}

(require __DIR__ . '/../config/bootstrap.php')->run();