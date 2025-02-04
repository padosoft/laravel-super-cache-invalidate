<?php

namespace Padosoft\SuperCacheInvalidate\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

class ProcessCacheInvalidationEventsCommand extends Command
{
    protected $signature = 'supercache:process-invalidation
                            {--shard= : The shard number to process}
                            {--priority= : The priority level}
                            {--limit= : The maximum number of events to fetch per batch}
                            {--tag-batch-size= : The number of identifiers to process per invalidation batch}
                            {--connection_name= : The Redis connection name}
                            {--log_attivo= : Indica se il log delle operazioni è attivo (0=no, 1=si)}';
    protected $description = 'Process cache invalidation events for a given shard and priority';
    protected SuperCacheInvalidationHelper $helper;
    protected bool $log_attivo = false;
    protected string $connection_name = 'cache';
    protected int $tagBatchSize = 100;
    protected int $limit = 1000;
    protected int $priority = 0;
    protected int $shardId = 0;
    protected int $invalidation_window = 600;

    public function __construct(SuperCacheInvalidationHelper $helper)
    {
        parent::__construct();
        $this->helper = $helper;
    }

    private function getEventsToInvalidate(Carbon $processingStartTime): array
    {
        $partitionCache_invalidation_events = $this->helper->getCacheInvalidationEventsUnprocessedPartitionName($this->shardId, $this->priority);
        return DB::table(DB::raw("`cache_invalidation_events` PARTITION ({$partitionCache_invalidation_events})"))
            ->select(['id', 'type', 'identifier', 'connection_name', 'partition_key', 'event_time'])
            ->where('processed', '=', 0)
            ->where('shard', '=', $this->shardId)
            ->where('priority', '=', $this->priority)
            // Cerco tutte le chiavi/tag da invalidare per questo database redis
            ->where('connection_name', '=', $this->connection_name)
            ->where('event_time', '<', $processingStartTime)
            ->orderBy('event_time')
            ->limit($this->limit)
            ->get()
            ->toArray()
        ;
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

    private function logIf(string $message, string $level = 'info')
    {
        if (!$this->log_attivo && $level === 'info') {
            return;
        }
        $this->$level(now()->toDateTimeString() . ' Shard[' . $this->shardId . '] Priority[' . $this->priority . '] Connection[' . $this->connection_name . '] : ' . PHP_EOL . $message);
    }

    protected function processEvents(): void
    {
        $processingStartTime = now();
        // Recupero gli eventi da invalidare
        $events = $this->getEventsToInvalidate($processingStartTime);
        $this->logIf('Trovati ' . count($events) . ' Eventi da invalidare');
        if (count($events) === 0) {
            return;
        }

        // Creiamo un array per memorizzare il valore più vecchio per ogni coppia (type, identifier) così elimino i doppioni che si sono accumulati nel tempo di apertura della finestra
        $unique_events = [];

        foreach ($events as $event) {
            $key = $event->type . ':' . $event->identifier; // Chiave univoca per type + identifier
            $event->event_time = \Illuminate\Support\Carbon::parse($event->event_time);
            // Quando la chiave non esiste o il nuovo valore ha un event_time più vecchio, lo sostituisco così a parità di tag ho sempre quello più vecchio e mi baso su quello per verificare la finestra
            if (!isset($unique_events[$key]) || $event->event_time <= $unique_events[$key]->event_time) {
                $unique_events[$key] = $event;
            }
        }

        $unique_events = array_values($unique_events);

        $this->logIf('Eventi unici ' . count($unique_events));
        // Quando il numero di eventi unici è inferiore al batchSize e quello più vecchio aspetta da almeno due minuti, mando l'invalidazione.
        // Questo serve per i siti piccoli che hanno pochi eventi, altrimenti si rischia di attendere troppo per invalidare i tags
        // In questo caso invalido i tag/key "unici" e setto a processed = 1 tutti quelli recuperati
        if (count($unique_events) < $this->tagBatchSize && $processingStartTime->diffInSeconds($unique_events[0]->event_time) >= 120) {
            $this->logIf('Il numero di eventi unici è inferiore al batchSize ( ' . $this->tagBatchSize . ' ) e sono passati più di due minuti, procedo');
            $this->processBatch($events, $unique_events);

            return;
        }

        // Altrimenti ho raggiunto il tagbatchsize e devo prendere solo quelli che hanno la finestra di invalidazione attiva
        $eventsToUpdate = [];
        $eventsAll = [];
        foreach ($unique_events as $event) {
            $elapsed = $processingStartTime->diffInSeconds($event->event_time);
            $typeFilter = $event->type;
            $identifierFilter = $event->identifier;
            if ($elapsed < $this->invalidation_window) {
                // Se la richiesta (cmq del più vecchio) non è nella finestra di invalidazione salto l'evento
                continue;
            }
            // altrimenti aggiungo l'evento a quelli da processare
            $eventsToUpdate[] = $event;
            // e recupero tutti gli ID che hanno quel tag/key
            $eventsAll[] = array_filter($events, function ($event) use ($typeFilter, $identifierFilter) {
                return $event->type === $typeFilter && $event->identifier === $identifierFilter;
            });
        }
        if (count($eventsToUpdate) === 0) {
            return;
        }
        $this->processBatch(array_merge(...$eventsAll), $eventsToUpdate);
    }

    protected function processBatch(array $allEvents, array $eventsToInvalidate): void
    {
        // Separo le chiavi dai tags
        $keys = [];
        $tags = [];

        foreach ($eventsToInvalidate as $item) {
            switch ($item->type) {
                case 'key':
                    $keys[] = $item->identifier . '§' . $item->connection_name;
                    break;
                case 'tag':
                    $tags[] = $item->identifier . '§' . $item->connection_name;
                    break;
            }
        }

        $this->logIf('Invalido ' . count($keys) . ' chiavi e ' . count($tags) . ' tags' . ' per un totale di ' . count($allEvents) . ' events_ID');

        if (!empty($keys)) {
            $this->invalidateKeys($keys);
        }

        if (!empty($tags)) {
            $this->invalidateTags($tags);
        }

        // Disabilita i controlli delle chiavi esterne e dei vincoli univoci
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::statement('SET UNIQUE_CHECKS=0;');

        // Mark event as processed
        // QUI NON VA USATA PARTITION perchè la cross partition è più lenta! Però è necessario utilizzare la $partition_key per sfruttare l'indice della primary key (id+partition_key)
        DB::table('cache_invalidation_events')
            ->whereIn('id', array_map(fn ($event) => $event->id, $allEvents))
            ->whereIn('partition_key', array_map(fn ($event) => $event->partition_key, $allEvents))
            ->update(['processed' => 1, 'updated_at' => now()])
        ;
        // Riattiva i controlli
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        DB::statement('SET UNIQUE_CHECKS=1;');
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
                continue;
            }
            // oppure di default uso Laravel
            $storeName =  $this->getStoreFromConnectionName($connection_name);

            if ($storeName === null) {
                continue;
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
                continue;
            }
            Cache::store($storeName)->tags($arrTags)->flush();
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Recupero dei parametri impostati nel command
        $this->shardId = (int) $this->option('shard');
        $this->priority = (int) $this->option('priority');
        $limit = $this->option('limit') ?? config('super_cache_invalidate.processing_limit');
        $this->limit = (int) $limit;
        $tagBatchSize = $this->option('tag-batch-size') ?? config('super_cache_invalidate.tag_batch_size');
        $this->tagBatchSize = (int) $tagBatchSize;
        $this->connection_name = $this->option('connection_name') ?? config('super_cache_invalidate.default_connection_name');
        $this->log_attivo = $this->option('log_attivo') && (int)$this->option('log_attivo') === 1;
        $this->invalidation_window = (int) config('super_cache_invalidate.invalidation_window');
        $lockTimeout = (int) config('super_cache_invalidate.lock_timeout');

        // Acquisisco il lock in modo da essere sicura che le esecuzioni non si accavallino
        $lockValue = $this->helper->acquireShardLock($this->shardId, $this->priority, $lockTimeout, $this->connection_name);
        $this->logIf('Starting Elaborazione ...' . $this->invalidation_window);
        if (!$lockValue) {
            return;
        }
        $startTime = microtime(true);
        try {
            $this->processEvents();
        } catch (\Throwable $e) {
            $this->logIf(sprintf(
                "Eccezione: %s in %s on line %d\nStack trace:\n%s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ), 'error');
        } finally {
            $this->helper->releaseShardLock($this->shardId, $this->priority, $lockValue, $this->connection_name);
        }
        $executionTime = (microtime(true) - $startTime) * 1000;
        $this->logIf('Fine Elaborazione - Tempo di esecuzione: ' . $executionTime . ' millisec.');
    }
}
