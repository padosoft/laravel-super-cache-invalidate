<?php

namespace Padosoft\SuperCacheInvalidate\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SuperCacheInvalidationHelper
{
    /**
     * Insert a cache invalidation event into the database.
     *
     * @param string      $type                  'key' or 'tag'
     * @param string      $identifier            The cache key or tag to invalidate
     * @param string|null $connection_name       The Redis Connection name (optional, 'default')
     * @param string|null $reason                Reason for invalidation (optional)
     * @param int|null    $totalShards           Total number of shards (from config if null)
     * @param int|null    $priority              Priority of the event
     * @param array|null  $associatedIdentifiers Optional array of associated tags or keys
     */
    public function insertInvalidationEvent(
        string $type,
        string $identifier,
        ?string $connection_name = null,
        ?string $reason = null,
        ?int $totalShards = 0,
        ?int $priority = 0,
        ?array $associatedIdentifiers = [],
    ): void {
        $shard = crc32($identifier) % ($totalShards > 0 ? $totalShards : config('super_cache_invalidate.total_shards', 10));

        $redisConnectionName = $connection_name ?? config('super_cache_invalidate.default_connection_name');
        $data = [
            'type' => $type,
            'identifier' => $identifier,
            'connection_name' => $redisConnectionName,
            'reason' => $reason,
            'priority' => $priority,
            'event_time' => now(),
            'processed' => 0,
            'shard' => $shard,
        ];

        $maxAttempts = 5;
        $attempts = 0;
        $insertOk = false;

        while ($attempts < $maxAttempts && !$insertOk) {
            DB::beginTransaction();

            try {
                // Cerca di bloccare il record per l'inserimento
                $eventId = DB::table('cache_invalidation_events')->insertGetId($data);

                // Insert associated identifiers
                if (!empty($associatedIdentifiers)) {
                    $associations = [];
                    foreach ($associatedIdentifiers as $associated) {
                        $associations[] = [
                            'event_id' => $eventId,
                            'associated_type' => $associated['type'], // 'key' or 'tag'
                            'associated_identifier' => $associated['identifier'],
                            'connection_name' => $associated['connection_name'],
                            'created_at' => now(),
                        ];
                    }
                    DB::table('cache_invalidation_event_associations')->insert($associations);
                }
                $insertOk = true;
                DB::commit(); // Completa la transazione
            } catch (\Throwable $e) {
                DB::rollBack(); // Annulla la transazione in caso di errore
                $attempts++;
                // Logica per gestire i tentativi falliti
                if ($attempts >= $maxAttempts) {
                    // Salta il record dopo il numero massimo di tentativi
                    Log::error("SuperCacheInvalidate: impossibile eseguire insert dopo $maxAttempts tentativi: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Acquire a lock for processing a shard.
     *
     * @param  int          $shardId         The shard number
     * @param  int          $lockTimeout     Lock timeout in seconds
     * @param  string       $connection_name The Redis Connection name
     * @return string|false The lock value if acquired, false otherwise
     */
    public function acquireShardLock(int $shardId, int $priority, int $lockTimeout, string $connection_name): bool|string
    {
        $lockKey = 'shard_lock:' . $shardId . '_' . $priority;
        // Il metodo has/exists occupa troppa memoria!!!
        $retrieveValue = Redis::connection($connection_name)->get($lockKey);
        if ($retrieveValue !== null) {
            // Lock già attivo
            return false;
        }
        $lockValue = uniqid('', true);
        $isLocked = Redis::connection($connection_name)->set($lockKey, $lockValue);

        if ($lockTimeout > 0) {
            Redis::connection($connection_name)->expire($lockKey, $lockTimeout);
        }

        return $isLocked ? $lockValue : false;
    }

    /**
     * Release the lock for a shard.
     *
     * @param int    $shardId         The shard number
     * @param string $lockValue       The lock value to validate ownership
     * @param string $connection_name The Redis Connection name
     */
    public function releaseShardLock(int $shardId, int $priority, string $lockValue, string $connection_name): void
    {
        $lockKey = 'shard_lock:' . $shardId . '_' . $priority;
        $currentValue = Redis::connection($connection_name)->get($lockKey);
        if ($currentValue === $lockValue) {
            Redis::connection($connection_name)->del($lockKey);
        }
    }
}
