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
        Schema::create('cache_invalidation_event_associations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id')->comment('Reference to cache_invalidation_events.id');
            $table->enum('associated_type', ['key', 'tag'])->comment('Indicates if the associated identifier is a cache key or tag');
            $table->string('associated_identifier')->comment('The associated cache key or tag');
            $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Timestamp of association creation');

            // Partition key as a generated stored column
            $table->integer('partition_key')->storedAs('YEAR(`created_at`) * 100 + WEEK(`created_at`, 3)')->comment('Partition key based on created_at');

            // Indexes
            $table->index('event_id', 'idx_event_id');
            $table->index(['associated_type', 'associated_identifier'], 'idx_associated_type_identifier');
            $table->index('partition_key', 'idx_partition_key');

            // Foreign key constraint
            $table->foreign('event_id')->references('id')->on('cache_invalidation_events')->onDelete('cascade');
        });

        // Generate partitions
        $partitionSQL = $this->generatePartitionSQL();

        // Add partitioning
        DB::statement("ALTER TABLE `cache_invalidation_event_associations` PARTITION BY RANGE (`partition_key`) (
            {$partitionSQL}
        );");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache_invalidation_event_associations');
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
