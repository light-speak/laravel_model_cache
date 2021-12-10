<?php

namespace LightSpeak\ModelCache;

use Cache;
use Exception;
use Illuminate\Database\Eloquent\Model;
use phpDocumentor\Reflection\Types\Self_;
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

    /**
     * @throws InvalidArgumentException
     */
    public function __construct($model, string $className, bool $useTransaction = false)
    {
        $this->instance = $model;
        $this->className = $className;
        $this->useTransaction = $useTransaction;
        $this->getAttributesCache($model->attributes);
        self::bindListener($this->className);
        parent::__construct($this->attributes);
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
                if (isset($model->changes) && is_array($model->changes)) {
                    foreach ($model->changes as $key => $value) {
                        Cache::delete(self::getStaticCacheKey($key, $className, $model->id));
                    }
                }
            });
        }
    }

    public static function getStaticCacheKey(string $key, string $className, $id): string
    {
        return "{$className}:{$id}:$key";
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
            SaveCacheJob::dispatch($this->className, $this->attributes['id'])->delay(now()->addSeconds(5));
            try {
                $value = $this->{$key};
            } catch (Exception $e) {
                throw new Exception('你这称(指字段名称)有问题啊');
            }
            throw_if($value == null, new Exception('你这称(指字段名称)有问题啊'));
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
                       array_key_exists($key, $this->tmpAttributes) ? $this->tmpAttributes[$key] : $this->attributes[$key]);
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
        if (Str::endsWith($key, '_cache')) {
            $initial_key = Str::of($key)->replace('_cache', '');
            return $this->getAttributeCache($initial_key);
        } else {
            return $this->instance->{$key};
        }
    }

    /**
     * @param $key
     * @param $value
     *
     */
    public function __set($key, $value)
    {
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

}


