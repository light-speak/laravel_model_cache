<?php

namespace LightSpeak\ModelCache;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @mixin Model
 */
class CacheModel extends Model
{

    /**
     * If useTransaction = True
     *
     * Only save the difference from the original value, the original value takes the value in Attributes
     * When the transaction is opened, it needs to be saved manually before the changes can be applied
     * Save with the difference value when saving, do not refer to the original value of Attribute
     *
     * 只保存和原值的差值，原值取Attributes里的值
     * 当开启事务后，需手动保存，才能应用更改
     * 保存时以差值进行保存，不参考Attribute原值
     *
     * @var array
     */
    protected array $tmpAttributes = [];
    protected bool  $useTransaction;

    protected string $className;
    /** @var self $instance */
    protected mixed $instance;

    public function __construct(mixed $model, string $className, bool $useTransaction = false)
    {
        $this->instance       = $model;
        $this->className      = $className;
        $this->useTransaction = $useTransaction;

        parent::__construct();
    }

    /**
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->useTransaction;
    }

    public function save(array $options = []): void
    {
    }

    /**
     * If using a transaction, use this method to save
     *
     * 如果使用事务，需要用该方法进行保存
     *
     * @throws Exception
     */
    public function saveCache(): void
    {
        foreach ($this->tmpAttributes as $key => $value) {
            $this->getAttributeCache($key);  // Avoid having no value in the cache and update the cached version
            Cache::increment($this->getCacheKey($key), $value);
        }
        $this->tmpAttributes = [];
        $modelKey            = $this->getCacheKey();
        if (!Cache::has("$modelKey:short")) {
            SaveCacheJob::dispatch($this->className, $this->instance->id)->delay(now()->addSeconds(15));
            Cache::put("$modelKey:short", 'wait');
        }
    }

    /**
     * 使用一个KEY: ModelKey:long VALUE:wait 的值来判断是否存在长时间缓存
     * 使用一个KEY: ModelKey:short VALUE:wait 的值来判断是否存在短时间缓存
     * 存在值修改会同时删除这两个值
     * 不修改数值的情况下，存在时间为3到24小时
     * 修改数值会在15秒后进行数据更新
     *
     * @param string $key
     * @return int
     * @throws Exception
     */
    public function getAttributeCache(string $key): int
    {
        if ($key === 'id') {
            return $this->instance->{$key};
        }
        $modelKey = $this->getCacheKey();
        try {
            $lock = Cache::lock("save_model_lock:$modelKey", 10);
            return $lock->block(10, function () use ($key, $modelKey) {
                $value = Cache::rememberForever($this->getCacheKey($key), function () use ($key, $modelKey) {
                    $value = $this->instance->refresh()->{$key};
                    return bcmul($value, 1000); // Store in a thousand times the value
                });

                // 如果当前是在事务模式且修改过值, 则返回两个的合
                if ($this->useTransaction && array_key_exists($key, $this->tmpAttributes)) {
                    return $this->tmpAttributes[$key] + $value;
                }

                return $value;
            });
        } catch (Exception $e) {
            throw new Exception('服务器繁忙');
        }
    }

    /**
     * Get the unique key, if the key is an empty string or null, it can represent a model, otherwise it represents a field
     *
     * 获取一个唯一Key，$key为空或者空字符时，用于代表Model，$key为字段名称则代表字段
     *
     * @param string $key
     * @return string
     */
    public function getCacheKey(string $key = ''): string
    {
        return ModelCache::getStaticCacheKey($this->className, $this->instance->id, $key);
    }


    /**
     * @param $key
     *
     * @return float
     * @throws Exception
     */
    public function __get($key)
    {
        if (!is_numeric($this->instance->$key) || $key === 'id') {
            return $this->instance->$key;
        }
        $v = $this->getAttributeCache($key);
        return (float)bcdiv($v, 1000, 2);
    }

    public function __set($key, $value)
    {
    }

    /**
     * @param string $key
     * @param $value
     * @param int|null $max
     * @return void
     * @throws Exception
     */
    public function incrementByCache(string $key, $value, ?int $max = null): void
    {
        $realValue = (int)bcmul($value, 1000);

        if (!$this->useTransaction) {
            if (!is_null($max)) {
                $lock = Cache::lock("{$this->getCacheKey($key)}:limit:lock", 3);
                $lock->block(3, function () use ($key, $realValue, $max) {
                    $currentValue = $this->getAttributeCache($key);
                    $resultValue  = $currentValue + $realValue;
                    info("计算的时候发现当前值： $currentValue 如果加完就是 {$resultValue}");
                    if ((int)bcmul($max, 1000) < $resultValue) {
                        throw new Exception("计算结果预期为：{$resultValue} (1000倍), 达到设定的数值上限：{$max}");
                    }
                    Cache::increment($this->getCacheKey($key), $realValue);
                });
            } else {
                $this->getAttributeCache($key);
                Cache::increment($this->getCacheKey($key), $realValue);
            }
            $this->setShortLockJob();
        } else {
            if (!is_null($max)) {
                throw new Exception("不允许这么使用");
            }
            $this->tmpAttributes[$key] = ($this->tmpAttributes[$key] ?? 0) + $realValue;
        }
    }

    /**
     * @return void
     */
    public function setShortLockJob(): void
    {
        $modelKey = $this->getCacheKey();
        $lock     = Cache::lock("$modelKey:short:lock", 5);
        $lock->block(3, function () use ($modelKey) {
            if (!Cache::has("$modelKey:short")) {
                Cache::put("$modelKey:short", 'wait');
                SaveCacheJob::dispatch($this->className, $this->instance->id)->delay(now()->addSeconds(30));
            }
        });
    }

    /**
     * @param string $key
     * @param $value
     * @param int|null $min
     * @return void
     * @throws Exception
     */
    public function decrementByCache(string $key, $value, ?int $min = null): void
    {
        $realValue = (int)bcmul($value, 1000);

        if (!$this->useTransaction) {
            if (!is_null($min)) {
                $lock = Cache::lock("{$this->getCacheKey($key)}:limit:lock", 3);
                $lock->block(3, function () use ($key, $realValue, $min) {
                    $currentValue = $this->getAttributeCache($key);
                    $resultValue  = $currentValue - $realValue;
                    info("计算的时候发现当前值： $currentValue 如果减完就是 {$resultValue}");
                    if ((int)bcmul($min, 1000) > $resultValue) {
                        throw new Exception("计算结果预期为：{$resultValue} (1000倍), 达到设定的数值下限：{$min}");
                    }
                    Cache::decrement($this->getCacheKey($key), $realValue);
                });
            } else {
                $this->getAttributeCache($key);
                Cache::decrement($this->getCacheKey($key), $realValue);
            }
            $this->setShortLockJob();
        } else {
            if (!is_null($min)) {
                throw new Exception("不允许这么使用");
            }
            $this->tmpAttributes[$key] = ($this->tmpAttributes[$key] ?? 0) - $realValue;
        }
    }

    /**
     * @param bool $useTransaction Whether to use transactions, if true, you must call the saveCache() method to save
     *
     * @return self
     */
    public function cache(bool $useTransaction = false): self
    {
        if (!$this->useTransaction && $useTransaction) {
            $this->useTransaction = $useTransaction;
        }
        return $this;
    }

    /**
     * @return void
     */
    public function flushCache(): void
    {
        SaveCacheJob::dispatchSync($this->className, $this->instance->id);
    }
}


