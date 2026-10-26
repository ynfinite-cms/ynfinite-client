<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Repository\RequestCacheRepository;
use App\Plugin\RunsPlugins;

use SlimSession\Helper as SessionHelper;

final class InvalidateCacheAction
{
    use RunsPlugins;

    public $repository;

    public function __construct(RequestCacheRepository $repository) {
        $this->repository = $repository;
    }

    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
    

        $entityBody = file_get_contents('php://input');
        $parsedBody = json_decode($entityBody);

        $data = (array)$request->getServerParams();
        $query = array();
        parse_str($data["QUERY_STRING"], $query);

        $cacheKeys = $parsedBody->cacheKey;
        if($query && $query["cacheKey"]) {
            $cacheKeys = $query["cacheKey"]; 
        }

        $context = ['request' => $request, 'cacheKeys' => $cacheKeys, 'operation' => 'cache.invalidate'];
        $pluginResult = $this->runPluginHook('cache.invalidate.before', $context);
        $result = $pluginResult !== null
            ? $pluginResult
            : ($cacheKeys ? $this->repository->invalidateCache($cacheKeys) : false);
        $context['result'] = $result;
        $pluginResult = $this->runPluginHook('cache.invalidate.after', $context);
        if ($pluginResult !== null) {
            $result = $pluginResult;
        }

        $response->getBody()->write((string)json_encode(array("success" => $result)));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
        
    }
}
