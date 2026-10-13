<?php

namespace Padosoft\SuperCacheInvalidate\Helpers;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SuperCacheInvalidationHelper
{
    public const TABLE = 'cache_invalidation_events';

    /** MySQL: "Found a row not matching the given partition set" (pruned UPDATE whose rows move to another partition). */
    public const MYSQL_ERROR_ROW_NOT_IN_PARTITION_SET = 1748;

    /**
     * Redis connection used for the shard locks.
     */
    protected function lockConnection(string $connection_name)
    {
        $lockConnection = config('super_cache_invalidate.lock_connection');

        return Redis::connection($lockConnection ?: $connection_name);
    }

    /**
     * UPDATE of one chunk of identifiers.
     * With partitions enabled the WHERE carries `partition_key = X`, so MySQL prunes the statement to the unprocessed
     * partition (about 14x faster on a big table). No PARTITION clause: the row moves to a processed partition.
     * MySQL refuses that pruned UPDATE with error 1748 when the SQL reaches the server with literal values (e.g. PDO
     * with ATTR_EMULATE_PREPARES=true): the statement is rolled back as a whole, so the same chunk is simply redone
     * without pruning (slower, but correct).
     */
    protected function markChunkAsProcessed(int $shardId, int $priority, string $connection_name, string $type, array $identifiers, string $beforeString, array $values): int
    {
        if (!$this->usePartitions()) {
            return $this->executeMarkUpdate($this->markQuery($shardId, $priority, $connection_name, $type, $identifiers, $beforeString, false), $values);
        }

        try {
            return $this->executeMarkUpdate($this->markQuery($shardId, $priority, $connection_name, $type, $identifiers, $beforeString, true), $values);
        } catch (QueryException $e) {
            if (!$this->isRowNotInPartitionSetError($e)) {
                throw $e;
            }
            Log::warning('SuperCacheInvalidate: MySQL ha rifiutato l\'UPDATE con pruning su partition_key (errore 1748, tipicamente PDO con ATTR_EMULATE_PREPARES=true): ripeto il chunk senza pruning, più lento. ' . $e->getMessage());

            return $this->executeMarkUpdate($this->markQuery($shardId, $priority, $connection_name, $type, $identifiers, $beforeString, false), $values);
        }
    }

    /**
     * @param  string[] $identifiers
     * @return Builder
     */
    protected function markQuery(int $shardId, int $priority, string $connection_name, string $type, array $identifiers, string $beforeString, bool $pruneByPartitionKey)
    {
        $query = DB::table(self::TABLE)
            ->where('processed', '=', 0)
            ->where('shard', '=', $shardId)
            ->where('priority', '=', $priority)
            ->where('connection_name', '=', $connection_name)
            ->where('type', '=', $type)
            ->whereIn('identifier', $identifiers)
            ->where('event_time', '<', $beforeString)
        ;
        if ($pruneByPartitionKey) {
            // Partition pruning on the generated column (NO PARTITION clause: the row moves to the processed partition)
            $query->where('partition_key', '=', ($priority * 10) + $shardId);
        }

        return $query;
    }

    /**
     * @param Builder $query
     */
    protected function executeMarkUpdate($query, array $values): int
    {
        return (int) $query->update($values);
    }

    protected function isRowNotInPartitionSetError(QueryException $e): bool
    {
        if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_ERROR_ROW_NOT_IN_PARTITION_SET) {
            return true;
        }

        return str_contains($e->getMessage(), 'not matching the given partition set');
    }

    /**
     * Insert a cache invalidation event into the database.
     *
     * @param string      $type            'key' or 'tag'
     * @param string      $identifier      The cache key or tag to invalidate
     * @param string|null $connection_name The Redis Connection name (optional, 'default')
     * @param string|null $reason          Reason for invalidation (optional)
     * @param int|null    $totalShards     Total number of shards (from config if null)
     * @param int|null    $priority        Priority of the event
     * @param int|null    $processed       Processed = 0 o 1
     * @param Carbon|null $event_time      Orario vero in cui si è richiesta l'invalidazione
     */
    public function insertInvalidationEvent(
        string $type,
        string $identifier,
        ?string $connection_name = null,
        ?string $reason = null,
        ?int $totalShards = 0,
        ?int $priority = 0,
        ?int $processed = 0,
        ?Carbon $event_time = null,
        /* ?array $associatedIdentifiers = [], */
        ?int $shard = -1,
    ): void {
        if ($shard < 0) {
            $shard = crc32($identifier) % ($totalShards > 0 ? $totalShards : config('super_cache_invalidate.total_shards', 10));
        }
        $redisConnectionName = $connection_name ?? config('super_cache_invalidate.default_connection_name');
        $data = [
            'type' => $type,
            'identifier' => $identifier,
            'connection_name' => $redisConnectionName,
            'reason' => $reason,
            'priority' => $priority,
            'event_time' => $event_time ?? now(),
            'processed' => $processed, // ATTENZIONE, poichè abbiamo solo 2 priorità, nel caso di priorità 1 verrà passato 1 perchè l'invalidazione la fa il progetto
            'shard' => $shard,
        ];

        $maxAttempts = 5;
        $attempts = 0;
        $insertOk = false;

        while ($attempts < $maxAttempts && !$insertOk) {
            try {
                switch ($processed) {
                    case 0:
                        $partitionCache_invalidation_events = $this->getCacheInvalidationEventsUnprocessedPartitionName($shard, $priority);
                        break;
                    case 1:
                        $partitionCache_invalidation_events = $this->getCacheInvalidationEventsProcessedPartitionName($shard, $priority, $event_time ?? now());
                        break;
                    default:
                        $attempts = 5; // Mi fermo
                        throw new \RuntimeException('Invalid value for processed');
                }
                $this->eventsTable($partitionCache_invalidation_events)->insert($data);
                $insertOk = true;
            } catch (\Throwable $e) {
                $attempts++;
                Log::error("SuperCacheInvalidate: impossibile eseguire insert, tentativo $attempts di $maxAttempts: " . $e->getMessage());
                if ($attempts >= $maxAttempts) {
                    // Salta il record dopo il numero massimo di tentativi
                    Log::error("SuperCacheInvalidate: impossibile eseguire insert dopo $maxAttempts tentativi: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Whether queries must use the explicit MySQL PARTITION clause.
     */
    public function usePartitions(): bool
    {
        return (bool) config('super_cache_invalidate.use_partitions', true);
    }

    /**
     * Query builder on the events table, restricted to a partition when partitions are enabled.
     * NOTE: never use the PARTITION clause on an UPDATE that changes `processed`, because the row
     * moves to another partition and MySQL raises error 1748.
     *
     * @return Builder
     */
    public function eventsTable(?string $partition = null)
    {
        if ($partition !== null && $this->usePartitions()) {
            return DB::table(DB::raw('`' . self::TABLE . "` PARTITION ({$partition})"));
        }

        return DB::table(self::TABLE);
    }

    /**
     * Fetch the oldest unprocessed events of a shard/priority/connection whose event_time is before $before.
     *
     * @return array<int, object> objects with type, identifier, connection_name, event_time
     */
    public function fetchUnprocessedEvents(int $shardId, int $priority, string $connection_name, Carbon $before, int $limit): array
    {
        $partition = $this->getCacheInvalidationEventsUnprocessedPartitionName($shardId, $priority);

        return $this->eventsTable($partition)
            ->select(['type', 'identifier', 'connection_name', 'event_time'])
            ->where('processed', '=', 0)
            ->where('shard', '=', $shardId)
            ->where('priority', '=', $priority)
            ->where('connection_name', '=', $connection_name)
            ->where('event_time', '<', $before->toDateTimeString())
            ->orderBy('event_time')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all()
        ;
    }

    /**
     * Mark as processed ALL the pending events of the given identifiers (not only the fetched ones)
     * whose event_time is before $before. Returns the number of affected rows.
     *
     * @param array<string, string[]> $identifiersByType es. ['tag' => ['a', 'b'], 'key' => ['k']]
     */
    public function markIdentifiersAsProcessed(int $shardId, int $priority, string $connection_name, array $identifiersByType, Carbon $before, string $batchId): int
    {
        $affected = 0;
        $values = ['processed' => 1, 'batch_ID' => $batchId, 'updated_at' => now()->toDateTimeString()];
        $beforeString = $before->toDateTimeString();

        foreach ($identifiersByType as $type => $identifiers) {
            foreach (array_chunk(array_values(array_unique($identifiers)), 500) as $chunk) {
                $affected += $this->markChunkAsProcessed($shardId, $priority, $connection_name, (string) $type, $chunk, $beforeString, $values);
            }
        }

        return $affected;
    }

    /**
     * Redis key of the lock that serializes the processing of a shard.
     */
    public function getShardLockKey(int $shardId, int $priority, string $connection_name): string
    {
        return 'supercache_invalidate:shard_lock:' . $connection_name . ':' . $shardId . ':' . $priority;
    }

    /**
     * Acquire the lock for processing a shard (atomic SET NX EX: cluster safe, single key).
     *
     * @return string|false The lock value (owner token) if acquired, false if another process holds it
     */
    public function acquireShardLock(int $shardId, int $priority, int $lockTimeout, string $connection_name): bool|string
    {
        $lockKey = $this->getShardLockKey($shardId, $priority, $connection_name);
        $lockValue = uniqid((string) gethostname() . ':' . getmypid() . ':', true);

        $result = $this->lockConnection($connection_name)->set($lockKey, $lockValue, 'EX', max(1, $lockTimeout), 'NX');

        return $result ? $lockValue : false;
    }

    /**
     * Extend the TTL of the lock only if we still own it (single key Lua script: cluster safe).
     *
     * @return bool false if the lock expired or belongs to another process
     */
    public function refreshShardLock(int $shardId, int $priority, string $lockValue, int $lockTimeout, string $connection_name): bool
    {
        $lockKey = $this->getShardLockKey($shardId, $priority, $connection_name);
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("expire", KEYS[1], tonumber(ARGV[2]))
else
    return 0
end
LUA;

        return (int) $this->lockConnection($connection_name)->eval($script, 1, $lockKey, $lockValue, max(1, $lockTimeout)) === 1;
    }

    /**
     * Release the lock only if we still own it (single key Lua script: cluster safe).
     *
     * @return bool true if the lock was released by this call
     */
    public function releaseShardLock(int $shardId, int $priority, string $lockValue, string $connection_name): bool
    {
        $lockKey = $this->getShardLockKey($shardId, $priority, $connection_name);
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;

        return (int) $this->lockConnection($connection_name)->eval($script, 1, $lockKey, $lockValue) === 1;
    }

    public function getCacheInvalidationEventsUnprocessedPartitionName(int $shardId, int $priorityId): string
    {
        $partitionValue = ($priorityId * 10) + $shardId;

        return "p_unprocessed_{$partitionValue}";
    }

    public function getCacheInvalidationEventsProcessedPartitionName(int $shardId, int $priorityId, Carbon $event_time): string
    {
        $partitionValue = ($event_time->year * 10000) + ($event_time->weekOfYear * 100) + ($priorityId * 10) + $shardId;

        return "p_processed_{$partitionValue}";
    }
}
