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
    protected        $guarded        = [];
    protected        $changes        = [];
    protected        $tmpAttributes  = [];
    protected        $className;
    protected        $instance;
    protected        $useTransaction;
    protected static $modelListeners = [];
    protected        $currentVersions;

    public function __construct($model, string $className, bool $useTransaction = false)
    {
        $this->instance = $model;
        $this->className = $className;
        $this->useTransaction = $useTransaction;
        $this->getAttributesCache($model->attributes);
        $this->updateVersion();

        parent::__construct($this->attributes);
    }


    public function updateVersion()
    {
        if (!array_key_exists($this->getCacheKey(), ModelCache::$versions)) {
            $versionUUID = Str::uuid();
            ModelCache::$versions[$this->getCacheKey()] = $versionUUID;
            $this->currentVersions = $versionUUID;
        } else {
            $this->currentVersions = ModelCache::$versions[$this->getCacheKey()];
        }
    }


    public function getCacheKey(string $key = ''): string
    {
        return "{$this->className}:{$this->attributes['id']}:$key";
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

    public function getAttributeCache(string $key)
    {

        if (array_key_exists($key, $this->tmpAttributes)) {
            return $this->tmpAttributes[$key];
        }
        return Cache::rememberForever($this->getCacheKey($key), function () use ($key) {
            SaveCacheJob::dispatch($this->className, $this->attributes['id'])->delay(now()->addMinutes(10));
            try {
                $value = $this->getAttribute($key);
            } catch (Exception $e) {
                throw new Exception("你这称(指字段名称: $key)有问题啊");
            }
            throw_if($value == null, new Exception('你这称(指字段名称)有问题啊'));
            if ($this->currentVersions != ModelCache::$versions[$this->getCacheKey()]) {
                $value = (new $this->className)->query()->where('id', $this->attributes['id'])->first()->{$key};
            }
            return $value;
        });
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
            Cache::put($this->getCacheKey($key),
                       array_key_exists($key, $this->tmpAttributes)
                           ? $this->tmpAttributes[$key]
                           : $this->attributes[$key]
            );
        }
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
     */
    public function __set($key, $value)
    {
        self::bindListener($this->className);
        $this->getAttributeCache($key);

        $key = (string)Str::of($key)->replace('_cache', '');
        $this->changes[] = $key;
        if (!$this->useTransaction) {
            Cache::put($this->getCacheKey($key), $value);
        } else {
            $this->tmpAttributes[$key] = $value;
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


