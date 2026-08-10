<?php

namespace App\Domain\Request\Repository;

use App\Utils\Cache\StaticCache;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Query\Builder;

class RequestCacheRepository {
    private $connection;

    public function __construct(Manager $connection) {
        $this->connection = $connection;
    }

    public function createCache($cacheKey, $filename) {
        $values = [
            'cache_key' => $cacheKey,
            'filename' => $filename,
            "type" => "page",
            "created_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ];

        $cache = $this->connection->table('static_cache')->where("cache_key", "=", $cacheKey)->where("filename", "=", $filename)->first();

        if($cache && $cache->id) {
            return (int) $this->connection->table('static_cache')->where("cache_key", "=", $cacheKey)->where("filename", "=", $filename)->update($values);
        }
        else {
            return (int) $this->connection->table('static_cache')->insert($values);
        }

        
    }

    public function invalidateAllCache() {
        $cachedPages = $this->connection->table("static_cache")->get();

        // Clear page/request caches from DB
        $deleted = $this->deleteCache($cachedPages);

        // Also clear all file-based API caches
        $deleted += StaticCache::invalidateAllApiCache();

        return $deleted;
    }

    public function invalidateCache($cacheKeys) {
        if(!is_array($cacheKeys)) $cacheKeys = array($cacheKeys);
        $cachedPages = $this->connection->table('static_cache')->whereIn('cache_key', $cacheKeys)->get();

        return $this->deleteCache($cachedPages);
    }

    private function deleteCache($cachedPages) {
        $deletedItems = 0;

        foreach($cachedPages as $cachedPage) {
            $pageDeleted = StaticCache::invalidateCache($cachedPage->filename);

            if($pageDeleted) {
                $success = $this->connection->table('static_cache')->where('id', '=', $cachedPage->id)->delete();
                if($success) {
                    $deletedItems++;
                }
                
            }            
        }
        
        return $deletedItems;
    }
}
