<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Total Shards
    |--------------------------------------------------------------------------
    |
    | The total number of shards to distribute events across for parallel
    | processing. Adjust based on your system's capacity and workload.
    |
    */
    'total_shards' => env('SUPERCACHE_INVALIDATE_TOTAL_SHARDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Invalidation Window (Seconds)
    |--------------------------------------------------------------------------
    |
    | The time window in seconds during which repeated invalidation events
    | for the same identifier (key or tag) are suppressed to prevent
    | redundant cache invalidations. An identifier is flushed only when its
    | oldest pending event is older than this window.
    |
    */
    'invalidation_window' => env('SUPERCACHE_INVALIDATE_INVALIDATION_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Event Processing Limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of events fetched from the database per iteration.
    | The command keeps iterating (within max_runtime) until the shard
    | partition has no more events due for invalidation.
    |
    */
    'processing_limit' => env('SUPERCACHE_INVALIDATE_PROCESSING_LIMIT', 10000),

    /*
    |--------------------------------------------------------------------------
    | Tag Batch Size
    |--------------------------------------------------------------------------
    |
    | When the number of unique identifiers fetched is lower than this value
    | and the oldest one has been waiting for at least two minutes, all of
    | them are flushed at once (small sites rule). Otherwise only the
    | identifiers whose oldest event is outside the invalidation window
    | are flushed.
    |
    */
    'tag_batch_size' => env('SUPERCACHE_INVALIDATE_TAG_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Number of unique identifiers flushed and marked as processed in a single
    | durable step. After each chunk the events are marked as processed in
    | the database, the BatchCompletedEvent is dispatched and the shard lock
    | is refreshed, so a process that dies loses at most one chunk of work.
    |
    */
    'chunk_size' => env('SUPERCACHE_INVALIDATE_CHUNK_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | Max Runtime (Seconds)
    |--------------------------------------------------------------------------
    |
    | Time budget of a single run. When exceeded the command stops cleanly
    | after the current chunk and releases the lock; the next scheduled run
    | resumes from where it stopped. 0 means no limit.
    |
    */
    'max_runtime' => env('SUPERCACHE_INVALIDATE_MAX_RUNTIME', 1200),

    /*
    |--------------------------------------------------------------------------
    | Lock Timeout (Seconds)
    |--------------------------------------------------------------------------
    |
    | TTL of the Redis lock that guarantees a single process per shard,
    | priority and connection. The lock is refreshed after every chunk, so a
    | live process never loses it; if the process is killed the lock expires
    | after this timeout and the next run takes over. It must be longer than
    | the worst-case duration of a single chunk.
    |
    */
    'lock_timeout' => env('SUPERCACHE_INVALIDATE_LOCK_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Lock Connection
    |--------------------------------------------------------------------------
    |
    | Redis connection name where shard locks are stored. When null the lock
    | is stored on the same connection being invalidated. Only single-key
    | commands are used (SET NX EX and single-key EVAL), so any connection,
    | including a Redis Cluster, is fine.
    |
    */
    'lock_connection' => env('SUPERCACHE_INVALIDATE_LOCK_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Use MySQL Partitions
    |--------------------------------------------------------------------------
    |
    | When true (default) reads and inserts use the explicit PARTITION clause
    | of the MySQL partitioned table created by the package migrations.
    | Set to false on databases without partitioning support (e.g. tests).
    |
    */
    'use_partitions' => env('SUPERCACHE_INVALIDATE_USE_PARTITIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Tags excluded from the Redis flush
    |--------------------------------------------------------------------------
    |
    | Tags whose identifier contains one of these substrings are NOT flushed
    | on Redis by this command (someone else refreshes them), but they are
    | still marked as processed and included in the BatchCompletedEvent.
    |
    */
    'excluded_tag_patterns' => ['fullpage'],

    /*
    |--------------------------------------------------------------------------
    | Custom Invalidation Callbacks
    |--------------------------------------------------------------------------
    |
    | Callbacks to customize the invalidation logic for keys and tags.
    | Set these to callable functions or leave as null to use default logic.
    | key callback signature: function (string $key, string $connection_name)
    | tag callback signature: function (array $tags, string $connection_name)
    |
    */
    'key_invalidation_callback' => env('SUPERCACHE_INVALIDATE_KEY_INVALIDATION_CALLBACK', null),
    'tag_invalidation_callback' => env('SUPERCACHE_INVALIDATE_TAG_INVALIDATION_CALLBACK', null),
    'default_connection_name' => env('SUPERCACHE_INVALIDATE_DEFAULT_CONNECTION_NAME', 'cache'),

];
