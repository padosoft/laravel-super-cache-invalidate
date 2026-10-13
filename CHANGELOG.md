# Changelog

All Notable changes to `laravel-super-cache-invalidate` will be documented in this file


## 2.2.0 - 2026-10-13

### Fixed
- `supercache:process-invalidation` never completed on large backlogs: the whole batch was flushed on Redis before any
  event was marked as processed, so a killed/overlapping process re-fetched the same oldest events forever. Progress is
  now durable per chunk (`chunk_size`).
- Carbon 3: `diffInSeconds()` is signed, so the "small sites" rule (flush everything when few identifiers are pending)
  had become dead code. Ages are now computed on timestamps.
- Event grouping was O(n²) (`array_filter` inside a `foreach`): now O(n).
- The shard lock was not atomic (GET + SET + EXPIRE) and was disabled. It is now an atomic `SET NX EX`, refreshed after
  every chunk, released with an owner check, and a run that finds it busy exits immediately. Single-key commands only
  (Redis Cluster safe).
- Marking events as processed also covers the pending events of the same identifiers that were not in the fetched page.
- `BatchCompletedEvent` is dispatched as soon as the events of the chunk are marked, even if the shard lock turns out
  to be lost right after: otherwise the listeners (CDN purge) would never learn about that batch.
- `markIdentifiersAsProcessed()`: the UPDATE pruned with `partition_key` is refused by MySQL with error 1748 ("Found a
  row not matching the given partition set") when the SQL reaches the server with literal values (e.g. PDO
  `ATTR_EMULATE_PREPARES => true`); the chunk is now redone without pruning, with a warning in the log.

### Added
- Config `chunk_size`, `max_runtime`, `lock_connection`, `use_partitions`, `excluded_tag_patterns`; options
  `--chunk-size` and `--max-runtime`.
- Clean stop on `SIGTERM`/`SIGINT` and on the `max_runtime` budget (the next run resumes); shutdown function that logs
  fatal errors with the peak memory; errors mirrored on the Laravel log; end-of-run summary.
- `BatchCompletedEvent` now carries the `identifiers` of the batch and the `connection_name`.
- Migration adding the `idx_batch_id` index on `cache_invalidation_events.batch_ID` (listeners query by batch).
- Unit tests on SQLite + in-memory Redis, and integration tests of the lock against a real Redis standalone/cluster
  (`SUPERCACHE_TEST_REDIS_HOST`, `SUPERCACHE_TEST_REDIS_CLUSTER`).

### Changed
- `SuperCacheInvalidationHelper::releaseShardLock()` returns `bool`; new `refreshShardLock()`, `getShardLockKey()`,
  `fetchUnprocessedEvents()`, `markIdentifiersAsProcessed()`, `eventsTable()`.
- Log lines are single-line (previously header + message on two lines, which interleaved between processes).
- Scheduling: remove `withoutOverlapping()` from the scheduled command, the package lock replaces it.
- The `idx_batch_id` migration creates nothing when the table already has an index starting with `batch_ID` (whatever
  its name), and `down()` drops only its own index.


## 1.0.0 - 2024-11-20

- Initial release
