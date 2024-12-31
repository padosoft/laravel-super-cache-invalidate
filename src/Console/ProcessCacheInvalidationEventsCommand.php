<?php

namespace Padosoft\SuperCacheInvalidate\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

class ProcessCacheInvalidationEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'supercache:process-invalidation
                            {--shard= : The shard number to process}
                            {--priority= : The priority level}
                            {--limit= : The maximum number of events to fetch per batch}
                            {--tag-batch-size= : The number of identifiers to process per invalidation batch}
                            {--connection_name= : The Redis connection name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process cache invalidation events for a given shard and priority';

    /**
     * Cache invalidation helper instance.
     */
    protected SuperCacheInvalidationHelper $helper;

    /**
     * Create a new command instance.
     */
    public function __construct(SuperCacheInvalidationHelper $helper)
    {
        parent::__construct();
        $this->helper = $helper;
    }

    private function getStoreFromConnectionName(string $connection_name): ?string
    {
        // Cerca il nome dello store associato alla connessione Redis
        foreach (config('cache.stores') as $storeName => $storeConfig) {
            if (
                isset($storeConfig['driver'], $storeConfig['connection']) &&
                $storeConfig['driver'] === 'redis' &&
                $storeConfig['connection'] === $connection_name
            ) {
                return $storeName;
            }
        }

        return null;
    }



    /**
     * Process cache invalidation events.
     *
     * @param int $shardId The shard number to process
     * @param int $priority The priority level
     * @param int $limit Maximum number of events to fetch per batch
     * @param int $tagBatchSize Number of identifiers to process per batch
     *
     * @throws \Exception
     * @throws \Throwable
     */
    protected function processEvents(int $shardId, int $priority, int $limit, int $tagBatchSize, string $connection_name): void
    {
        $processingStartTime = now();
        $invalidationWindow = config('super_cache_invalidate.invalidation_window');

        // Fetch a batch of unprocessed events
        $partitionCache_invalidation_events = $this->helper->getCacheInvalidationEventsPartitionName($shardId, $priority);

        $events = DB::table(DB::raw("`cache_invalidation_events` PARTITION ({$partitionCache_invalidation_events})"))
            //->from(DB::raw("`{$this->from}` PARTITION ({$partitionsString})"))
            ->where('processed', '=', 0)
            ->where('shard', '=', $shardId)
            ->where('priority', '=', $priority)
            ->where('event_time', '<', $processingStartTime)
            // Cerco tutte le chiavi/tag da invalidare per questo database redis
            ->where('connection_name', '=', $connection_name)
            ->orderBy('event_time')
            ->limit($limit)
            ->get()
        ;

        //ds($partitionCache_invalidation_events . ' -> Shard (' . $shardId . ') Priority (' . $priority . ') Record = ' . $events->count());
        if ($events->isEmpty()) {
            // No more events to process
            return;
        }

        // Group events by type and identifier
        $eventsByIdentifier = $events->groupBy(function ($event) {
            return $event->type . ':' . $event->identifier;
        });

        $batchIdentifiers = [];
        $eventsToUpdate = [];
        $counter = 0;

        // Fetch associated identifiers for the events
        // TODO JB 31/12/2024: per adesso commentato, da riattivare quando tutto funziona alla perfezione usando la partizione
        $associations = collect();
        /*
        $eventIds = $events->pluck('id')->all();

        //retrive associated identifiers related to fetched event id
        // Per le chiavi/tag associati non filtro per connection_name, potrebbero esserci associazioni anche in altri database
        $associations = DB::table('cache_invalidation_event_associations')
            ->whereIn('event_id', $eventIds)
            ->get()
            ->groupBy('event_id')
        ;
        */

        // Prepare list of all identifiers to fetch last invalidation times
        $allIdentifiers = [];
        foreach ($eventsByIdentifier as $key => $eventsGroup) {
            $allIdentifiers[] = $key;
            foreach ($eventsGroup as $event) {
                $eventAssociations = $associations->where('event_id', '=', $event->id);
                foreach ($eventAssociations as $assoc) {
                    $assocKey = $assoc->associated_type . ':' . $assoc->associated_identifier;
                    $allIdentifiers[] = $assocKey;
                }
            }
        }
        // Fetch last invalidation times in bulk
        $lastInvalidationTimes = $this->getLastInvalidationTimes(array_unique($allIdentifiers));

        foreach ($eventsByIdentifier as $key => $eventsGroup) {
            // Extract type and identifier
            [$type, $identifier] = explode(':', $key, 2);

            // Get associated identifiers for the events
            $associatedIdentifiers = [];
            foreach ($eventsGroup as $event) {
                $eventAssociations = $associations->where('event_id', '=', $event->id);
                foreach ($eventAssociations as $assoc) {
                    $assocKey = $assoc->associated_type . ':' . $assoc->associated_identifier;
                    $associatedIdentifiers[$assocKey] = [
                        'type' => $assoc->associated_type,
                        'identifier' => $assoc->associated_identifier,
                        'connection_name' => $assoc->connection_name,
                    ];
                }
            }

            // Build a list of all identifiers to check
            $identifiersToCheck = [$key];
            $identifiersToCheck = array_merge($identifiersToCheck, array_keys($associatedIdentifiers));
            $lastInvalidationTimesSubset = array_intersect_key($lastInvalidationTimes, array_flip($identifiersToCheck));

            $shouldInvalidate = $this->shouldInvalidateMultiple($identifiersToCheck, $lastInvalidationTimesSubset, $invalidationWindow);

            if ($shouldInvalidate) {
                // Proceed to invalidate
                $latestEvent = $eventsGroup->last();

                // Accumulate identifiers and events
                $batchIdentifiers[] = [
                    'type' => $type,
                    'identifier' => $identifier,
                    'event' => $latestEvent,
                    'connection_name' => $connection_name,
                    'associated' => array_values($associatedIdentifiers),
                ];

                // Update last invalidation times for all identifiers
                $this->updateLastInvalidationTimes($identifiersToCheck);

                // Mark all events in the group as processed
                foreach ($eventsGroup as $event) {
                    $eventsToUpdate[] = $event->id;
                }
            } else {
                // Within the invalidation window, skip invalidation
                // Mark all events except the last one as processed
                $eventsToProcess = $eventsGroup->slice(0, -1);
                foreach ($eventsToProcess as $event) {
                    $eventsToUpdate[] = $event->id;
                }
                // The last event remains unprocessed
            }

            $counter++;

            // When we reach the batch size, process the accumulated identifiers
            if ($counter % $tagBatchSize === 0) {
                $this->processBatch($batchIdentifiers, $eventsToUpdate, $shardId, $priority);

                // Reset the accumulators
                $batchIdentifiers = [];
                $eventsToUpdate = [];
            }
        }

        if (empty($batchIdentifiers)) {
            return;
        }

        // Process any remaining identifiers in the batch
        $this->processBatch($batchIdentifiers, $eventsToUpdate, $shardId, $priority);
    }

    /**
     * Fetch last invalidation times for identifiers in bulk.
     *
     * @param  array $identifiers Array of 'type:identifier' strings
     * @return array Associative array of last invalidation times
     */
    protected function getLastInvalidationTimes(array $identifiers): array
    {
        // Extract types and identifiers into tuples
        $tuples = array_map(static function ($key) {
            return explode(':', $key, 2);
        }, $identifiers);

        if (empty($tuples)) {
            return [];
        }

        $records = $this->getRecordsFromDb($tuples);

        // Build associative array
        $lastInvalidationTimes = [];
        foreach ($records as $record) {
            $key = $record->identifier_type . ':' . $record->identifier;
            $lastInvalidationTimes[$key] = Carbon::parse($record->last_invalidated);
        }

        return $lastInvalidationTimes;
    }

    /**
     * Execute Query to get records from DB
     */
    protected function getRecordsFromDb(array $tuples): array
    {
        // Prepare placeholders and parameters
        $placeholders = implode(',', array_fill(0, count($tuples), '(?, ?)'));
        $params = [];
        foreach ($tuples as [$type, $identifier]) {
            $params[] = $type;
            $params[] = $identifier;
        }

        $sql = "SELECT identifier_type,
                        identifier,
                        last_invalidated
                FROM cache_invalidation_timestamps
                WHERE (identifier_type, identifier) IN ($placeholders)
                ";

        return DB::select($sql, $params);
    }

    /**
     * Determine whether to invalidate based on last invalidation times for multiple identifiers.
     *
     * @param  array $identifiers           Array of 'type:identifier' strings
     * @param  array $lastInvalidationTimes Associative array of last invalidation times
     * @param  int   $invalidationWindow    Invalidation window in seconds
     * @return bool  True if should invalidate, false otherwise
     */
    protected function shouldInvalidateMultiple(array $identifiers, array $lastInvalidationTimes, int $invalidationWindow): bool
    {
        $now = now();
        foreach ($identifiers as $key) {
            $lastInvalidated = $lastInvalidationTimes[$key] ?? null;
            if (!$lastInvalidated) {
                continue;
            }
            $elapsed = $now->diffInSeconds($lastInvalidated);
            if ($elapsed < $invalidationWindow) {
                // At least one identifier is within the invalidation window
                return false;
            }
        }

        // All identifiers are outside the invalidation window or have no record
        return true;
    }

    /**
     * Update the last invalidation times for multiple identifiers.
     *
     * @param array $identifiers Array of 'type:identifier' strings
     */
    protected function updateLastInvalidationTimes(array $identifiers): void
    {
        $now = now();

        foreach ($identifiers as $key) {
            [$type, $identifier] = explode(':', $key, 2);
            DB::table('cache_invalidation_timestamps')
                ->updateOrInsert(
                    ['identifier_type' => $type, 'identifier' => $identifier],
                    ['last_invalidated' => $now]
                )
            ;
        }
    }

    /**
     * Process a batch of identifiers and update events.
     *
     * @param array $batchIdentifiers Array of identifiers to invalidate
     * @param array $eventsToUpdate   Array of event IDs to mark as processed
     *
     * @throws \Throwable
     */
    protected function processBatch(array $batchIdentifiers, array $eventsToUpdate, int $shard, int $priority): void
    {
        $maxAttempts = 5;
        $attempts = 0;
        $updatedOk = false;

        // Separate keys and tags
        $keys = [];
        $tags = [];

        foreach ($batchIdentifiers as $item) {
            switch ($item['type']) {
                case 'key':
                    $keys[] = $item['identifier'] . '§' . $item['connection_name'];
                    break;
                case 'tag':
                    $tags[] = $item['identifier'] . '§' . $item['connection_name'];
                    break;
            }

            if (empty($item['associated'])) {
                continue;
            }

            // Include associated identifiers
            foreach ($item['associated'] as $assoc) {
                switch ($assoc['type']) {
                    case 'key':
                        $keys[] = $assoc['identifier'] . '§' . $assoc['connection_name'];
                        break;
                    case 'tag':
                        $tags[] = $assoc['identifier'] . '§' . $assoc['connection_name'];
                        break;
                }
            }
        }

        // Remove duplicates
        $keys = array_unique($keys);
        $tags = array_unique($tags);

        // Invalidate cache for keys
        if (!empty($keys)) {
            $this->invalidateKeys($keys);
        }

        // Invalidate cache for tags
        if (!empty($tags)) {
            $this->invalidateTags($tags);
        }

        while ($attempts < $maxAttempts && !$updatedOk) {
            // Begin transaction for the batch
            DB::beginTransaction();

            try {
                // Mark events as processed
                $partitionCache_invalidation_events = $this->helper->getCacheInvalidationEventsPartitionName($shard, $priority);
                DB::table(DB::raw("`cache_invalidation_events` PARTITION ({$partitionCache_invalidation_events})"))
                    ->whereIn('id', $eventsToUpdate)
                    ->update(['processed' => 1])
                ;

                // Commit transaction
                DB::commit();
                $updatedOk = true;
            } catch (\Throwable $e) {
                // Rollback transaction on error
                DB::rollBack();
                $attempts++;
                // Logica per gestire i tentativi falliti
                if ($attempts >= $maxAttempts) {
                    // Salta il record dopo il numero massimo di tentativi
                    throw $e;
                }
            }
        }
    }

    /**
     * Invalidate cache keys.
     *
     * @param array $keys Array of cache keys to invalidate
     */
    protected function invalidateKeys(array $keys): void
    {
        $callback = config('super_cache_invalidate.key_invalidation_callback');


        // Anche in questo caso va fatto un loop perchè le chiavi potrebbero stare in database diversi
        foreach ($keys as $keyAndConnectionName) {
            [$key, $connection_name] = explode('§', $keyAndConnectionName);

            // Metodo del progetto
            if (is_callable($callback)) {
                $callback($key, $connection_name);

                return;
            }

            // oppure di default uso Laravel
            $storeName =  $this->getStoreFromConnectionName($connection_name);

            if ($storeName === null) {
                return;
            }
            Cache::store($storeName)->forget($key);
        }
    }

    /**
     * Invalidate cache tags.
     *
     * @param array $tags Array of cache tags to invalidate
     */
    protected function invalidateTags(array $tags): void
    {
        $callback = config('super_cache_invalidate.tag_invalidation_callback');

        $groupByConnection = [];

        // Anche in questo caso va fatto un loop perchè i tags potrebbero stare in database diversi,
        // ma per ottimizzare possiamo raggruppare le operazioni per connessione
        foreach ($tags as $tagAndConnectionName) {
            // chiave e connessione
            [$key, $connection] = explode('§', $tagAndConnectionName);

            // Aggiungo la chiave alla connessione appropriata
            $groupByConnection[$connection][] = $key;
        }
        if (is_callable($callback)) {
            foreach ($groupByConnection as $connection_name => $arrTags) {
                $callback($arrTags, $connection_name);
            }

            return;
        }
        foreach ($groupByConnection as $connection_name => $arrTags) {
            $storeName =  $this->getStoreFromConnectionName($connection_name);
            if ($storeName === null) {
                return;
            }
            Cache::store($storeName)->tags($arrTags)->flush();
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $shardId = (int) $this->option('shard');
        $priority = (int) $this->option('priority');
        $limit = $this->option('limit') ?? config('super_cache_invalidate.processing_limit');
        $limit = (int)$limit;
        $tagBatchSize = $this->option('tag-batch-size') ?? config('super_cache_invalidate.tag_batch_size');
        $tagBatchSize = (int)$tagBatchSize;
        $lockTimeout = (int) config('super_cache_invalidate.lock_timeout');
        $connection_name = $this->option('connection_name') ?? config('super_cache_invalidate.default_connection_name');
        /*
        if ($shardId === 0 && $priority === 0) {
            $this->error('Shard and priority are required and must be non-zero integers.');

            return;
        }
        */
        $lockValue = $this->helper->acquireShardLock($shardId, $priority, $lockTimeout, $connection_name);

        if (!$lockValue) {
            return;
        }

        try {
            $this->processEvents($shardId, $priority, $limit, $tagBatchSize, $connection_name);
        } catch (\Throwable $e) {
            $this->error(now() . ' Si è verificato un errore in ' . __METHOD__ . ': ' . $e->getMessage());
        } finally {
            $this->helper->releaseShardLock($shardId, $priority, $lockValue, $connection_name);
        }
    }
}
