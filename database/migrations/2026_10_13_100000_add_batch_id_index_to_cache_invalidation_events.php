<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The BatchCompletedEvent listeners look up the processed events by batch_ID:
 * without an index that query scans every processed row of the shard.
 * On a large partitioned table this ALTER (ADD INDEX, online/INPLACE on InnoDB)
 * can take minutes: schedule the deploy accordingly.
 */
return new class () extends Migration {
    private const INDEX = 'idx_batch_id';

    private function indexExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return count(DB::select('SHOW INDEX FROM `cache_invalidation_events` WHERE Key_name = ?', [self::INDEX])) > 0;
        }

        if (method_exists(Schema::getFacadeRoot(), 'hasIndex')) {
            return Schema::hasIndex('cache_invalidation_events', self::INDEX);
        }

        return false;
    }

    public function up(): void
    {
        if (!Schema::hasTable('cache_invalidation_events') || !Schema::hasColumn('cache_invalidation_events', 'batch_ID')) {
            return;
        }
        if ($this->indexExists()) {
            return;
        }

        Schema::table('cache_invalidation_events', function (Blueprint $table) {
            $table->index('batch_ID', self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cache_invalidation_events') || !$this->indexExists()) {
            return;
        }

        Schema::table('cache_invalidation_events', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
