<?php

namespace LightSpeak\ModelCache;

use Illuminate\Support\Facades\Cache;

class ModelCache
{

    /**
     * @param mixed $model
     * @param string $className
     * @param bool $useTransaction
     *
     * @return CacheModel
     */
    public static function make(mixed $model, string $className, bool $useTransaction = false): CacheModel
    {
        return new CacheModel($model, $className, $useTransaction);
    }

    /**
     * @param string $key
     * @param string $className
     * @param        $id
     *
     * @return float|int|null
     */
    public static function getStaticAttributeCache(string $key, string $className, $id): float|int|null
    {
        $cacheKey = self::getStaticCacheKey($className, $id, $key);
        if (Cache::has($cacheKey)) {
            return (float)bcdiv(Cache::get($cacheKey), 1000, 2);
        }

        return null;
    }

    /**
     * Get the unique key, if the key is an empty string or null, it can represent a model, otherwise it represents a field
     *
     * @param string $key
     * @param string $className
     * @param        $id
     *
     * @return string
     */
    public static function getStaticCacheKey(string $className, $id, string $key = ''): string
    {
        return "$className:$id:$key";
    }
}
