<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Generate partition SQL statements.
     */
    protected function generatePartitionSQL(): string
    {
        $startYear = 2024;
        $endYear = 2030;
        $shards = config('super_cache_invalidate.total_shards', 10);
        $priorities = [0, 1]; // Adjust as needed

        $partitionStatements = [];

        // Partitions for unprocessed events
        foreach ($priorities as $priority) {
            for ($shard = 0; $shard < $shards; $shard++) {
                $partitionName = "p_unprocessed_s{$shard}_p{$priority}";
                $partitionValue = ($priority * $shards) + $shard + 1;
                $partitionStatements[] = "PARTITION {$partitionName} VALUES LESS THAN ({$partitionValue})";
            }
        }

        // Partitions for processed events
        for ($year = $startYear; $year <= $endYear; $year++) {
            for ($week = 1; $week <= 53; $week++) {
                foreach ($priorities as $priority) {
                    for ($shard = 0; $shard < $shards; $shard++) {
                        $partitionKey = ($year * 10000) + ($week * 100) + ($priority * $shards) + $shard;
                        $nextPartitionKey = $partitionKey + 1;
                        $partitionName = "p_s{$shard}_p{$priority}_{$year}w{$week}";
                        $partitionStatements[] = "PARTITION {$partitionName} VALUES LESS THAN ({$nextPartitionKey})";
                    }
                }
            }
        }

        // Final partition for any values beyond defined partitions
        $partitionStatements[] = 'PARTITION pMax VALUES LESS THAN MAXVALUE';

        // Combine all partition statements
        return implode(",\n", $partitionStatements);
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('cache_invalidation_events')) {
            Schema::create('cache_invalidation_events', function (Blueprint $table) {
                //$table->bigIncrements('id');
                $table->bigInteger('id')->unsigned(); // Definiamo l'ID come bigInteger senza autoincrement sennò la primarykey multipla non funziona
                $table->enum('type', ['key', 'tag'])->comment('Indicates whether the event is for a cache key or tag');
                $table->string('identifier')->comment('The cache key or tag to invalidate');
                $table->string('reason')->nullable()->comment('Reason for the invalidation (for logging purposes)');
                $table->tinyInteger('priority')->default(0)->comment('Priority of the event');
                $table->dateTime('event_time')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Timestamp when the event was created');
                $table->boolean('processed')->default(0)->comment('Flag indicating whether the event has been processed');
                $table->integer('shard')->comment('Shard number for parallel processing');

                // Partition key as a generated stored column
                $table->integer('partition_key')->storedAs('
                CASE
                    WHEN `processed` = 0 THEN
                        (`priority` * `shard`) + `shard` + 1
                    ELSE
                        (YEAR(`event_time`) * 10000) + (WEEK(`event_time`, 3) * 100) + (`priority` * `shard`) + `shard`
                END
            ')->comment('Partition key for efficient querying and partitioning');

                // Indexes
                $table->index(['processed', 'shard', 'priority', 'partition_key', 'event_time'], 'idx_processed_shard_priority');
                $table->index(['type', 'identifier'], 'idx_type_identifier');
                $table->primary(['id', 'partition_key']);
            });
        }
        // Abilitare l'autoincrement manualmente
        DB::statement('ALTER TABLE cache_invalidation_events MODIFY id BIGINT UNSIGNED AUTO_INCREMENT');

        // Generate partitions using the PHP script
        $partitionSQL = $this->generatePartitionSQL();

        // Add partitioning
        DB::statement("ALTER TABLE `cache_invalidation_events` PARTITION BY RANGE (`partition_key`) (
            {$partitionSQL}
        );");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache_invalidation_events');
    }
};
