<?php

namespace Padosoft\SuperCacheInvalidate\Test\Support;

use Illuminate\Contracts\Redis\Factory;

/**
 * In-memory replacement of Laravel's RedisManager for unit tests.
 */
class FakeRedisManager implements Factory
{
    /** @var array<string, FakeRedisConnection> */
    public array $connections = [];

    public function connection($name = null)
    {
        $name = $name ?? 'default';

        return $this->connections[$name] ??= new FakeRedisConnection($name);
    }
}
