<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBatchIdIndexMigrationTest extends TestCase
{
    public function test_migration_adds_and_removes_the_batch_id_index_idempotently(): void
    {
        $this->createEventsTable();
        $migration = include __DIR__ . '/../../database/migrations/2026_10_13_100000_add_batch_id_index_to_cache_invalidation_events.php';

        $this->assertFalse(Schema::hasIndex('cache_invalidation_events', 'idx_batch_id'));

        $migration->up();
        $this->assertTrue(Schema::hasIndex('cache_invalidation_events', 'idx_batch_id'));

        // idempotente: non deve fallire se l'indice esiste già
        $migration->up();
        $this->assertTrue(Schema::hasIndex('cache_invalidation_events', 'idx_batch_id'));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('cache_invalidation_events', 'idx_batch_id'));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('cache_invalidation_events', 'idx_batch_id'));
    }

    public function test_migration_is_a_noop_when_the_table_does_not_exist(): void
    {
        Schema::dropIfExists('cache_invalidation_events');
        $migration = include __DIR__ . '/../../database/migrations/2026_10_13_100000_add_batch_id_index_to_cache_invalidation_events.php';

        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasTable('cache_invalidation_events'));
    }

    public function test_migration_does_not_duplicate_an_existing_index_on_batch_id_with_another_name(): void
    {
        $this->createEventsTable();
        Schema::table('cache_invalidation_events', function (Blueprint $table) {
            $table->index('batch_ID'); // nome di default Laravel: cache_invalidation_events_batch_id_index
        });
        $migration = include __DIR__ . '/../../database/migrations/2026_10_13_100000_add_batch_id_index_to_cache_invalidation_events.php';

        $migration->up();

        $this->assertFalse(Schema::hasIndex('cache_invalidation_events', 'idx_batch_id'), 'un indice su batch_ID esiste già: non va duplicato');
        $this->assertTrue(Schema::hasIndex('cache_invalidation_events', 'cache_invalidation_events_batch_id_index'));

        // down non deve toccare un indice che non ha creato lei
        $migration->down();
        $this->assertTrue(Schema::hasIndex('cache_invalidation_events', 'cache_invalidation_events_batch_id_index'));
    }
}
