<?php

namespace Padosoft\SuperCacheInvalidate\Events;

class BatchCompletedEvent
{
    use \Illuminate\Foundation\Events\Dispatchable, \Illuminate\Queue\SerializesModels;

    public string $batch_ID;
    public int $shard;

    public function __construct(string $batch_ID, int $shard)
    {
        $this->batch_ID = $batch_ID;
        $this->shard = $shard;
    }
}
