<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Padosoft\SuperCacheInvalidate\Console\ProcessCacheInvalidationEventsCommand;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;
use Carbon\Carbon;

class ProcessCacheInvalidationEventsTest extends TestCase
{
    protected ProcessCacheInvalidationEventsCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $helper = new SuperCacheInvalidationHelper();
        $this->command = new ProcessCacheInvalidationEventsCommand($helper);
    }

    public function testProcessEventsWithAssociatedIdentifiersWithinWindow(): void
    {
        // Mock data
        $events = collect([
            (object)[
                'id' => 1,
                'type' => 'tag',
                'identifier' => 'article_ID:7',
                'event_time' => Carbon::now()->subSeconds(10),
            ],
        ]);

        $associations = collect([
            (object)[
                'event_id' => 1,
                'associated_type' => 'tag',
                'associated_identifier' => 'plp:sport',
            ],
        ]);

        // Mock DB queries
        DB::shouldReceive('table->where->where->where->where->orderBy->limit->get')
            ->andReturn($events)
        ;

        DB::shouldReceive('table->whereIn->get')
            ->andReturn($associations)
        ;

        // Mock last invalidation times
        DB::shouldReceive('select')->andReturn([
            (object)[
                'identifier_type' => 'tag',
                'identifier' => 'article_ID:7',
                'last_invalidated' => Carbon::now()->subSeconds(40)->toDateTimeString(),
            ],
            (object)[
                'identifier_type' => 'tag',
                'identifier' => 'plp:sport',
                'last_invalidated' => Carbon::now()->subSeconds(20)->toDateTimeString(),
            ],
        ]);

        // Mock Cache
        Cache::shouldReceive('tags->flush')->never();

        // Mock update or insert
        DB::shouldReceive('table->updateOrInsert')->never();

        // Mock event update
        DB::shouldReceive('table->whereIn->update')->once();

        // Run the command
        $this->command->handle();

        // Assertions are handled by Mockery expectations
    }
}
