<?php

namespace Padosoft\SuperCacheInvalidate\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchCompleted
{
    use Dispatchable;
    use SerializesModels;

    public string $batch_ID;

    public function __construct(string $batch_ID)
    {
        $this->batch_ID = $batch_ID;
    }
}
