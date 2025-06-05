<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('cache_invalidation_events', 'batch_ID')) {
            return;
        }

        Schema::table('cache_invalidation_events', function (Blueprint $table) {
            $table->char('batch_ID', 36)->nullable()->index()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('cache_invalidation_events', 'batch_ID')) {
            return;
        }
        Schema::table('cache_invalidation_events', function (Blueprint $table) {
            $table->dropColumn('batch_ID');
        });
    }
};
