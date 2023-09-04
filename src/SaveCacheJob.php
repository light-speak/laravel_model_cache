<?php

namespace LightSpeak\ModelCache;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Psr\SimpleCache\InvalidArgumentException;

class SaveCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new job instance.
     *
     */
    public function __construct(
        public string $className,
        public int    $id,
    )
    {
        $this->queue = 'model-cache';
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
        $lock     = Cache::lock("save_model_lock:$modelKey", 10);
        try {
            $lock->block(10, function () use ($modelKey, $lock) {
                try {
                    $model = (new $this->className)
                        ->query()
                        ->where('id', $this->id)
                        ->first();
                    if (!$model) {
                        Cache::forget($modelKey);
                        return;
                    }
//                    throw new Exception("炸一下试试");
                    $changed = false;
                    foreach ($model->getAttributes() as $key => $v) {
                        if (!is_numeric($model->{$key})) {
                            continue; // 非数字类直接跳过
                        }
                        $cache_key = ModelCache::getStaticCacheKey($this->className, $this->id, $key);
                        if (Cache::has($cache_key)) {
                            $value      = Cache::pull($cache_key);
                            $cacheValue = (int)bcdiv($value, 1000);
//                    info("缓存Key: $cache_key ,值: $cacheValue 模型值: {$model->{$key}}");
                            if ($model->{$key} != $cacheValue) {
                                $model->{$key} = $cacheValue;
                                $changed       = true;
                            }
                        }
                    }
                    if ($changed) {
//                Cache::put("$modelKey:cache_version", Str::uuid()); // update version
                        $model->save();
                    }
                    // save 之后不会出现问题
                    Cache::forget("$modelKey:short");                     // delete short
                    Cache::forget("$modelKey:long");                      // delete long
                } catch (Exception $exception) {
                    $this->release(now()->addSeconds(30));
                } finally {
                    $lock->release();
                }
            });
        } catch (LockTimeoutException $exception) {
            $this->release(now()->addSeconds(30));
        }
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("model_cache|$this->className|$this->id"))->releaseAfter(60)];
    }
}
