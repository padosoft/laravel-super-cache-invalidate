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
                            {--tag-batch-size= : The number of identifiers to process per invalidation batch}';

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

    /**
     * Process cache invalidation events.
     *
     * @param int $shardId      The shard number to process
     * @param int $priority     The priority level
     * @param int $limit        Maximum number of events to fetch per batch
     * @param int $tagBatchSize Number of identifiers to process per batch
     *
     * @throws \Exception
     */
    protected function processEvents(int $shardId, int $priority, int $limit, int $tagBatchSize): void
    {
        $processingStartTime = now();
        $invalidationWindow = config('super_cache_invalidate.invalidation_window');

        // Fetch a batch of unprocessed events
        $events = DB::table('cache_invalidation_events')
            ->where('processed', 0)
            ->where('shard', $shardId)
            ->where('priority', $priority)
            ->where('event_time', '<', $processingStartTime)
            ->orderBy('event_time')
            ->limit($limit)
            ->get()
        ;

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
        $eventIds = $events->pluck('id')->all();

        //retrive associated identifiers related to fetched event id
        $associations = DB::table('cache_invalidation_event_associations')
            ->whereIn('event_id', $eventIds)
            ->get()
            ->groupBy('event_id')
        ;

        // Prepare list of all identifiers to fetch last invalidation times
        $allIdentifiers = [];

        foreach ($eventsByIdentifier as $key => $eventsGroup) {
            $allIdentifiers[] = $key;
            foreach ($eventsGroup as $event) {
                $eventAssociations = $associations->get($event->id, collect());
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
                $eventAssociations = $associations->get($event->id, collect());
                foreach ($eventAssociations as $assoc) {
                    $assocKey = $assoc->associated_type . ':' . $assoc->associated_identifier;
                    $associatedIdentifiers[$assocKey] = [
                        'type' => $assoc->associated_type,
                        'identifier' => $assoc->associated_identifier,
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
                $this->processBatch($batchIdentifiers, $eventsToUpdate);

                // Reset the accumulators
                $batchIdentifiers = [];
                $eventsToUpdate = [];
            }
        }

        // Process any remaining identifiers in the batch
        if (!empty($batchIdentifiers)) {
            $this->processBatch($batchIdentifiers, $eventsToUpdate);
        }
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

        // Prepare placeholders and parameters
        $placeholders = implode(',', array_fill(0, count($tuples), '(?, ?)'));
        $params = [];
        foreach ($tuples as [$type, $identifier]) {
            $params[] = $type;
            $params[] = $identifier;
        }

        // Build and execute the query
        $sql = "SELECT identifier_type,
                        identifier,
                        last_invalidated
                FROM cache_invalidation_timestamps
                WHERE (identifier_type, identifier) IN ($placeholders)
                ";
        $records = DB::select($sql, $params);

        // Build associative array
        $lastInvalidationTimes = [];
        foreach ($records as $record) {
            $key = $record->identifier_type . ':' . $record->identifier;
            $lastInvalidationTimes[$key] = Carbon::parse($record->last_invalidated);
        }

        return $lastInvalidationTimes;
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
     * @throws \Exception
     */
    protected function processBatch(array $batchIdentifiers, array $eventsToUpdate): void
    {
        // Begin transaction for the batch
        DB::beginTransaction();

        try {
            // Separate keys and tags
            $keys = [];
            $tags = [];
            foreach ($batchIdentifiers as $item) {
                if ($item['type'] === 'key') {
                    $keys[] = $item['identifier'];
                } else {
                    $tags[] = $item['identifier'];
                }

                // Include associated identifiers
                if (!empty($item['associated'])) {
                    foreach ($item['associated'] as $assoc) {
                        if ($assoc['type'] === 'key') {
                            $keys[] = $assoc['identifier'];
                        } else {
                            $tags[] = $assoc['identifier'];
                        }
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

            // Mark events as processed
            DB::table('cache_invalidation_events')
                ->whereIn('id', $eventsToUpdate)
                ->update(['processed' => 1])
            ;

            // Commit transaction
            DB::commit();
        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();
            throw $e;
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

        if (is_callable($callback)) {
            call_user_func($callback, $keys);

            return;
        }

        //default invalidation method
        foreach ($keys as $key) {
            Cache::forget($key);
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

        if (is_callable($callback)) {
            call_user_func($callback, $tags);

            return;
        }

        //default invalidation method
        Cache::tags($tags)->flush();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $shardId = (int) $this->option('shard');
        $priority = (int) $this->option('priority');
        $limit = $this->option('limit') ?? config('super_cache_invalidate.processing_limit');
        $tagBatchSize = $this->option('tag-batch-size') ?? config('super_cache_invalidate.tag_batch_size');
        $lockTimeout = config('super_cache_invalidate.lock_timeout');

        if ($shardId === 0 && $priority === 0) {
            $this->error('Shard and priority are required and must be non-zero integers.');

            return;
        }

        $lockValue = $this->helper->acquireShardLock($shardId, $lockTimeout);

        if (!$lockValue) {
            $this->info("Another process is handling shard $shardId.");

            return;
        }

        try {
            $this->processEvents($shardId, $priority, $limit, $tagBatchSize);
        } catch (\Exception $e) {
            $this->error('An error occourred in ' . __METHOD__ . ': ' . $e->getTraceAsString());
        } finally {
            $this->helper->releaseShardLock($shardId, $lockValue);
        }
    }
}
