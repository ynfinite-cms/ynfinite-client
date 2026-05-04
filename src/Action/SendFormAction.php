<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use Psr\Container\ContainerInterface;
use App\Domain\Request\Service\SendFormService;
use App\Domain\Request\Service\RenderPageService;
use App\Domain\Request\Service\CacheService;

use App\Utils\Cache\StaticCache;

use SlimSession\Helper as SessionHelper;

final class SendFormAction
{
    public $sendFormService;
    public $renderPageService;
    public $cacheService;

    public function __construct(SendFormService $sendFormService, RenderPageService $renderPageService, CacheService $cacheService) {
        $this->sendFormService = $sendFormService;
        $this->renderPageService = $renderPageService;
        $this->cacheService = $cacheService;
    }

    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        if ($this->isRateLimited($request)) {
            $response->getBody()->write((string)json_encode([
                'type'    => 'error',
                'message' => 'Too many requests. Please wait a moment before trying again.',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(429);
        }

        $formResponse = $this->sendFormService->sendForm($request, $_POST);
        $result = array();

        switch ($formResponse["type"]) {
            case "page": 
            case "404": 
                $data = $formResponse["data"];
                $templates = $formResponse["templates"];
                $result = array(
                    "type" => "page",
                    "rendered" => null,
                    "listingForm" => $data["listingForm"] ?? null,
                    "pagination" => $data["pagination"] ?? null
                );
        
                if(array_key_exists("activeEvent", $data) && $data["activeEvent"] && $data["activeEvent"]["asyncTemplate"]) {
                    $rendered = $this->renderPageService->renderTemplate($templates, $data, $data["activeEvent"]["asyncTemplate"]);
                    $result["rendered"] = $rendered;
                }
                break;
            case "redirect": 
                $result = array("type" => 'redirect', "statusCode" => $formResponse["statusCode"], "url" => $formResponse["url"]);

                break;
            case "error": 
                $result = array("type" => 'error', 'message' => $formResponse["message"]);
                break;
        }

        $response->getBody()->write((string)json_encode($result));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    /**
     * Sliding-window rate limiter stored in the PHP session.
     * Allows at most RATE_LIMIT_MAX requests within RATE_LIMIT_WINDOW seconds
     * per session (= per browser session / IP behind the session cookie).
     */
    private function isRateLimited(ServerRequestInterface $request): bool
    {
        $rateLimitMax    = 10;   // max form submissions
        $rateLimitWindow = 60;   // per this many seconds

        $now = time();
        $key = 'yn_form_rate_limit';

        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        // Remove timestamps outside the current window
        $_SESSION[$key] = array_values(
            array_filter($_SESSION[$key], fn(int $ts) => ($now - $ts) < $rateLimitWindow)
        );

        if (count($_SESSION[$key]) >= $rateLimitMax) {
            return true;
        }

        $_SESSION[$key][] = $now;
        return false;
    }
}