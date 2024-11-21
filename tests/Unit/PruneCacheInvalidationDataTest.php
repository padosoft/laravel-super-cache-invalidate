<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\DB;
use Padosoft\SuperCacheInvalidate\Console\PruneCacheInvalidationDataCommand;

class PruneCacheInvalidationDataTest extends TestCase
{
    protected PruneCacheInvalidationDataCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new PruneCacheInvalidationDataCommand();
    }

    public function testPruneTables(): void
    {
        // Mock DB queries
        DB::shouldReceive('select')
            ->times(3)
            ->andReturn([
                (object)[
                    'PARTITION_NAME' => 'p202401',
                    'PARTITION_DESCRIPTION' => '202401',
                ],
            ])
        ;

        DB::shouldReceive('statement')->times(3);

        // Run the command
        $this->command->handle();

        // Assertions are handled by Mockery expectations
    }
}
