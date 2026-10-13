<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

class SuperCacheInvalidationHelperTest extends TestCase
{
    protected SuperCacheInvalidationHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new SuperCacheInvalidationHelper();
    }

    /**
     * Helper whose pruned UPDATE (the one with partition_key in the WHERE) fails like MySQL does with literal SQL.
     */
    protected function helperFailingPrunedUpdateWith(int $driverCode, string $driverMessage): SuperCacheInvalidationHelper
    {
        return new class ($driverCode, $driverMessage) extends SuperCacheInvalidationHelper {
            /** @var bool[] true = tentativo con pruning, false = senza */
            public array $attempts = [];

            public function __construct(private int $driverCode, private string $driverMessage)
            {
            }

            protected function executeMarkUpdate($query, array $values): int
            {
                $pruned = str_contains($query->toSql(), 'partition_key');
                $this->attempts[] = $pruned;
                if ($pruned) {
                    $pdoException = new \PDOException(sprintf('SQLSTATE[HY000]: General error: %d %s', $this->driverCode, $this->driverMessage));
                    $pdoException->errorInfo = ['HY000', $this->driverCode, $this->driverMessage];

                    throw new QueryException('testing', $query->toSql(), $query->getBindings(), $pdoException);
                }

                return parent::executeMarkUpdate($query, $values);
            }
        };
    }

    protected function insertTwoPendingEventsOf(string $identifier, \Illuminate\Support\Carbon $before): void
    {
        foreach ([100, 50] as $age) {
            DB::table('cache_invalidation_events')->insert([
                'type' => 'tag',
                'identifier' => $identifier,
                'connection_name' => 'cache',
                'priority' => 0,
                'event_time' => $before->copy()->subSeconds($age)->toDateTimeString(),
                'processed' => 0,
                'shard' => 0,
                'partition_key' => 0,
            ]);
        }
    }

    public function test_insert_invalidation_event_uses_the_partition_when_partitions_are_enabled(): void
    {
        Config::set('super_cache_invalidate.use_partitions', true);
        $partition = $this->helper->getCacheInvalidationEventsUnprocessedPartitionName(0, 0);

        $now = now();
        $data = [
            'type' => 'key',
            'identifier' => 'zazzi',
            'connection_name' => 'default',
            'reason' => 'Article 7 removed',
            'priority' => 0,
            'event_time' => $now,
            'processed' => 0, // ATTENZIONE, poichè abbiamo solo 2 priorità, nel caso di priorità 1 verrà passato 1 perchè l'invalidazione la fa il progetto
            'shard' => 0,
        ];

        DB::shouldReceive('raw')
            ->once()
            ->with("`cache_invalidation_events` PARTITION ({$partition})")
            ->andReturn("`cache_invalidation_events` PARTITION ({$partition})")
        ;

        DB::shouldReceive('table')
            ->once()
            ->with("`cache_invalidation_events` PARTITION ({$partition})")
            ->andReturnSelf()
        ;

        DB::shouldReceive('insert')
            ->once()
            ->with($data)
            ->andReturn(true)
        ;

        $this->helper->insertInvalidationEvent(
            'key',
            'zazzi',
            'default',
            'Article 7 removed',
            1,
            0,
            0,
            $now,
            0,
        );
    }

    public function test_insert_invalidation_event_without_partitions_writes_the_row(): void
    {
        $this->createEventsTable();

        $this->helper->insertInvalidationEvent('tag', 'product:7', 'cache', 'Article 7 updated', 5, 0, 0, now(), 3);

        $row = DB::table('cache_invalidation_events')->first();
        $this->assertNotNull($row);
        $this->assertSame('tag', $row->type);
        $this->assertSame('product:7', $row->identifier);
        $this->assertSame('cache', $row->connection_name);
        $this->assertSame(3, (int) $row->shard);
        $this->assertSame(0, (int) $row->processed);
    }

    public function test_shard_is_computed_from_the_identifier_when_not_given(): void
    {
        $this->createEventsTable();

        $this->helper->insertInvalidationEvent('tag', 'product:7', 'cache', null, 5);

        $this->assertSame(crc32('product:7') % 5, (int) DB::table('cache_invalidation_events')->value('shard'));
    }

    public function test_lock_lifecycle_is_atomic_and_owner_bound(): void
    {
        $key = $this->helper->getShardLockKey(2, 0, 'cache');
        $connection = $this->redis->connection('cache');

        $token = $this->helper->acquireShardLock(2, 0, 600, 'cache');
        $this->assertIsString($token);
        $this->assertSame($token, $connection->get($key));
        $this->assertEqualsWithDelta(600, $connection->ttl($key), 2, 'il lock deve avere un TTL');

        // SET NX: un secondo processo non lo ottiene
        $this->assertFalse($this->helper->acquireShardLock(2, 0, 600, 'cache'));

        // refresh solo se sono il proprietario
        $this->assertTrue($this->helper->refreshShardLock(2, 0, $token, 900, 'cache'));
        $this->assertEqualsWithDelta(900, $connection->ttl($key), 2);
        $this->assertFalse($this->helper->refreshShardLock(2, 0, 'not-my-token', 900, 'cache'));

        // release solo se sono il proprietario
        $this->assertFalse($this->helper->releaseShardLock(2, 0, 'not-my-token', 'cache'));
        $this->assertSame($token, $connection->get($key));
        $this->assertTrue($this->helper->releaseShardLock(2, 0, $token, 'cache'));
        $this->assertNull($connection->get($key));

        // una volta rilasciato il refresh fallisce e si può riacquisire
        $this->assertFalse($this->helper->refreshShardLock(2, 0, $token, 900, 'cache'));
        $this->assertIsString($this->helper->acquireShardLock(2, 0, 600, 'cache'));
    }

    public function test_lock_is_isolated_per_connection_shard_and_priority(): void
    {
        $this->assertIsString($this->helper->acquireShardLock(0, 0, 600, 'cache'));
        $this->assertIsString($this->helper->acquireShardLock(1, 0, 600, 'cache'));
        $this->assertIsString($this->helper->acquireShardLock(0, 1, 600, 'cache'));
        $this->assertIsString($this->helper->acquireShardLock(0, 0, 600, 'other'));
        $this->assertFalse($this->helper->acquireShardLock(0, 0, 600, 'cache'));
    }

    public function test_lock_can_be_stored_on_a_dedicated_connection(): void
    {
        Config::set('super_cache_invalidate.lock_connection', 'locks');

        $token = $this->helper->acquireShardLock(0, 0, 600, 'cache');

        $this->assertIsString($token);
        $key = $this->helper->getShardLockKey(0, 0, 'cache');
        $this->assertSame($token, $this->redis->connection('locks')->get($key));
        $this->assertNull($this->redis->connection('cache')->get($key));
        $this->assertTrue($this->helper->releaseShardLock(0, 0, $token, 'cache'));
    }

    public function test_lock_uses_only_single_key_commands(): void
    {
        $token = $this->helper->acquireShardLock(0, 0, 600, 'cache');
        $this->helper->refreshShardLock(0, 0, $token, 600, 'cache');
        $this->helper->releaseShardLock(0, 0, $token, 'cache');

        $commands = array_column($this->redis->connection('cache')->calls, 0);
        $this->assertSame(['set', 'eval', 'eval'], $commands);
        foreach ($this->redis->connection('cache')->calls as [$command, $arguments]) {
            if ($command === 'eval') {
                $this->assertSame(1, $arguments[1], 'gli script Lua devono toccare una sola chiave (cluster safe)');
            }
        }
    }

    public function test_mark_identifiers_as_processed_marks_only_older_events_of_the_same_shard_and_connection(): void
    {
        $this->createEventsTable();
        $before = now();
        $insert = function (string $identifier, int $age, int $shard = 0, string $connection = 'cache', string $type = 'tag') use ($before) {
            DB::table('cache_invalidation_events')->insert([
                'type' => $type,
                'identifier' => $identifier,
                'connection_name' => $connection,
                'priority' => 0,
                'event_time' => $before->copy()->subSeconds($age)->toDateTimeString(),
                'processed' => 0,
                'shard' => $shard,
                'partition_key' => $shard,
            ]);
        };
        $insert('product:1', 100);
        $insert('product:1', 50);
        $insert('product:1', -100);          // futuro rispetto a $before: non va marcato
        $insert('product:1', 100, 1);        // altro shard
        $insert('product:1', 100, 0, 'x');   // altra connessione
        $insert('product:1', 100, 0, 'cache', 'key'); // altro type
        $insert('product:2', 100);           // altro identificatore

        $affected = $this->helper->markIdentifiersAsProcessed(0, 0, 'cache', ['tag' => ['product:1']], $before, 'batch-1');

        $this->assertSame(2, $affected);
        $this->assertSame(2, DB::table('cache_invalidation_events')->where('batch_ID', 'batch-1')->where('processed', 1)->count());
        $this->assertSame(5, DB::table('cache_invalidation_events')->where('processed', 0)->count());
    }

    public function test_fetch_unprocessed_events_returns_oldest_first_within_limit(): void
    {
        $this->createEventsTable();
        $now = now();
        foreach ([300, 100, 200] as $age) {
            DB::table('cache_invalidation_events')->insert([
                'type' => 'tag',
                'identifier' => 'age:' . $age,
                'connection_name' => 'cache',
                'priority' => 0,
                'event_time' => $now->copy()->subSeconds($age)->toDateTimeString(),
                'processed' => 0,
                'shard' => 0,
                'partition_key' => 0,
            ]);
        }

        $events = $this->helper->fetchUnprocessedEvents(0, 0, 'cache', $now, 2);

        $this->assertSame(['age:300', 'age:200'], array_column($events, 'identifier'));
    }

    public function test_mark_identifiers_redoes_the_chunk_without_pruning_when_mysql_raises_error_1748(): void
    {
        $this->createEventsTable();
        Config::set('super_cache_invalidate.use_partitions', true);
        $before = now();
        $this->insertTwoPendingEventsOf('product:1', $before);
        $helper = $this->helperFailingPrunedUpdateWith(1748, 'Found a row not matching the given partition set');
        Log::spy();

        $affected = $helper->markIdentifiersAsProcessed(0, 0, 'cache', ['tag' => ['product:1']], $before, 'batch-1748');

        $this->assertSame(2, $affected);
        $this->assertSame([true, false], $helper->attempts, 'prima con pruning, poi lo stesso chunk senza');
        $this->assertSame(2, DB::table('cache_invalidation_events')->where('batch_ID', 'batch-1748')->where('processed', 1)->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '1748'));
    }

    public function test_mark_identifiers_rethrows_any_other_database_error(): void
    {
        $this->createEventsTable();
        Config::set('super_cache_invalidate.use_partitions', true);
        $before = now();
        $this->insertTwoPendingEventsOf('product:1', $before);
        $helper = $this->helperFailingPrunedUpdateWith(1213, 'Deadlock found when trying to get lock');

        try {
            $helper->markIdentifiersAsProcessed(0, 0, 'cache', ['tag' => ['product:1']], $before, 'batch-deadlock');
            $this->fail('un errore diverso da 1748 deve essere rilanciato');
        } catch (QueryException $e) {
            $this->assertStringContainsString('1213', $e->getMessage());
        }
        $this->assertSame([true], $helper->attempts, 'nessun secondo tentativo');
        $this->assertSame(0, DB::table('cache_invalidation_events')->where('processed', 1)->count());
    }
}
