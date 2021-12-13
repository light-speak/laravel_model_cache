<?php

namespace LightSpeak\ModelCache;

use Cache;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Psr\SimpleCache\InvalidArgumentException;
use Str;
use Throwable;

class CacheModel extends Model
{
    protected $guarded = [];
    protected $changes = [];
    /**
     * 只保存和原值的差值，原值取Attributes里的值
     * 当开启事务后，需手动保存，才能应用更改
     * 保存时以差值进行保存，不参考Attribute原值
     *
     * @var array
     */
    protected $tmpAttributes = [];
    protected $useTransaction;

    protected        $className;
    protected        $instance;
    protected static $modelListeners = [];

    /**
     * 用于校验当前代理的实例是否是最新版本
     * 只有原模型修改了，这个版本才会不对
     * @var string
     */
    protected $currentVersion;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct($model, string $className, bool $useTransaction = false)
    {
        $this->instance = $model;
        $this->className = $className;
        $this->useTransaction = $useTransaction;
        $this->updateVersion();
        $this->getAttributesCache($model->attributes);

        parent::__construct($this->attributes);
    }


    /**
     * @throws InvalidArgumentException
     */
    public function updateVersion()
    {
        if (!array_key_exists($this->getCacheKey(), ModelCache::$versions)) {
            foreach ($this->instance->attributes as $key => $value) {
                Cache::delete($this->getCacheKey($key));
            }
            $versionUUID = Str::uuid();
            ModelCache::$versions[$this->getCacheKey()] = $versionUUID;
            $this->currentVersion = $versionUUID;
        } else {
            $this->currentVersion = ModelCache::$versions[$this->getCacheKey()];
        }
    }


    public function getCacheKey(string $key = ''): string
    {
        return "{$this->className}:{$this->instance->attributes['id']}:$key";
    }

    protected function getAttributesCache(array $attributes)
    {
        $this->attributes = $attributes;
        foreach ($attributes as $key => $value) {
            if (Cache::has($this->getCacheKey($key))) {
                $this->attributes[$key] = $this->getAttributeCache($key);
            }
        }
        $this->instance->attributes = $this->attributes;
    }

    /**
     * @throws Exception
     */
    public function getAttributeCache(string $key)
    {
        try {
            $value = $this->attributes[$key];
        } catch (Exception $e) {
            throw new Exception("你这称(指字段名称: $key)有问题啊");
        }
        if ($key == 'id') {
            // ID 存个啥， ID不存
            return $value;
        }
        if (!is_numeric($value) && $this->useTransaction) {
            throw new Exception("十五斤，三十块，你这称(指字段名称: $key)有问题啊，吸铁石（如果开启事务 又不是数值型，那不扯犊子了吗）");
        }
        $value = Cache::remember($this->getCacheKey($key), 600, function () use ($key) {
            if (!Cache::has($this->getCacheKey())) {
                SaveCacheJob::dispatch($this->className, $this->attributes['id'])->delay(now()->addMinutes(5));
                Cache::put($this->getCacheKey(), 'wait');
            }
            $value = $this->attributes[$key];

            if ($this->currentVersion != ModelCache::$versions[$this->getCacheKey()]) {
                $newest = (new $this->className)->query()->where('id', $this->attributes['id'])->first();
                $this->getAttributesCache($newest->attributes);
                $this->currentVersion = ModelCache::$versions[$this->getCacheKey()];
                $value = $newest->{$key};
            }
            return $value ?? 0;
        });

        // 如果当前是在事务模式且修改过值, 则返回两个的合
        if ($this->useTransaction && array_key_exists($key, $this->tmpAttributes)) {
            return $this->tmpAttributes[$key] + $value;
        } else {
            return $value;
        }
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function save(array $options = [])
    {
        foreach ($this->changes as $key) {
            Cache::delete($this->getCacheKey($key));
        }
        $this->instance->save();
    }

    public function saveCache()
    {
        foreach ($this->changes as $key) {
            Cache::put($this->getCacheKey($key), $this->getAttributeCache($key));
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
        $key = Str::of($key)->replace('_cache', '');
        return $this->getAttributeCache($key);
    }

    /**
     * @param $key
     * @param $value
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function __set($key, $value)
    {
        self::bindListener($this->className);
        $key = (string)Str::of($key)->replace('_cache', '');
        // 先读一遍值，防止emo
        $this->getAttributeCache($key);

        $this->changes[] = $key;
        if (!$this->useTransaction) {
            Cache::put($this->getCacheKey($key), $value);
        } else {
            $this->tmpAttributes[$key] = $value - $this->attributes[$key]; // 存差值
        }
        $this->instance->{$key} = $value;
        $this->attributes = $this->instance->attributes;
    }

    /**
     * @param string $className
     *
     * @throws InvalidArgumentException
     */
    protected static function bindListener(string $className)
    {
        if (!array_key_exists($className, self::$modelListeners)) {
            self::$modelListeners[$className] = true;
            (new $className)->saved(function ($model) use ($className) {
                // change version uuid to notify else instance when saved
                ModelCache::$versions[ModelCache::getStaticCacheKey($className, $model->id)] = Str::uuid();
                if (isset($model->changes) && is_array($model->changes)) {
                    foreach ($model->changes as $key => $value) {
                        Cache::delete(ModelCache::getStaticCacheKey($className, $model->id, $key));
                    }
                }
            });
        }
    }

}


