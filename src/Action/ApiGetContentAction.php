<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Service\ApiGetContentService;
use App\Domain\Request\Service\CacheService;
use SlimSession\Helper as SessionHelper;

use App\Exception\YnfiniteException;
use App\Plugin\RunsPlugins;

final class ApiGetContentAction
{
    use RunsPlugins;
    private ApiGetContentService $apiService;

    public function __construct(ApiGetContentService $apiService) {
        $this->apiService = $apiService;
    }

    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $context = ['request' => $request, 'postData' => $_POST, 'operation' => 'content.fetch'];
            $pluginResult = $this->runPluginHook('content.before_fetch', $context);
            if ($pluginResult !== null) {
                $apiResponse = $pluginResult;
            } else {
                $apiResponse = $this->apiService->getContent($request, $_POST);
            }
            $context['result'] = $apiResponse;
            $pluginResult = $this->runPluginHook('content.after_fetch', $context);
            if ($pluginResult !== null) {
                $apiResponse = $pluginResult;
            }
            $response->getBody()->write(json_encode($apiResponse));
        }
        catch (YnfiniteException $e) {
            return $this->handleException($e, $response);
        }
        
        return $response;
    }

    private function handleException($e, $response) {
        $error = [];
        $error["message"] = $e->getMessage();
        $error["code"] = $e->getCode();
        $error["trace"] = $e->getTraceAsString();

        $response = $response->withStatus($error["code"]);

        return $response;
    }
}
