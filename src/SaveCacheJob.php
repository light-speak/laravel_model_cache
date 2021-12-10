<?php

namespace LightSpeak\ModelCache;

use Cache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Psr\SimpleCache\InvalidArgumentException;

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
     */
    public function handle()
    {
        $model = (new $this->className)
            ->query()
            ->lockForUpdate()
            ->where('id', $this->id)
            ->first();
        foreach ($model->getAttributes() as $key => $v) {
            $cache_key = CacheModel::getStaticCacheKey($key, $this->className, $this->id);
            if (Cache::has($cache_key)) {
                $value = Cache::get($cache_key);
                $model->{$key} = $value;
                Cache::delete($cache_key);// 这里先删了，不影响其他地方获取
            }
        }
        $model->save();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("model_cache|$this->className|$this->id"))->releaseAfter(60)];
    }
}
