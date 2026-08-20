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
                $result = array("type" => 'error', 'message' => $formResponse["message"] ?? null);
                break;
        }

        $response->getBody()->write((string)json_encode($result));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }
}