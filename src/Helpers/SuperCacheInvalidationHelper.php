<?php

namespace Padosoft\SuperCacheInvalidate\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SuperCacheInvalidationHelper
{
    /**
     * Insert a cache invalidation event into the database.
     *
     * @param string      $type                  'key' or 'tag'
     * @param string      $identifier            The cache key or tag to invalidate
     * @param string|null $reason                Reason for invalidation (optional)
     * @param int         $priority              Priority of the event
     * @param array       $associatedIdentifiers Optional array of associated tags or keys
     * @param int|null    $totalShards           Total number of shards (from config if null)
     */
    public function insertInvalidationEvent(
        string $type,
        string $identifier,
        ?string $reason = null,
        int $priority = 0,
        array $associatedIdentifiers = [],
        ?int $totalShards = null
    ): void {
        $totalShards = $totalShards ?? config('super_cache_invalidate.total_shards', 10);
        $shard = crc32($identifier) % $totalShards;

        $data = [
            'type' => $type,
            'identifier' => $identifier,
            'reason' => $reason,
            'priority' => $priority,
            'event_time' => now(),
            'processed' => 0,
            'shard' => $shard,
        ];

        // Insert the event and get its ID
        $eventId = DB::table('cache_invalidation_events')->insertGetId($data);

        // Insert associated identifiers
        if (!empty($associatedIdentifiers)) {
            $associations = [];
            foreach ($associatedIdentifiers as $associated) {
                $associations[] = [
                    'event_id' => $eventId,
                    'associated_type' => $associated['type'], // 'key' or 'tag'
                    'associated_identifier' => $associated['identifier'],
                    'created_at' => now(),
                ];
            }
            DB::table('cache_invalidation_event_associations')->insert($associations);
        }
    }

    /**
     * Acquire a lock for processing a shard.
     *
     * @param  int          $shardId     The shard number
     * @param  int          $lockTimeout Lock timeout in seconds
     * @return string|false The lock value if acquired, false otherwise
     */
    public function acquireShardLock(int $shardId, int $lockTimeout): bool|string
    {
        $lockKey = "shard_lock:$shardId";
        $lockValue = uniqid('', true);
        $isLocked = Redis::set($lockKey, $lockValue, 'NX', 'EX', $lockTimeout);

        return $isLocked ? $lockValue : false;
    }

    /**
     * Release the lock for a shard.
     *
     * @param int    $shardId   The shard number
     * @param string $lockValue The lock value to validate ownership
     */
    public function releaseShardLock(int $shardId, string $lockValue): void
    {
        $lockKey = "shard_lock:$shardId";
        $currentValue = Redis::get($lockKey);
        if ($currentValue === $lockValue) {
            Redis::del($lockKey);
        }
    }
}
