<?php

namespace App\Domain\Request\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use SlimSession\Helper as SessionHelper;
use App\Domain\Request\Service\RequestService;

final class GetSitemapService extends RequestService
{
    private $repository;
    public $settings;

    public function __construct(SessionHelper $session, ContainerInterface $container) {
        parent::__construct($session, $container);    
    }

    public function getSitemap(ServerRequestInterface $request)
    {
        $jsonResponse = true;
        $path = $request->getUri()->getPath();
        $lang = $request->getAttribute('lang'); // Get the language from the route

        $postBody = $this->getBody($request);
        $result = $this->request(trim($path), $this->settings["services"]["sitemap"], $postBody, $jsonResponse);

        if ($result['statusCode'] !== 200) {
            return null;
        }

        $data = $result["body"];
        
        // 1. Check if backend returned sitemap index format (multi-language index)
        if (is_array($data) && isset($data['type']) && $data['type'] === 'index') {
            return [
                'isIndex' => true,
                'entries' => $data['entries'] ?? []
            ];
        }
        
        // 2. Normalize single-language format (array of URL entries)
        $sitemapData = [
            'isIndex' => false,
            'entries' => is_array($data) ? $data : []
        ];

        // 3. VALIDATION LOGIC: Prevent /{lang}/sitemap.xml on single-language domains
        if ($lang && !empty($sitemapData['entries'])) {
            $firstEntry = reset($sitemapData['entries']);
            
            /**
             * If a language prefix is used in the URL ($lang), but the 
             * sitemap entries have no 'alternatives' (translations), 
             * then the language route is invalid for this domain.
             */
            $hasAlternatives = isset($firstEntry['alternatives']) && 
                               is_array($firstEntry['alternatives']) && 
                               count($firstEntry['alternatives']) > 0;

            if (!$hasAlternatives) {
                return null; // This will trigger the 404 in your Action
            }
        }

        return $sitemapData;
    }
}