<?php

namespace LightSpeak\ModelCache;

use Illuminate\Database\Eloquent\Model;

trait ModelCacheTrait
{
    /**
     * @param bool $useTransaction 是否使用事务，使用的话必须调用saveCache方法才可保存
     *
     * @return CacheModel|ModelCacheTrait|mixed
     */
    public function cache(bool $useTransaction = false)
    {
        return ModelCache::make($this, __CLASS__, $useTransaction);
    }

}
