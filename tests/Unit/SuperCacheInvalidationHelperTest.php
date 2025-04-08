<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Support\Facades\DB;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

class SuperCacheInvalidationHelperTest extends TestCase
{
    protected SuperCacheInvalidationHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new SuperCacheInvalidationHelper();
    }

    public function test_insert_invalidation_event(): void
    {
        $partition = $this->helper->getCacheInvalidationEventsUnprocessedPartitionName(0, 0);

        // Mock DB insert
        // DB::shouldReceive('table->insert')->once();
        // Mock DB facade
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
            $now
        );
    }

    // TODO: da riattivare quando si implemntano per bene le associazioni
    /*
    public function testInsertInvalidationEventWithAssociations(): void
    {
        // Mock DB insert
        DB::shouldReceive('table->insertGetId')->once()->andReturn(1);
        DB::shouldReceive('table->insert')->once();

        $this->helper->insertInvalidationEvent(
            'key',
            'article_ID:7',
            'default',
            'Article 7 removed',
            0,
            0,
            [
                ['type' => 'tag', 'identifier' => 'plp:sport', 'connection_name' => 'default'],
            ]
        );
    }
    */
}
