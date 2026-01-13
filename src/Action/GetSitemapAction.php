<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Domain\Request\Service\GetSitemapService;
use App\Domain\Request\Service\RenderSitemapService;
use App\Domain\Request\Service\RequestPageService;
use App\Domain\Request\Service\RenderPageService;

use SlimSession\Helper as SessionHelper;

final class GetSitemapAction
{

    public $getSitemapService;
    public $renderSitemapService;
    public $requestPageService;
    public $renderPageService;

    public function __construct(
        GetSitemapService $getSitemapService, 
        RenderSitemapService $renderSitemapService,
        RequestPageService $requestPageService,
        RenderPageService $renderPageService
    ) {
        $this->getSitemapService = $getSitemapService;
        $this->renderSitemapService = $renderSitemapService;
        $this->requestPageService = $requestPageService;
        $this->renderPageService = $renderPageService;
    }
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface 
    {
        $sitemap = $this->getSitemapService->getSitemap($request);

        // Check if sitemap exists
        if (!$sitemap) {
            return $this->handleNotFound($request, $response);
        }

        $renderedSitemap = trim($this->renderSitemapService->render($sitemap));

        // Clear previous output/headers to ensure clean XML
        $response = $response->withBody(new \Slim\Psr7\Stream(fopen('php://temp', 'r+')));
        $response->getBody()->write($renderedSitemap);

        return $response
            ->withHeader('Content-Type', 'text/xml; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withStatus(200);
    }

    private function handleNotFound(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(404);
    }
}