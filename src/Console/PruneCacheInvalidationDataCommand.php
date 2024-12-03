<?php

namespace Padosoft\SuperCacheInvalidate\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCacheInvalidationDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'supercache:prune-invalidation-data
                            {--months=1 : The number of months to retain data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old cache invalidation data by dropping partitions';

    /**
     * Prune partitions in a table older than the retention partition key.
     *
     * @param string $tableName             The name of the table
     * @param int    $retentionPartitionKey The partition key cutoff
     */
    protected function pruneTable(string $tableName, int $retentionPartitionKey, ?int $minPartitionValueToExclude = 0): void
    {
        // Fetch partition names
        $partitions = $this->getPartitionsFromDb($tableName, $retentionPartitionKey, $minPartitionValueToExclude);

        if (empty($partitions)) {
            $this->info("No partitions to prune for table {$tableName}.");

            return;
        }

        // Build DROP PARTITION statement
        $partitionNames = implode(', ', array_map(function ($partition) {
            return $partition->PARTITION_NAME;
        }, $partitions));

        DB::statement("ALTER TABLE `{$tableName}` DROP PARTITION {$partitionNames}");
        $this->info("Pruned partitions: {$partitionNames} from table {$tableName}.");
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $months = (int) $this->option('months');
        $retentionDate = now()->subMonths($months);

        // Prune tables
        $this->pruneTable('cache_invalidation_timestamps', ($retentionDate->year * 100 + $retentionDate->week) + 1);
        $this->pruneTable('cache_invalidation_event_associations', ($retentionDate->year * 100 + $retentionDate->week) + 1);

        $shards = config('super_cache_invalidate.total_shards', 10);
        $priorities = [0, 1];

        $minPartitionValueToExclude = 0;
        foreach ($priorities as $priority) {
            for ($shard = 0; $shard < $shards; $shard++) {
                $minPartitionValueToExclude = ($priority * $shards) + $shard + 1;
            }
        }
        $arrPartitionValues = [];
        foreach ($priorities as $priority) {
            for ($shard = 0; $shard < $shards; $shard++) {
                $arrPartitionValues[] = (($retentionDate->year * 10000) + ($retentionDate->week * 100) + ($priority * $shards) + $shard) + 1;
            }
        }
        $maxPartitionValueToInclude = max($arrPartitionValues);
        $this->pruneTable('cache_invalidation_events', $maxPartitionValueToInclude, $minPartitionValueToExclude);

        $this->info('Old cache invalidation data has been pruned.');
    }

    /**
     * @param string $tableName
     * @param int $maxPartitionValueToInclude
     * @return array
     */
    protected function getPartitionsFromDb(string $tableName, int $maxPartitionValueToInclude, $minPartitionValueToExclude): array
    {
        $partitions = DB::select('
            SELECT PARTITION_NAME, PARTITION_DESCRIPTION
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND PARTITION_NAME IS NOT NULL
            AND PARTITION_DESCRIPTION < ? AND PARTITION_DESCRIPTION > ?
        ', [$tableName, $maxPartitionValueToInclude, $minPartitionValueToExclude]);
        return $partitions;
    }
}
