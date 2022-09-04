<?php

namespace LightSpeak\ModelCache;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;

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
        if ($this->has_cache && isset($this->getAttributes()['id'])) {
            $cache_value = ModelCache::getStaticAttributeCache($key, __CLASS__, $this->getAttributes()['id']);
            if ($cache_value != null) {
                return $cache_value;
            }
        }
        return $this->getAttributes()[$key] ?? null;
    }


    /**
     * Use a unique ID to search, the ID field is used by default, which can be modified
     * Return the model with the Cache property
     *
     * 使用一个唯一ID进行搜索，默认使用ID字段，可修改
     * 返回带有Cache属性的模型
     *
     *
     * @param mixed $uniqueId
     * @param string $fieldName
     * @return self|CacheModel
     */
    public static function findCache(mixed $uniqueId, string $fieldName = 'id'): self|CacheModel
    {
        return (new self())->newQuery()
            ->where($fieldName, $uniqueId)
            ->first()->cache();

    }
}
