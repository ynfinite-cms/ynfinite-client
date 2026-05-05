<?php

namespace App\Utils\Cache;

class StaticCache
{
    const BASIC_PATH = "/../tmp/static_pages/";
    const DEFAULT_API_CACHE_TTL = 86400; // 24 hours

    public static function createCacheKey($type, $pageOnly = false) {
        $filename = null;
        switch($type) {
            case "PAGE": 
                $filename = StaticCache::createPageCacheKey($pageOnly);
                break;
            case "REQUEST":
                $filename = StaticCache::createRequestCacheKey($pageOnly);
                break;
        }
        return $filename;
    }

    /**
     * Create a cache key for an API call using a custom identifier.
     * @param string $cacheKey A unique identifier for this API call
     * @return string The generated cache key
     */
    public static function createApiCacheKey($cacheKey) {
        return "API_CACHE_" . md5($cacheKey);
    }

    public static function createPageCacheKey($pageOnly = false)
    {
        $requestUrlParts = explode("?", $_SERVER["REQUEST_URI"]);

        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$requestUrlParts[0]";

        $key = "PAGE_".md5($url);

        if($pageOnly) {
            return $key;
        }

        if(sizeof($requestUrlParts) > 1 && $requestUrlParts[1]) {
            $key .= "_".md5($requestUrlParts[1]);
        }
    
        if ($_COOKIE["ynfinite-cookies"] ?? false) {
            $ynCookie = json_decode($_COOKIE["ynfinite-cookies"]);
            $activeScripts = implode("-", $ynCookie->activeScripts);
            if ($activeScripts) $key .= "_" . md5($activeScripts);
        }

        return $key;
    }

    public static function createRequestCacheKey($pageOnly = false)
    {
        $referer = $_SERVER["HTTP_REFERER"] ?? $_SERVER["REQUEST_URI"] ?? "/";
        $requestUrlParts = explode("?", $referer);

        $url = $requestUrlParts[0];

        $key = "REQUEST_".md5($url);

        if($pageOnly) {
            return $key;
        }

        if($requestUrlParts[1]) {
            $key .= "_".md5($requestUrlParts[1]);
        }
    
        if ($_COOKIE["ynfinite-cookies"]) {
            $ynCookie = json_decode($_COOKIE["ynfinite-cookies"]);
            $activeScripts = implode("-", $ynCookie->activeScripts);
            if ($activeScripts) $key .= "_" . md5($activeScripts);
        }

        return $key;
    }

    public static function getCachePath($type)
    {
        $dirname = StaticCache::createCacheKey($type);        
        
        $name = 'loggedout';
        if (isset($_COOKIE['leadGroupIds'])) {
            $leadGroupIds = $_COOKIE['leadGroupIds'];
            if ($leadGroupIds == 'empty') { 
                $name = 'loggedin';
            } else {
                $name = $leadGroupIds;
            }
        } elseif (isset($_COOKIE['loginToken'])) {
            $name = 'loggedin';
        }
        
        $filename = "$dirname/$name.html";
        $path = getcwd() . StaticCache::BASIC_PATH . $filename;

        return $path;
    }

    /**
     * Get cache path for a generic API cache entry.
     * @param string $cacheKey A unique identifier for this API call
     * @return string The full file path for the cache file
     */
    public static function getApiCachePath($cacheKey) {
        $dirname = StaticCache::createApiCacheKey($cacheKey);
        $filename = "$dirname/data.json";
        return getcwd() . StaticCache::BASIC_PATH . $filename;
    }

    /**
     * Get the meta file path for an API cache entry (stores TTL info).
     * @param string $cacheKey A unique identifier for this API call
     * @return string The full file path for the meta file
     */
    public static function getApiCacheMetaPath($cacheKey) {
        $dirname = StaticCache::createApiCacheKey($cacheKey);
        $filename = "$dirname/meta.json";
        return getcwd() . StaticCache::BASIC_PATH . $filename;
    }

    /**
     * Store data in the generic API cache.
     * @param string $cacheKey A unique identifier for this API call
     * @param string $content JSON string to cache
     * @param int|null $ttl Custom TTL in seconds. Null = use global default.
     * @return string The cache file path
     */
    public static function createApiCache($cacheKey, $content, $ttl = null) {
        $path = StaticCache::getApiCachePath($cacheKey);

        $dirname = dirname($path);
        if (!file_exists($dirname)) {
            mkdir($dirname, 0777, true);
        }

        file_put_contents($path, $content);

        // Store meta with the TTL used for this cache entry
        $meta = [
            'ttl' => $ttl ?? self::DEFAULT_API_CACHE_TTL,
            'created_at' => time()
        ];
        $metaPath = StaticCache::getApiCacheMetaPath($cacheKey);
        file_put_contents($metaPath, json_encode($meta));

        return $path;
    }

    /**
     * Resolve the effective TTL for a cached API entry.
     * Priority: per-key meta TTL → 24h default.
     * @param string $cacheKey A unique identifier for this API call
     * @return int TTL in seconds
     */
    public static function resolveApiCacheTTL($cacheKey) {
        $metaPath = StaticCache::getApiCacheMetaPath($cacheKey);
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (isset($meta['ttl']) && is_numeric($meta['ttl'])) {
                return (int) $meta['ttl'];
            }
        }
        return self::DEFAULT_API_CACHE_TTL;
    }

    /**
     * Retrieve data from the generic API cache.
     * Returns the cached content if valid (within TTL), or false if expired/missing.
     * @param string $cacheKey A unique identifier for this API call
     * @return string|false Cached JSON string or false
     */
    public static function getApiCache($cacheKey) {
        $path = StaticCache::getApiCachePath($cacheKey);

        if (file_exists($path)) {
            $mtime = filemtime($path);
            $age = time() - $mtime;
            $ttl = StaticCache::resolveApiCacheTTL($cacheKey);

            if ($age > $ttl) {
                // Cache expired — clean up data + meta
                StaticCache::invalidateCache($path);
                $metaPath = StaticCache::getApiCacheMetaPath($cacheKey);
                if (file_exists($metaPath)) {
                    StaticCache::invalidateCache($metaPath);
                }
                return false;
            }

            return file_get_contents($path);
        }

        return false;
    }

    public static function createCache($type, $content)
    {
        $path = StaticCache::getCachePath($type);

        $dirname = dirname($path);
        if (!file_exists($dirname)) {
            mkdir($dirname, 0777, true);
        }
        
        file_put_contents($path, $content);
        $etag = filemtime($path);
        header('ETag: ' . $etag);

        return $path;
    }

    public static function getCache($type)
    {
        $path = StaticCache::getCachePath($type);

        if(file_exists($path)) {
            $etag = filemtime($path);
            
            header('Cache-Control: max-age=15');
            
            header('ETag: ' . $etag);
            
            if(isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
                if($_SERVER['HTTP_IF_NONE_MATCH'] == $etag) {
                    header('HTTP/1.1 304 Not Modified', true, 304);
                    exit();
                }
            }
            return file_get_contents($path);
        }
        return false;
    }

    public static function invalidateCache($path)
    {
        $result = false;
        $realPath = realpath($path);

        if(file_exists($realPath)) {
            $result = unlink($realPath);
        }

        $dir = dirname($realPath);
        if(is_dir($dir) && count(scandir($dir)) == 2){
            rmdir($dir);
        }
        
        return $result;
    }

    /**
     * Remove all API cache entries (API_CACHE_* directories).
     * @return int Number of deleted cache entries
     */
    public static function invalidateAllApiCache()
    {
        $basePath = getcwd() . self::BASIC_PATH;
        $realBase = realpath($basePath);
        $deleted = 0;

        if (!$realBase || !is_dir($realBase)) {
            return $deleted;
        }

        foreach (glob($realBase . '/API_CACHE_*', GLOB_ONLYDIR) as $dir) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);
            $deleted++;
        }

        return $deleted;
    }
}
