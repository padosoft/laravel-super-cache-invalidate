<?php

namespace Padosoft\SuperCacheInvalidate\Events;

class BatchCompletedEvent
{
    use \Illuminate\Foundation\Events\Dispatchable;
    use \Illuminate\Queue\SerializesModels;

    public string $batch_ID;
    public int $shard;

    /**
     * Identifiers (keys and tags) marked as processed in this batch, so listeners
     * can avoid querying the events table by batch_ID.
     *
     * @var string[]
     */
    public array $identifiers;

    public ?string $connection_name;

    public function __construct(string $batch_ID, int $shard, array $identifiers = [], ?string $connection_name = null)
    {
        $this->batch_ID = $batch_ID;
        $this->shard = $shard;
        $this->identifiers = $identifiers;
        $this->connection_name = $connection_name;
    }
}
