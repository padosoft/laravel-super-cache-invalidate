<?php

namespace Padosoft\SuperCacheInvalidate\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SuperCacheInvalidationEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The cache identifier type ('key' or 'tag').
     *
     * @var string
     */
    public string $type;

    /**
     * The cache key or tag that was invalidated.
     *
     * @var string
     */
    public string $identifier;

    /**
     * The reason for invalidation.
     *
     * @var string|null
     */
    public ?string $reason;

    /**
     * Create a new event instance.
     *
     * @param string      $type       Identifier type ('key' or 'tag')
     * @param string      $identifier The cache key or tag
     * @param string|null $reason     Reason for invalidation
     */
    public function __construct(string $type, string $identifier, ?string $reason = null)
    {
        $this->type = $type;
        $this->identifier = $identifier;
        $this->reason = $reason;
    }
}
