<?php

namespace LightSpeak\ModelCache;

use Cache;
use Eloquent;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Str;
use Throwable;

/**
 * @mixin Eloquent
 */
class CacheModel extends Model
{
    /**
     * 只保存和原值的差值，原值取Attributes里的值
     * 当开启事务后，需手动保存，才能应用更改
     * 保存时以差值进行保存，不参考Attribute原值
     *
     * @var array
     */
    protected $tmpAttributes = [];
    protected $useTransaction;

    protected $className;
    protected $instance;

    /**
     * 用于校验当前代理的实例是否是最新版本
     * 只有原模型修改了，这个版本才会不对
     * @var string
     */
    protected $currentVersion;

    public function __construct($model, string $className, bool $useTransaction = false)
    {
        $this->instance = $model;
        $this->className = $className;
        $this->useTransaction = $useTransaction;
        $this->updateVersion();

        parent::__construct();
    }


    public function updateVersion()
    {
        if (!Cache::has("{$this->getCacheKey()}:cache_version")) {
            $versionUUID = Str::uuid();
            Cache::put("{$this->getCacheKey()}:cache_version", $versionUUID);
            $this->currentVersion = $versionUUID;
        } else {
            $this->currentVersion = Cache::get("{$this->getCacheKey()}:cache_version");
        }
    }

    public function getCacheKey(string $key = ''): string
    {
        return "$this->className:{$this->instance->id}:$key";
    }

    public function getAttributeCache(string $key): int
    {
        // 又不需要存，又不存在key的情况下就这样返回就行
        if ($key == 'id') {
            return $this->instance->{$key};
        }
        $lock = Cache::lock("save_model_lock:{$this->getCacheKey()}");
//        info("{$this->getCacheKey($key)}等待锁");
        return $lock->block(10, function () use ($key) {
//            info("{$this->getCacheKey($key)}得到锁");
            $value = Cache::rememberForever($this->getCacheKey($key), function () use ($key) {
                if (!Cache::has($this->getCacheKey())) {
                    SaveCacheJob::dispatch($this->className, $this->instance->id)->delay(now()->addSeconds(15));
                    Cache::put($this->getCacheKey(), 'wait');
                }
                $value = $this->instance->{$key};

                if (Cache::get("{$this->getCacheKey()}:cache_version") != $this->currentVersion) {
                    $newest = (new $this->className)
                        ->query()
                        ->findOrFail($this->instance->id);
                    $value = $newest->{$key};
                    $this->currentVersion = Cache::get("{$this->getCacheKey()}:cache_version");
                }
                return intval(($value ?? 0) * 100); // 百倍存放，不要小数
            });
//            if ($key == 'group_performance_buy_again') {
//                info($key);
//                info($this->getCacheKey($key));
//                info(Cache::get($this->getCacheKey($key)));
//            }

            // 如果当前是在事务模式且修改过值, 则返回两个的合
            if ($this->useTransaction && array_key_exists($key, $this->tmpAttributes)) {
                return $this->tmpAttributes[$key] + $value;
            } else {
                return $value;
            }
        });
    }

    /**
     * @throws Exception
     */
    public function save(array $options = [])
    {
        throw new Exception('这玩意不给save了');
    }

    /**
     * @throws Exception
     */
    public function saveCache()
    {
        foreach ($this->tmpAttributes as $key => $value) {
            $this->getAttributeCache($key);  // 避免缓存里没有值
            $v = Cache::increment($this->getCacheKey($key), $value);
            info("key: {$this->getCacheKey($key)} value : {$value}, 结果: {$v}");
        }
        $this->tmpAttributes = [];
    }

    /**
     * @param $key
     *
     * @return mixed
     * @throws Throwable
     */
    public function __get($key)
    {
        if (!is_numeric($this->instance->$key) || $key == 'id') {
            return $this->instance->$key;
        }
        $v = $this->getAttributeCache($key);
//        info("{$this->getCacheKey($key)} 数据值: $v");
        return (float)$v / 100;
    }

    /**
     * @param $key
     * @param $value
     *
     * @throws Exception
     */
    public function __set($key, $value)
    {
        throw new Exception('这玩意不给set了');
    }

    /**
     * @throws Exception
     */
    public function incrementByCache(string $key, $value)
    {
        $v = $this->getAttributeCache($key);
        $realValue = intval(round($value * 100));
        if (!$this->useTransaction) {
            $v = Cache::increment($this->getCacheKey($key), $realValue);
//            info("{$this->getCacheKey($key)} : $v =>  增加数量 $realValue => 结果 $v");
        } else {
            $this->tmpAttributes[$key] = ($this->tmpAttributes[$key] ?? 0) + $realValue;
        }
    }

    /**
     * @throws Exception
     */
    public function decrementByCache(string $key, $value)
    {
        $v = $this->getAttributeCache($key);

        $realValue = intval(round($value * 100));
        if (!$this->useTransaction) {
            $v = Cache::decrement($this->getCacheKey($key), $realValue);
//            info("{$this->getCacheKey($key)} : $v => 减少数量 $realValue => 结果 $v");
        } else {
            $this->tmpAttributes[$key] = ($this->tmpAttributes[$key] ?? 0) - $realValue;
//            info("{$this->getCacheKey($key)} :减少数量：{$realValue} 最新差值： {$this->tmpAttributes[$key]}");
        }
    }

}


