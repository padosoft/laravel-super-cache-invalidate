<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

class ProcessCacheInvalidationEventsTest extends TestCase
{
    protected SuperCacheInvalidationHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new SuperCacheInvalidationHelper();
    }

    public function test_process_events_with_associated_identifiers_within_window(): void
    {
        // Mock data
        $now = now();
        $events = collect([
            (object)[
                'id' => 1,
                'type' => 'key',
                'identifier' => 'article_ID:7',
                'reason' => 'Article 7 removed',
                'connection_name' => 'default',
                'shard' => 0,
                'priority' => 0,
                'event_time' => $now,
                'partition_key' => 0,
            ],
        ]);

        Config::set('super_cache_invalidate.invalidation_window', 0);

        // Recupera il valore di configurazione per verificare che sia stato impostato
        $value = config('super_cache_invalidate.invalidation_window');

        // Asserzione
        $this->assertEquals(0, $value);

        $partitionCache_invalidation_events = $this->helper->getCacheInvalidationEventsUnprocessedPartitionName(0, 0);

        DB::shouldReceive('raw')
            ->once()
            ->with("`cache_invalidation_events` PARTITION ({$partitionCache_invalidation_events})")
            ->andReturn("`cache_invalidation_events` PARTITION ({$partitionCache_invalidation_events})")
        ;

        DB::shouldReceive('table')
            ->once()
            ->with("`cache_invalidation_events` PARTITION ({$partitionCache_invalidation_events})")
            ->andReturnSelf()
        ;

        DB::shouldReceive('select')
            ->once()
            ->with(['id', 'type', 'identifier', 'connection_name', 'partition_key', 'event_time'])
            ->andReturnSelf()
        ;

        DB::shouldReceive('where')
            ->once()
            ->with('processed', '=', 0)
            ->andReturnSelf()
        ;

        DB::shouldReceive('where')
            ->once()
            ->with('shard', '=', 0)
            ->andReturnSelf()
        ;

        DB::shouldReceive('where')
            ->once()
            ->with('priority', '=', 0)
            ->andReturnSelf()
        ;

        DB::shouldReceive('where')
            ->once()
            ->with('event_time', '<', Mockery::any())
            ->andReturnSelf()
        ;

        DB::shouldReceive('where')
            ->once()
            ->with('connection_name', '=', 'default')
            ->andReturnSelf()
        ;

        DB::shouldReceive('orderBy')
            ->once()
            ->with('event_time')
            ->andReturnSelf()
        ;

        DB::shouldReceive('limit')
            ->once()
            ->with(1)
            ->andReturnSelf()
        ;

        DB::shouldReceive('get')
            ->once()
            ->andReturn($events)
        ;

        // DB::shouldReceive('toArray')
        //    ->once()
        //    ->andReturn($events->toArray())
        // ;

        // CHIAVE
        // Mock Cache
        Cache::shouldReceive('store')
            ->with('redis-store-1') // Nome dello store
            ->once()
            ->andReturn(Mockery::mock(\Illuminate\Contracts\Cache\Repository::class, function ($mock) {
                // Mockiamo il comportamento del repository cache
                $mock->shouldReceive('forget')
                    ->withArgs(function ($key) {
                        // controllo che la chiave sia proprio quella
                        $this->assertEquals('article_ID:7', $key);

                        return true; // Indica che l'argomento è accettabile
                    })
                    ->once()
                    ->andReturn(true)
                ;
            }))
        ;


        // TAG
        // Mock Cache
        /*
        Cache::shouldReceive('store')
             ->with('redis-store-1') // Nome dello store
             ->once()
             ->andReturn(Mockery::mock(\Illuminate\Contracts\Cache\Repository::class, function ($mock) {
                 // Mockiamo il comportamento del repository cache
                 $mock->shouldReceive('tags')
                      ->withArgs(function ($tags) {
                          // controllo che la chiave sia proprio quella
                          $this->assertEquals(['plp:sport'], $tags);
                          return true; // Indica che l'argomento è accettabile
                      })
                      ->once()
                     ->andReturn(Mockery::mock(\Illuminate\Cache\TaggedCache::class, function ($taggedCacheMock) {
                         $taggedCacheMock->shouldReceive('flush')
                                         ->once()
                                         ->andReturn(true);
                     }));
             }));
        */
        // Mock event update

        // Mock DB::statement
        DB::shouldReceive('statement')
            ->atLeast()->once()
            ->with('SET FOREIGN_KEY_CHECKS=0;')
            ->andReturn(true)
        ;

        DB::shouldReceive('statement')
            ->atLeast()->once()
            ->with('SET UNIQUE_CHECKS=0;')
            ->andReturn(true)
        ;

        // Mock the DB facade
        DB::shouldReceive('table')
            ->once()
            ->with('cache_invalidation_events')
            ->andReturnSelf()
        ;

        DB::shouldReceive('whereIn')
            ->once()
            ->andReturnSelf()
        ;

        DB::shouldReceive('whereIn')
            ->once()
            ->andReturnSelf()
        ;

        DB::shouldReceive('update')
            ->once()
            ->andReturn(1) // Simulate the number of rows updated
        ;


        // Mock DB::statement
        DB::shouldReceive('statement')
            ->atLeast()->once()
            ->with('SET FOREIGN_KEY_CHECKS=1;')
            ->andReturn(true)
        ;

        DB::shouldReceive('statement')
            ->atLeast()->once()
            ->with('SET UNIQUE_CHECKS=1;')
            ->andReturn(true)
        ;

        // DB::shouldReceive('commit')->once();
        Redis::connection('default')->del('shard_lock:0_0');
        // Run the command
        // Run the command
        $this->artisan('supercache:process-invalidation', [
            '--shard' => 0,
            '--priority' => 0,
            '--limit' => 1,
            '--tag-batch-size' => 1,
            '--connection_name' => 'default',
        ]);
    }
}
