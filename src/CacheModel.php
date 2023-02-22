<?php

namespace LightSpeak\ModelCache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Exception;
use Illuminate\Support\Str;
use Throwable;

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
    protected mixed  $instance;


    /**
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->useTransaction;
    }

    public function __construct(mixed $model, string $className, bool $useTransaction = false)
    {
        $this->instance       = $model;
        $this->className      = $className;
        $this->useTransaction = $useTransaction;

        parent::__construct();
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
     * 使用一个KEY: ModelKey:long VALUE:wait 的值来判断是否存在长时间缓存
     * 使用一个KEY: ModelKey:short VALUE:wait 的值来判断是否存在短时间缓存
     * 存在值修改会同时删除这两个值
     * 不修改数值的情况下，存在时间为3到24小时
     * 修改数值会在15秒后进行数据更新
     *
     * @param string $key
     * @return int
     */
    public function getAttributeCache(string $key): int
    {
        if ($key === 'id') {
            return $this->instance->{$key};
        }
        $modelKey = $this->getCacheKey();
        return Cache::lock("save_model_lock:$modelKey")->block(10, function () use ($key, $modelKey) {
            $value = Cache::rememberForever($this->getCacheKey($key), function () use ($key, $modelKey) {
                if (!Cache::has("$modelKey:long")) {
                    SaveCacheJob::dispatch($this->className, $this->instance->id)->delay(now()->addHours(random_int(3, 24)));
                    Cache::put("$modelKey:long", 'wait');
                }
                $value = $this->instance->{$key};
                return (($value ?? 0) * 1000); // Store in a thousand times the value
            });

            // 如果当前是在事务模式且修改过值, 则返回两个的合
            if ($this->useTransaction && array_key_exists($key, $this->tmpAttributes)) {
                return $this->tmpAttributes[$key] + $value;
            }

            return $value;
        });
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
    public function saveCache()
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
     * @param $key
     *
     * @return float
     * @throws Throwable
     */
    public function __get($key)
    {
        if (!is_numeric($this->instance->$key) || $key === 'id') {
            return $this->instance->$key;
        }
        $v = $this->getAttributeCache($key);
        return (float)$v / 1000;
    }

    public function __set($key, $value)
    {
    }

    /**
     * @param string $key
     * @param $value
     * @return void
     */
    public function incrementByCache(string $key, $value): void
    {
        $this->getAttributeCache($key);
        $realValue = (int)round($value * 1000);
        if (!$this->useTransaction) {
            Cache::increment($this->getCacheKey($key), $realValue);
            $modelKey = $this->getCacheKey();
            if (!Cache::has("$modelKey:short")) {
                SaveCacheJob::dispatch($this->className, $this->instance->id)->delay(now()->addSeconds(15));
                Cache::put("$modelKey:short", 'wait');
            }
        } else {
            $this->tmpAttributes[$key] = ($this->tmpAttributes[$key] ?? 0) + $realValue;
        }
    }

    /**
     * @param string $key
     * @param $value
     * @return void
     */
    public function decrementByCache(string $key, $value): void
    {
        $this->getAttributeCache($key);

        $realValue = (int)round($value * 1000);
        if (!$this->useTransaction) {
            Cache::decrement($this->getCacheKey($key), $realValue);
            $modelKey = $this->getCacheKey();
            if (!Cache::has("$modelKey:short")) {
                SaveCacheJob::dispatch($this->className, $this->instance->id)->delay(now()->addSeconds(15));
                Cache::put("$modelKey:short", 'wait');
            }
        } else {
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

}


