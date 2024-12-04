<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
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

    public function testProcessEventsWithAssociatedIdentifiersWithinWindow(): void
    {
        // Mock data
        $events = collect([
            (object)[
                'id' => 1,
                'type' => 'key',
                'identifier' => 'article_ID:7',
                'reason' => 'Article 7 removed',
                'connection_name' => 'default',
                'shard' => 1,
                'priority' => 1
            ],
        ]);

        $associations = collect([
            (object)[
                'event_id' => 1,
                'associated_type' => 'tag',
                'associated_identifier' => 'plp:sport',
                'connection_name' => 'default',
            ],
        ]);

        // Mock DB queries
        DB::shouldReceive('table->where->where->where->where->where->orderBy->limit->get')
            ->andReturn($events)
        ;

        DB::shouldReceive('table->whereIn->get->groupBy')
            ->andReturn($associations)
        ;

        // Mock last invalidation times
        DB::shouldReceive('select')
            ->andReturn([
            (object)[
                'identifier_type' => 'key',
                'identifier' => 'article_ID:7',
                'last_invalidated' => Carbon::now()->subSeconds(120)->toDateTimeString(),
            ],
            (object)[
                'identifier_type' => 'tag',
                'identifier' => 'plp:sport',
                'last_invalidated' => Carbon::now()->subSeconds(180)->toDateTimeString(),
            ],
        ]);

        // Mock update or insert
        DB::shouldReceive('table->updateOrInsert')->twice(); //1 per la chiave e 1 per il tag

        DB::shouldReceive('beginTransaction')->once();

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
                      ->andReturn(true);
             }));

        // TAG
        // Mock Cache
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

        // Mock event update
        DB::shouldReceive('table->whereIn->update')->once();

        DB::shouldReceive('commit')->once();

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
