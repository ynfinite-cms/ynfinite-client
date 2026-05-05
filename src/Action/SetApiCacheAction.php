<?php

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Utils\Cache\StaticCache;

/**
 * Generic API cache storage endpoint.
 * 
 * Usage: POST /yn-api/cache
 * Body: { "key": "my-unique-cache-key", "data": {...}, "ttl": 3600 }
 * 
 * Stores the provided data in the file-based cache under the given key.
 * TTL is optional; defaults to StaticCache::DEFAULT_API_CACHE_TTL (24h).
 */
final class SetApiCacheAction
{
    public function __invoke(
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface {
        $body = json_decode($request->getBody()->getContents(), true);
        
        if (!$body) {
            $body = $request->getParsedBody() ?? [];
        }

        $cacheKey = $body['key'] ?? '';
        $data = $body['data'] ?? null;
        $ttl = isset($body['ttl']) && is_numeric($body['ttl']) ? (int) $body['ttl'] : null;

        if (empty($cacheKey) || $data === null) {
            $responseData = ['success' => false, 'cached' => false];
            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Store the data in the cache
        $cacheContent = json_encode($data);
        StaticCache::createApiCache($cacheKey, $cacheContent, $ttl);

        $effectiveTTL = $ttl ?? StaticCache::DEFAULT_API_CACHE_TTL;

        $responseData = [
            'success' => true,
            'message' => 'Data cached successfully',
            'key' => $cacheKey,
            'ttl' => $effectiveTTL
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
