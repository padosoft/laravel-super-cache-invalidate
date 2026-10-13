<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\SuperCacheInvalidate\SuperCacheInvalidateServiceProvider;
use Padosoft\SuperCacheInvalidate\Test\Support\FakeRedisManager;

abstract class TestCase extends Orchestra
{
    protected FakeRedisManager $redis;

    protected function setUp(): void
    {
        parent::setUp();

        // Il RedisServiceProvider registra il RedisManager vero DOPO getEnvironmentSetUp (singleton -> dropStaleInstances),
        // quindi il fake va legato ad app già avviata e la facade va svuotata
        $this->redis = new FakeRedisManager();
        $this->app->instance('redis', $this->redis);
        Redis::clearResolvedInstance('redis');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreExceptionHandler();
    }

    protected function restoreExceptionHandler(): void
    {
        while (true) {
            $previousHandler = set_exception_handler(static fn () => null);

            restore_exception_handler();

            if ($previousHandler === null) {
                break;
            }

            restore_exception_handler();
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            SuperCacheInvalidateServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Redis in memoria (vedi setUp): supporta solo comandi single-key, come deve fare il package (cluster safe)
        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
            'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        ]);

        // Database SQLite in memoria (niente partizioni MySQL)
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('super_cache_invalidate.use_partitions', false);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores', [
            'array' => [
                'driver' => 'array',
            ],
            'redis-store-1' => [
                'driver' => 'redis',
                'connection' => 'default',
            ],
        ]);

        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
    }

    /**
     * Same columns of the package migration, without partitioning (SQLite).
     */
    protected function createEventsTable(): void
    {
        Schema::dropIfExists('cache_invalidation_events');
        Schema::create('cache_invalidation_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('batch_ID', 36)->nullable();
            $table->string('type');
            $table->string('identifier');
            $table->string('connection_name');
            $table->string('reason')->nullable();
            $table->tinyInteger('priority')->default(0);
            $table->dateTime('event_time')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->boolean('processed')->default(0);
            $table->integer('shard')->default(0);
            $table->integer('partition_key')->default(0);
        });
    }
}
