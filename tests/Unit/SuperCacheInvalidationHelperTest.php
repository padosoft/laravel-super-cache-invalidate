<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use PHPUnit\Framework\TestCase;
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

    public function testInsertInvalidationEvent(): void
    {
        // Mock DB insert
        DB::shouldReceive('table->insertGetId')->once()->andReturn(1);

        $this->helper->insertInvalidationEvent('tag', 'test_tag', 'Test reason', 0);

        // Assertions are handled by Mockery expectations
    }

    public function testInsertInvalidationEventWithAssociations(): void
    {
        // Mock DB insert
        DB::shouldReceive('table->insertGetId')->once()->andReturn(1);
        DB::shouldReceive('table->insert')->once();

        $this->helper->insertInvalidationEvent(
            'tag',
            'article_ID:7',
            'Article 7 removed',
            0,
            [
                ['type' => 'tag', 'identifier' => 'plp:sport'],
            ]
        );

        // Assertions are handled by Mockery expectations
    }
}
