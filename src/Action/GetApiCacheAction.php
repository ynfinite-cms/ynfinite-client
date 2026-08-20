<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Utils\Cache\StaticCache;

/**
 * Generic API cache retrieval endpoint.
 * 
 * Usage: GET /yn-api/cache?key=my-unique-cache-key
 * 
 * Returns cached JSON data if available and within TTL,
 * or { "cached": false } if no valid cache exists.
 */
final class GetApiCacheAction
{
    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $cacheKey = $params['key'] ?? '';

        if (empty($cacheKey)) {
            $responseData = ['cached' => false];
            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Check cache
        $cachedData = StaticCache::getApiCache($cacheKey);

        if ($cachedData) {
            $response->getBody()->write($cachedData);
            return $response->withHeader('Content-Type', 'application/json');
        }

        // No valid cache found
        $responseData = [
            'cached' => false,
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
