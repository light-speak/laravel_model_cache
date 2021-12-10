<?php

namespace LightSpeak\ModelCache;

use Cache;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * @template T
 */
class ModelCache
{

    public static $versions = [];

    /**
     * @param T      $model
     * @param string $className      类名
     * @param bool   $useTransaction 是否开启事务
     *
     * @return CacheModel
     */
    public static function make($model, string $className, bool $useTransaction = false): CacheModel
    {
        return new CacheModel($model, $className, $useTransaction);
    }


    /**
     * @param string $key
     * @param string $className
     * @param        $id
     *
     * @return string
     */
    public static function getStaticCacheKey(string $className, $id, string $key = ''): string
    {
        return "{$className}:{$id}:$key";
    }


    /**
     * @param string $key
     * @param string $className
     * @param        $id
     *
     * @return mixed|null
     */
    public static function getStaticAttributeCache(string $key, string $className, $id)
    {
        $cacheKey = self::getStaticCacheKey($className, $id, $key);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        } else {
            return null;
        }
    }
}
