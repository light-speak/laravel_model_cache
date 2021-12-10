<?php

namespace LightSpeak\ModelCache;

use Illuminate\Database\Eloquent\Model;
use Psr\SimpleCache\InvalidArgumentException;

trait ModelCacheTrait
{
    /**
     * @param bool $useTransaction 是否使用事务，使用的话必须调用saveCache方法才可保存
     *
     * @return CacheModel|ModelCacheTrait|mixed
     * @throws InvalidArgumentException
     */
    public function cache(bool $useTransaction = false)
    {
        return ModelCache::make($this, __CLASS__, $useTransaction);
    }


    /**
     * @param $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        $cache_value = ModelCache::getStaticAttributeCache($key, __CLASS__, $this->getAttribute('id'));
        if ($cache_value != null) {
            return $cache_value;
        }
        return $this->getAttribute($key);
    }
}
