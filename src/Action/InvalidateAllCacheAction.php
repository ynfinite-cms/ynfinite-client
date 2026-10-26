<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Repository\RequestCacheRepository;
use App\Plugin\RunsPlugins;

use SlimSession\Helper as SessionHelper;

final class InvalidateAllCacheAction
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

        $context = ['request' => $request, 'operation' => 'cache.invalidate_all'];
        $pluginResult = $this->runPluginHook('cache.invalidate_all.before', $context);
        $result = $pluginResult !== null ? $pluginResult : $this->repository->invalidateAllCache();
        $context['result'] = $result;
        $pluginResult = $this->runPluginHook('cache.invalidate_all.after', $context);
        if ($pluginResult !== null) {
            $result = $pluginResult;
        }
 
        $response->getBody()->write((string)json_encode(array("success" => $result)));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
        
    }
}
