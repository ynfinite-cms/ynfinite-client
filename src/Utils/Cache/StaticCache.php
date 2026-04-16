<?php

namespace App\Utils\Cache;

class StaticCache
{
    const BASIC_PATH = "/../tmp/static_pages/";

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
}
