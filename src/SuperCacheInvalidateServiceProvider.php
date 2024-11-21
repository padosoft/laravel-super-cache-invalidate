<?php

namespace Padosoft\SuperCacheInvalidate;

use Illuminate\Support\ServiceProvider;
use Padosoft\SuperCacheInvalidate\Console\ProcessCacheInvalidationEventsCommand;
use Padosoft\SuperCacheInvalidate\Console\PruneCacheInvalidationDataCommand;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;

class SuperCacheInvalidateServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/super_cache_invalidate.php',
            'super_cache_invalidate'
        );

        // Register the helper as a singleton
        $this->app->singleton('supercache.invalidation', function ($app) {
            return new SuperCacheInvalidationHelper();
        });
    }

    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/super_cache_invalidate.php' => config_path('super_cache_invalidate.php'),
        ], 'config');

        // Publish migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessCacheInvalidationEventsCommand::class,
                PruneCacheInvalidationDataCommand::class,
            ]);
        }
    }
}
