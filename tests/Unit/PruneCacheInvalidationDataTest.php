<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Support\Facades\DB;
use Padosoft\SuperCacheInvalidate\Console\PruneCacheInvalidationDataCommand;

class PruneCacheInvalidationDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testPruneTables(): void
    {
        // Mock DB queries
        $retentionDate = now()->subMonths(1);
        DB::shouldReceive('select')
            ->times(3)
            ->andReturn([
                (object)[
                    'PARTITION_NAME' => 'p_s0_p0_2024w1',
                    'PARTITION_DESCRIPTION' => ($retentionDate->year * 10000) + ($retentionDate->week * 100) + 1,
                ],
            ])
        ;

        DB::shouldReceive('statement')->times(3);

        // Run the command
        $this->artisan('supercache:prune-invalidation-data', [
            '--months' => 1,
        ]);

        // Assertions are handled by Mockery expectations
    }
}
