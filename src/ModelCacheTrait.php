<?php

namespace LightSpeak\ModelCache;

use Cache;

trait ModelCacheTrait
{

    protected $has_cache = false;

    /**
     * @param bool $useTransaction 是否使用事务，使用的话必须调用saveCache方法才可保存
     *
     * @return mixed|CacheModel
     */
    public function cache(bool $useTransaction = false)
    {
        $this->has_cache = true;
        return ModelCache::make($this, __CLASS__, $useTransaction);
    }

    /**
     * @param $key
     *
     * @return mixed
     */
    public function getAttributeFromArray($key)
    {
        if ($this->has_cache && isset($this->getAttributes()['id'])) {
            $cache_value = ModelCache::getStaticAttributeCache($key, __CLASS__, $this->getAttributes()['id']);
            if ($cache_value != null) {
                return $cache_value;
            }
        }
        return $this->getAttributes()[$key] ?? null;
    }

    public static function boot()
    {
        self::saved(function ($model) {
            // change version uuid to notify else instance when saved
            foreach ($model->changes as $key => $value) {
                Cache::delete(ModelCache::getStaticCacheKey(__CLASS__, $model->id, $key));
            }

        });
        static::bootTraits();
    }


}
