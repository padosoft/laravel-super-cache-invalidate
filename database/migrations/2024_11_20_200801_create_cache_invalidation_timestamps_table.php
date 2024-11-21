<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache_invalidation_timestamps', function (Blueprint $table) {
            $table->enum('identifier_type', ['key', 'tag'])->comment('Indicates whether the identifier is a cache key or tag');
            $table->string('identifier')->comment('The cache key or tag');
            $table->dateTime('last_invalidated')->comment('Timestamp of the last invalidation');

            // Partition key as a generated stored column
            $table->integer('partition_key')->storedAs('YEAR(`last_invalidated`) * 100 + WEEK(`last_invalidated`, 3)')->comment('Partition key based on last_invalidated');

            $table->primary(['identifier_type', 'identifier']);
            $table->index(['identifier_type', 'identifier'], 'idx_identifier_type_identifier');
            $table->index('partition_key', 'idx_partition_key');
        });

        // Generate partitions
        $partitionSQL = $this->generatePartitionSQL();

        // Add partitioning
        DB::statement("ALTER TABLE `cache_invalidation_timestamps` PARTITION BY RANGE (`partition_key`) (
            {$partitionSQL}
        );");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache_invalidation_timestamps');
    }

    /**
     * Generate partition SQL statements.
     *
     * @return string
     */
    protected function generatePartitionSQL(): string
    {
        $startYear = 2024;
        $endYear = 2050;

        $partitionStatements = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            for ($week = 1; $week <= 53; $week++) {
                $partitionKey = $year * 100 + $week;
                $nextPartitionKey = $partitionKey + 1;

                $partitionName = "p_{$year}w{$week}";
                $partitionStatements[] = "PARTITION {$partitionName} VALUES LESS THAN ({$nextPartitionKey})";
            }
        }

        // Final partition for any values beyond defined partitions
        $partitionStatements[] = "PARTITION pMax VALUES LESS THAN MAXVALUE";

        // Combine all partition statements
        return implode(",\n", $partitionStatements);
    }
};
