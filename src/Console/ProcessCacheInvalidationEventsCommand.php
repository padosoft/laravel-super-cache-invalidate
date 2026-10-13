<?php

namespace Padosoft\SuperCacheInvalidate\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Padosoft\SuperCacheInvalidate\Events\BatchCompletedEvent;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

/**
 * Processes the pending invalidation events of one shard.
 *
 * Design (see README "Processing Invalidation Events"):
 *  - one process per shard/priority/connection, guaranteed by an atomic Redis lock (SET NX EX)
 *    refreshed after every chunk; a killed process loses the lock after lock_timeout seconds;
 *  - events are fetched oldest-first, grouped by identifier (O(n)), and only the identifiers whose
 *    oldest event is outside the invalidation window are flushed;
 *  - the work is done in chunks: flush on Redis, mark as processed ALL the pending events of those
 *    identifiers, dispatch BatchCompletedEvent (always, once the events are marked, even if the lock
 *    turns out to be lost), refresh the lock. Progress is durable per chunk;
 *  - the run stops cleanly on max_runtime, SIGTERM/SIGINT, lock loss or exception; an unexpected
 *    shutdown is logged by a shutdown function.
 *
 * All Redis commands used by the package are single-key (cluster safe).
 */
class ProcessCacheInvalidationEventsCommand extends Command
{
    /** Small sites rule: when few unique identifiers are pending, flush them all once the oldest waits this long. */
    public const SMALL_BATCH_MIN_AGE = 120;

    protected $signature = 'supercache:process-invalidation
                            {--shard= : The shard number to process}
                            {--priority= : The priority level}
                            {--limit= : The maximum number of events to fetch per iteration}
                            {--tag-batch-size= : Below this number of unique identifiers the small sites rule applies}
                            {--chunk-size= : The number of unique identifiers flushed and marked as processed per chunk}
                            {--max-runtime= : Time budget in seconds of this run (0 = unlimited)}
                            {--connection_name= : The Redis connection name}
                            {--log_attivo= : Indica se il log delle operazioni è attivo (0=no, 1=si)}';
    protected $description = 'Process cache invalidation events for a given shard and priority';
    protected SuperCacheInvalidationHelper $helper;
    protected bool $log_attivo = false;
    protected string $connection_name = 'cache';
    protected int $tagBatchSize = 100;
    protected int $limit = 1000;
    protected int $chunkSize = 200;
    protected int $maxRuntime = 1200;
    protected int $priority = 0;
    protected int $shardId = 0;
    protected int $invalidation_window = 600;
    protected int $lockTimeout = 600;
    protected string|false $lockValue = false;
    protected bool $stopRequested = false;
    protected ?string $stopReason = null;
    protected bool $stopLogged = false;
    protected bool $finished = false;
    protected bool $safetyNetsRegistered = false;
    protected float $runStartedAt = 0.0;
    protected int $totalChunks = 0;
    protected int $totalIdentifiers = 0;
    protected int $totalEvents = 0;

    public function __construct(SuperCacheInvalidationHelper $helper)
    {
        parent::__construct();
        $this->helper = $helper;
    }

    protected function resetState(): void
    {
        $this->lockValue = false;
        $this->stopRequested = false;
        $this->stopReason = null;
        $this->stopLogged = false;
        $this->finished = false;
        $this->runStartedAt = microtime(true);
        $this->totalChunks = 0;
        $this->totalIdentifiers = 0;
        $this->totalEvents = 0;
    }

    protected function readOptions(): void
    {
        $this->shardId = (int) $this->option('shard');
        $this->priority = (int) $this->option('priority');
        $this->limit = max(1, (int) ($this->option('limit') ?? config('super_cache_invalidate.processing_limit')));
        $this->tagBatchSize = (int) ($this->option('tag-batch-size') ?? config('super_cache_invalidate.tag_batch_size'));
        $this->chunkSize = max(1, (int) ($this->option('chunk-size') ?? config('super_cache_invalidate.chunk_size', 200)));
        $this->maxRuntime = max(0, (int) ($this->option('max-runtime') ?? config('super_cache_invalidate.max_runtime', 1200)));
        $this->connection_name = (string) ($this->option('connection_name') ?? config('super_cache_invalidate.default_connection_name'));
        $this->log_attivo = (int) $this->option('log_attivo') === 1;
        $this->invalidation_window = (int) config('super_cache_invalidate.invalidation_window');
        $this->lockTimeout = max(1, (int) config('super_cache_invalidate.lock_timeout'));
    }

    /**
     * Shutdown function (logs fatal errors / unexpected exits and releases the lock) and signal handlers.
     * A SIGKILL cannot be intercepted: in that case the lock expires after lock_timeout seconds.
     */
    protected function registerSafetyNets(): void
    {
        if ($this->safetyNetsRegistered) {
            return;
        }
        $this->safetyNetsRegistered = true;

        register_shutdown_function(function () {
            if ($this->finished) {
                return;
            }
            $message = 'Processo terminato senza completare l\'elaborazione (shutdown inatteso)';
            $error = error_get_last();
            if ($error !== null) {
                $message .= sprintf('. Ultimo errore PHP: %s in %s on line %d', $error['message'], $error['file'], $error['line']);
            }
            $message .= '. Peak memory: ' . $this->formatBytes(memory_get_peak_usage(true));
            $this->logError($message);
            $this->releaseLock();
        });

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use ($signal) {
                    $this->requestStop('segnale ' . $signal);
                });
            }
        }
    }

    protected function processEvents(): void
    {
        while (true) {
            if ($this->shouldStop()) {
                return;
            }

            $processingStartTime = now();
            $events = $this->helper->fetchUnprocessedEvents($this->shardId, $this->priority, $this->connection_name, $processingStartTime, $this->limit);
            $found = count($events);
            $this->logIf('Trovati ' . $found . ' Eventi da invalidare');
            if ($found === 0) {
                return;
            }

            $groups = $this->groupEvents($events);
            unset($events);
            $this->logIf('Eventi unici ' . count($groups));

            $due = $this->selectDueGroups($groups, $processingStartTime);
            unset($groups);
            if (count($due) === 0) {
                $this->logIf('Nessun identificatore fuori dalla finestra di invalidazione (' . $this->invalidation_window . 's), esco.');

                return;
            }

            foreach (array_chunk($due, $this->chunkSize) as $chunk) {
                $this->processChunk($chunk, $processingStartTime);
                if ($this->shouldStop()) {
                    return;
                }
            }
            unset($due);

            if ($found < $this->limit) {
                // Ho letto tutto quello che c'era: la partizione è stata svuotata
                return;
            }
        }
    }

    /**
     * Group the events by (type, identifier) keeping the oldest event_time of each group. O(n).
     *
     * @param  object[]                                                                                              $events
     * @return array<int, array{type: string, identifier: string, connection_name: string, oldest: int, count: int}>
     */
    protected function groupEvents(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $key = $event->type . "\0" . $event->identifier;
            $timestamp = $this->toTimestamp($event->event_time);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'type' => (string) $event->type,
                    'identifier' => (string) $event->identifier,
                    'connection_name' => (string) $event->connection_name,
                    'oldest' => $timestamp,
                    'count' => 1,
                ];
                continue;
            }
            $groups[$key]['count']++;
            if ($timestamp < $groups[$key]['oldest']) {
                $groups[$key]['oldest'] = $timestamp;
            }
        }

        return array_values($groups);
    }

    /**
     * Select the groups to flush now.
     * - small sites rule: fewer unique identifiers than tagBatchSize and the oldest waits at least 2 minutes -> all of them;
     * - otherwise only the groups whose oldest event is outside the invalidation window.
     * Ages are computed on unix timestamps (Carbon 3 diffInSeconds is signed, do not use it here).
     */
    protected function selectDueGroups(array $groups, Carbon $processingStartTime): array
    {
        if (count($groups) === 0) {
            return [];
        }
        $startTs = $processingStartTime->getTimestamp();
        $oldestAge = $startTs - min(array_column($groups, 'oldest'));

        if (count($groups) < $this->tagBatchSize && $oldestAge >= self::SMALL_BATCH_MIN_AGE) {
            $this->logIf('Il numero di eventi unici è inferiore al batchSize (' . $this->tagBatchSize . ') e il più vecchio aspetta da ' . $oldestAge . 's, procedo con tutti');

            return $groups;
        }

        $due = [];
        foreach ($groups as $group) {
            if (($startTs - $group['oldest']) >= $this->invalidation_window) {
                $due[] = $group;
            }
        }

        return $due;
    }

    /**
     * Flush a chunk of identifiers on Redis, then mark ALL their pending events (older than the fetch time)
     * as processed, dispatch the BatchCompletedEvent and refresh the lock.
     */
    protected function processChunk(array $chunk, Carbon $processingStartTime): void
    {
        $batchId = (string) Str::uuid();
        $keys = [];
        $tags = [];
        $identifiersByType = [];
        $identifiers = [];
        $excludedTags = 0;

        foreach ($chunk as $group) {
            $identifiersByType[$group['type']][] = $group['identifier'];
            $identifiers[] = $group['identifier'];
            switch ($group['type']) {
                case 'key':
                    $keys[] = $group;
                    break;
                case 'tag':
                    if ($this->isTagExcludedFromFlush($group['identifier'])) {
                        $excludedTags++;
                        break;
                    }
                    $tags[] = $group;
                    break;
            }
        }

        $this->logIf(sprintf(
            'Chunk %d: invalido %d chiavi e %d tags (%d tags esclusi dal flush Redis) per %d identificatori',
            $this->totalChunks + 1,
            count($keys),
            count($tags),
            $excludedTags,
            count($chunk)
        ));

        if (!empty($keys)) {
            $this->invalidateKeys($keys);
        }
        if (!empty($tags)) {
            $this->invalidateTags($tags);
        }

        // Progresso durevole: marco TUTTI gli eventi pendenti di questi identificatori più vecchi dell'inizio del fetch,
        // non solo quelli letti, così lo stesso tag non viene rifatto al prossimo giro
        $affected = $this->helper->markIdentifiersAsProcessed(
            $this->shardId,
            $this->priority,
            $this->connection_name,
            $identifiersByType,
            $processingStartTime,
            $batchId
        );
        if ($affected === 0) {
            throw new \RuntimeException('Nessun evento marcato come processed per il batch ' . $batchId . ': interrompo per evitare di rielaborare all\'infinito gli stessi eventi.');
        }

        $this->totalChunks++;
        $this->totalIdentifiers += count($chunk);
        $this->totalEvents += $affected;

        // Gli eventi sono già marcati con questo batch_ID: l'evento va emesso SEMPRE, anche se nel frattempo il lock
        // è scaduto, altrimenti il progetto non saprebbe mai di dover pulire la CDN per questi identificatori
        $lockStillOwned = $this->refreshLock();
        // Avviso il progetto che le chiavi/tags sono stati puliti, per cui può procedere alla pulizia della CDN
        event(new BatchCompletedEvent($batchId, $this->shardId, $identifiers, $this->connection_name));
        if (!$lockStillOwned) {
            $this->throwLockLost();
        }
        $this->refreshLockOrFail();

        $this->logIf(sprintf(
            'Chunk %d completato: %d identificatori, %d eventi marcati, batch %s, elapsed %.1fs, peak memory %s',
            $this->totalChunks,
            count($chunk),
            $affected,
            $batchId,
            microtime(true) - $this->runStartedAt,
            $this->formatBytes(memory_get_peak_usage(true))
        ));
    }

    /**
     * Invalidate cache keys.
     *
     * @param array<int, array{identifier: string, connection_name: string}> $keys
     */
    protected function invalidateKeys(array $keys): void
    {
        $callback = config('super_cache_invalidate.key_invalidation_callback');

        foreach ($keys as $item) {
            // Metodo del progetto
            if (is_callable($callback)) {
                $callback($item['identifier'], $item['connection_name']);
                continue;
            }
            // oppure di default uso Laravel
            $storeName = $this->getStoreFromConnectionName($item['connection_name']);
            if ($storeName === null) {
                continue;
            }
            Cache::store($storeName)->forget($item['identifier']);
        }
    }

    /**
     * Invalidate cache tags, grouped by connection.
     *
     * @param array<int, array{identifier: string, connection_name: string}> $tags
     */
    protected function invalidateTags(array $tags): void
    {
        $callback = config('super_cache_invalidate.tag_invalidation_callback');

        $groupByConnection = [];
        foreach ($tags as $item) {
            $groupByConnection[$item['connection_name']][] = $item['identifier'];
        }

        foreach ($groupByConnection as $connection_name => $arrTags) {
            if (is_callable($callback)) {
                $callback($arrTags, (string) $connection_name);
                continue;
            }
            $storeName = $this->getStoreFromConnectionName((string) $connection_name);
            if ($storeName === null) {
                continue;
            }
            Cache::store($storeName)->tags($arrTags)->flush();
        }
    }

    protected function isTagExcludedFromFlush(string $tag): bool
    {
        foreach ((array) config('super_cache_invalidate.excluded_tag_patterns', ['fullpage']) as $pattern) {
            if ($pattern !== '' && str_contains($tag, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function getStoreFromConnectionName(string $connection_name): ?string
    {
        // Cerca il nome dello store associato alla connessione Redis
        foreach ((array) config('cache.stores') as $storeName => $storeConfig) {
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

    protected function shouldStop(): bool
    {
        if ($this->stopRequested) {
            if (!$this->stopLogged) {
                $this->stopLogged = true;
                $this->logIf('Stop richiesto (' . $this->stopReason . '): interrompo dopo il chunk corrente.');
            }

            return true;
        }
        if ($this->maxRuntime > 0 && (microtime(true) - $this->runStartedAt) >= $this->maxRuntime) {
            if (!$this->stopLogged) {
                $this->stopLogged = true;
                $this->logIf('Budget di tempo (' . $this->maxRuntime . 's) esaurito: mi fermo, il prossimo run riprende da qui.');
            }

            return true;
        }

        return false;
    }

    /**
     * Extend the TTL of the shard lock. Returns false when the lock is no longer ours (expired or taken by
     * another process): in that case it is forgotten, so that it is not released by mistake.
     */
    protected function refreshLock(): bool
    {
        if ($this->lockValue === false) {
            return false;
        }
        if ($this->helper->refreshShardLock($this->shardId, $this->priority, $this->lockValue, $this->lockTimeout, $this->connection_name)) {
            return true;
        }
        $this->lockValue = false;

        return false;
    }

    protected function refreshLockOrFail(): void
    {
        if (!$this->refreshLock()) {
            $this->throwLockLost();
        }
    }

    protected function throwLockLost(): void
    {
        throw new \RuntimeException('Lock dello shard perso (scaduto o acquisito da un altro processo): interrompo l\'elaborazione. Aumentare lock_timeout o ridurre chunk_size.');
    }

    protected function releaseLock(): void
    {
        if ($this->lockValue === false) {
            return;
        }
        try {
            $this->helper->releaseShardLock($this->shardId, $this->priority, $this->lockValue, $this->connection_name);
        } catch (\Throwable $e) {
            $this->logError('Impossibile rilasciare il lock: ' . $e->getMessage() . ' (scadrà da solo tra ' . $this->lockTimeout . 's)');
        }
        $this->lockValue = false;
    }

    protected function logSummary(int $exitCode): void
    {
        $message = sprintf(
            'Fine Elaborazione - esito %s, chunk %d, identificatori %d, eventi marcati %d, tempo %.1fs, peak memory %s',
            $exitCode === self::SUCCESS ? 'OK' : 'ERRORE',
            $this->totalChunks,
            $this->totalIdentifiers,
            $this->totalEvents,
            microtime(true) - $this->runStartedAt,
            $this->formatBytes(memory_get_peak_usage(true))
        );
        if ($this->log_attivo || $this->totalChunks > 0 || $exitCode !== self::SUCCESS) {
            $this->line($this->prefix() . $message);
        }
        if ($this->totalChunks > 0 || $exitCode !== self::SUCCESS) {
            Log::info('SuperCacheInvalidate ' . $this->prefix() . $message);
        }
    }

    protected function prefix(): string
    {
        return now()->toDateTimeString() . ' Shard[' . $this->shardId . '] Priority[' . $this->priority . '] Connection[' . $this->connection_name . '] : ';
    }

    protected function logIf(string $message): void
    {
        if (!$this->log_attivo) {
            return;
        }
        $this->line($this->prefix() . $message);
    }

    /**
     * Errors are always written both on the console output (scheduler log) and on the Laravel log.
     */
    protected function logError(string $message): void
    {
        try {
            $this->error($this->prefix() . $message);
        } catch (\Throwable $e) {
            // output non disponibile (es. shutdown)
        }
        try {
            Log::error('SuperCacheInvalidate ' . $this->prefix() . $message);
        } catch (\Throwable $e) {
            // logger non disponibile
        }
    }

    protected function toTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return Carbon::parse((string) $value)->getTimestamp();
        }

        return $timestamp;
    }

    protected function formatBytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->resetState();
        $this->readOptions();
        $this->registerSafetyNets();

        // Un solo processo per shard: se il precedente sta ancora lavorando esco subito.
        // Il lock è atomico (SET NX EX) e viene rinfrescato ad ogni chunk; se il processo muore scade da solo.
        $this->lockValue = $this->helper->acquireShardLock($this->shardId, $this->priority, $this->lockTimeout, $this->connection_name);
        if ($this->lockValue === false) {
            $this->logIf('Lock già attivo: un altro processo sta ancora elaborando questo shard, esco senza fare nulla.');
            $this->finished = true;

            return self::SUCCESS;
        }

        $this->logIf(sprintf(
            'Starting Elaborazione - finestra %ds, limit %d, tagBatchSize %d, chunk %d, budget %ds, lock TTL %ds',
            $this->invalidation_window,
            $this->limit,
            $this->tagBatchSize,
            $this->chunkSize,
            $this->maxRuntime,
            $this->lockTimeout
        ));

        $exitCode = self::SUCCESS;
        try {
            $this->processEvents();
        } catch (\Throwable $e) {
            $exitCode = self::FAILURE;
            $this->logError(sprintf(
                "Eccezione: %s in %s on line %d\nStack trace:\n%s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
        } finally {
            $this->releaseLock();
        }

        $this->finished = true;
        $this->logSummary($exitCode);

        return $exitCode;
    }

    /**
     * Ask the command to stop cleanly after the current chunk (used by signal handlers, usable by listeners).
     */
    public function requestStop(string $reason = 'richiesta esterna'): void
    {
        $this->stopRequested = true;
        $this->stopReason = $reason;
    }
}
