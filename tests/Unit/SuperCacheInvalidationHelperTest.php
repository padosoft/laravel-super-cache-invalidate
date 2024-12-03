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

    public function testInsertInvalidationEvent(): void
    {
        // Mock DB insert
        DB::shouldReceive('table->insertGetId')->once()->andReturn(1);

        $this->helper->insertInvalidationEvent(
            'key',
            'test_tag',
            'default',
            'Article 7 removed',
            1,
            1);
    }

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
}
