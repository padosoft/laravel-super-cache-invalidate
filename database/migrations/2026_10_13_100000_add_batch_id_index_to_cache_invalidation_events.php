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
 * If the table already has an index starting with batch_ID (whatever its name, e.g. one
 * added by hand or by the project) nothing is created: a duplicate index would only cost a
 * long ALTER and extra work on every write.
 */
return new class () extends Migration {
    private const TABLE = 'cache_invalidation_events';

    private const COLUMN = 'batch_ID';

    private const INDEX = 'idx_batch_id';

    /**
     * Whether the index created by this migration exists.
     */
    private function ownIndexExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return count(DB::select('SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = ?', [self::INDEX])) > 0;
        }

        if (method_exists(Schema::getFacadeRoot(), 'hasIndex')) {
            return Schema::hasIndex(self::TABLE, self::INDEX);
        }

        return false;
    }

    /**
     * Whether ANY index has batch_ID as its first column: it already serves the lookups by batch.
     */
    private function anyIndexOnColumnExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return count(DB::select('SHOW INDEX FROM `' . self::TABLE . '` WHERE Column_name = ? AND Seq_in_index = 1', [self::COLUMN])) > 0;
        }

        if ($this->ownIndexExists()) {
            return true;
        }

        if (method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
            foreach (Schema::getIndexes(self::TABLE) as $index) {
                if (strcasecmp((string) ($index['columns'][0] ?? ''), self::COLUMN) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }
        if ($this->anyIndexOnColumnExists()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index(self::COLUMN, self::INDEX);
        });
    }

    public function down(): void
    {
        // Only the index created by this migration is dropped, never someone else's
        if (!Schema::hasTable(self::TABLE) || !$this->ownIndexExists()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
