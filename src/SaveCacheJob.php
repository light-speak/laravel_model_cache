<?php

namespace LightSpeak\ModelCache;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\HasCacheLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\SimpleCache\InvalidArgumentException;

class SaveCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, HasCacheLock;

    protected string $className;
    protected int    $id;

    /**
     * Create a new job instance.
     *
     */
    public function __construct(string $className, $id)
    {
        $this->queue     = 'model-cache';
        $this->className = $className;
        $this->id        = $id;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function handle(): void
    {
        $modelKey = ModelCache::getStaticCacheKey($this->className, $this->id);
        $lock     = $this->lock("save_model_lock:$modelKey", 10, $modelKey);

        $lock->get(function () use ($modelKey) {
            $model = (new $this->className)
                ->query()
                ->where('id', $this->id)
                ->first();
            if (!$model) {
                Cache::forget($modelKey);
                return;
            }
            $changed = false;
            foreach ($model->getAttributes() as $key => $v) {
                $cache_key = ModelCache::getStaticCacheKey($this->className, $this->id, $key);
                if (Cache::has($cache_key)) {
                    $value      = Cache::pull($cache_key);
                    $cacheValue = (int)bcdiv($value, 1000);
                    if ($model->{$key} != $cacheValue) {
                        $model->{$key} = $cacheValue;
                        $changed       = true;
                    }
                }
            }
            if ($changed) {
                Cache::put("$modelKey:cache_version", Str::uuid()); // update version
                $model->save();
            }
            Cache::pull("$modelKey:short");                     // delete short
            Cache::pull("$modelKey:long");                      // delete long
        });
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("model_cache|$this->className|$this->id"))->releaseAfter(60)];
    }
}
