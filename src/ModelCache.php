<?php

namespace LightSpeak\ModelCache;

use Illuminate\Database\Eloquent\Model;

/**
 * @template T
 */
class ModelCache
{
    /**
     * @param T      $model
     * @param string $className      类名
     * @param bool   $useTransaction 是否开启事务
     *
     * @return T
     */
    public static function make($model, string $className, bool $useTransaction = false)
    {
        return new CacheModel($model, $className, $useTransaction);
    }
}
