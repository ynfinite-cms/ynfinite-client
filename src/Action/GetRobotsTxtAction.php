<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Service\GetRobotsTxtService;
use App\Plugin\RunsPlugins;

use SlimSession\Helper as SessionHelper;

final class GetRobotsTxtAction
{
    use RunsPlugins;
    public $getRobotsTxtService;

    public function __construct(GetRobotsTxtService $getRobotsTxtService) {
        $this->getRobotsTxtService = $getRobotsTxtService;
    }

    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        
        $context = ['request' => $request, 'operation' => 'robots.generate'];
        $pluginResult = $this->runPluginHook('robots.before_generate', $context);
        $robots = $pluginResult !== null ? $pluginResult : $this->getRobotsTxtService->getRobotsTxt($request);
        $context['result'] = $robots;
        $pluginResult = $this->runPluginHook('robots.after_generate', $context);
        if ($pluginResult !== null) {
            $robots = $pluginResult;
        }

        // Build the HTTP response
        $response->getBody()->write($robots);

        return $response
            ->withHeader('Content-Type','text/plain')
            ->withStatus(200);
    }
}
