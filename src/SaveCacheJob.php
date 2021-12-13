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
            ->where('id', $this->id)
            ->first();
        if (!$model) {
            Cache::delete(ModelCache::getStaticCacheKey($this->className, $this->id));
            return;
        }
        foreach ($model->getAttributes() as $key => $v) {
            $cache_key = ModelCache::getStaticCacheKey($key, $this->className, $this->id);
            if (Cache::has($cache_key)) {
                $value = Cache::pull($cache_key);
                $model->{$key} = $value;
            }
        }
        $model->save();
        Cache::delete(ModelCache::getStaticCacheKey($this->className, $this->id));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("model_cache|$this->className|$this->id"))->releaseAfter(60)];
    }
}
