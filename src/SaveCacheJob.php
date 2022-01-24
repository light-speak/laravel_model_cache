<?php

namespace LightSpeak\ModelCache;

use Cache;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Psr\SimpleCache\InvalidArgumentException;
use Str;

class SaveCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected $className;
    protected $id;

    /**
     * Create a new job instance.
     *
     */
    public function __construct(string $className, $id)
    {
        $this->queue = 'model-cache';
        $this->className = $className;
        $this->id = $id;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function handle()
    {
        $xxx = ModelCache::getStaticCacheKey($this->className, $this->id);
        $lock = Cache::lock("save_model_lock:$xxx");
//        info($xxx . 'job等待锁');

        $lock->get(function () use ($xxx) {
            $model = (new $this->className)
                ->query()
                ->where('id', $this->id)
                ->first();
            if (!$model) {
                Cache::delete($xxx);
                return;
            }
//            info($xxx . 'job拿到锁， 在操作');
            foreach ($model->getAttributes() as $key => $v) {
                $cache_key = ModelCache::getStaticCacheKey($this->className, $this->id, $key);
//                info("正在处理$key");
                if (Cache::has($cache_key)) {
                    $value = Cache::pull($cache_key);
                    $model->{$key} = $value / 100;
//                    info("值为：{$model->$key}");
                }
            }
            $model->save();
            Cache::put("$xxx:cache_version", Str::uuid()); // 更新版本
//            info($xxx . 'job释放锁');
        });
        Cache::pull(ModelCache::getStaticCacheKey($this->className, $this->id));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("model_cache|$this->className|$this->id"))->releaseAfter(60)];
    }
}
