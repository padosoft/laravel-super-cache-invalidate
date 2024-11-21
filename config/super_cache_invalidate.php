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
    | redundant cache invalidations.
    |
    */
    'invalidation_window' => env('SUPERCACHE_INVALIDATE_INVALIDATION_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Event Processing Limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of events to fetch per batch when processing
    | invalidation events.
    |
    */
    'processing_limit' => env('SUPERCACHE_INVALIDATE_PROCESSING_LIMIT', 10000),

    /*
    |--------------------------------------------------------------------------
    | Tag Batch Size
    |--------------------------------------------------------------------------
    |
    | The number of identifiers (keys or tags) to process in each batch
    | during cache invalidation.
    |
    */
    'tag_batch_size' => env('SUPERCACHE_INVALIDATE_TAG_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Lock Timeout (Seconds)
    |--------------------------------------------------------------------------
    |
    | The duration in seconds for which a shard lock is held during event
    | processing to prevent overlapping processes.
    |
    */
    'lock_timeout' => env('SUPERCACHE_INVALIDATE_LOCK_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Custom Invalidation Callbacks
    |--------------------------------------------------------------------------
    |
    | Callbacks to customize the invalidation logic for keys and tags.
    | Set these to callable functions or leave as null to use default logic.
    |
    */
    'key_invalidation_callback' => env('SUPERCACHE_INVALIDATE_KEY_INVALIDATION_CALLBACK', null),
    'tag_invalidation_callback' => env('SUPERCACHE_INVALIDATE_TAG_INVALIDATION_CALLBACK', null),

];
