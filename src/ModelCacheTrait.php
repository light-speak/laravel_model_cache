<?php

namespace LightSpeak\ModelCache;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @mixin Model
 */
trait ModelCacheTrait
{
    use HasAttributes;

    protected bool $has_cache = false;

    /**
     * @param bool $useTransaction Whether to use transactions, if true, you must call the saveCache() method to save
     *
     * @return self|CacheModel
     */
    public function cache(bool $useTransaction = false): self|CacheModel
    {
        $this->has_cache = true;
        return ModelCache::make($this, __CLASS__, $useTransaction);
    }

    /**
     * @param $key
     *
     * @return mixed
     */
    public function getAttributeFromArray($key): mixed
    {
        if ($key === 'id' || !isset($this->getAttributes()['id'])) {
            return $this->getAttributes()[$key] ?? null;
        }

        $modelKey = ModelCache::getStaticCacheKey(__CLASS__, $this->getAttributes()['id']);
        if ($this->has_cache || Cache::has("$modelKey:short") || Cache::has("$modelKey:long")) {
            $this->has_cache = true;
        }
        if ($this->has_cache) {
            $cache_value = ModelCache::getStaticAttributeCache($key, __CLASS__, $this->getAttributes()['id']);
            if ($cache_value !== null) {
                return $cache_value;
            }
        }
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * @return bool
     */
    public function inTransaction(): bool
    {
        return false;
    }
}
