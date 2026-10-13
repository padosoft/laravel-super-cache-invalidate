<?php

namespace Padosoft\SuperCacheInvalidate\Test\Integration;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;
use Padosoft\SuperCacheInvalidate\SuperCacheInvalidateServiceProvider;

/**
 * Runs the shard lock against a REAL Redis (standalone and/or cluster) to prove that only
 * cluster-safe commands are used. Skipped unless these env vars are set:
 *   SUPERCACHE_TEST_REDIS_HOST / SUPERCACHE_TEST_REDIS_PORT   -> standalone node
 *   SUPERCACHE_TEST_REDIS_CLUSTER=host:port,host:port,...      -> cluster nodes
 *   SUPERCACHE_TEST_REDIS_PASSWORD (optional), SUPERCACHE_TEST_REDIS_CLIENT (phpredis|predis, default phpredis)
 */
class RedisShardLockIntegrationTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SuperCacheInvalidateServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $password = env('SUPERCACHE_TEST_REDIS_PASSWORD') ?: null;
        $redis = [
            'client' => env('SUPERCACHE_TEST_REDIS_CLIENT', 'phpredis'),
            'options' => [
                'cluster' => 'redis',
                'prefix' => 'sci_test_',
            ],
        ];
        if (env('SUPERCACHE_TEST_REDIS_HOST')) {
            $redis['standalone'] = [
                'host' => env('SUPERCACHE_TEST_REDIS_HOST'),
                'port' => (int) env('SUPERCACHE_TEST_REDIS_PORT', 6379),
                'database' => (int) env('SUPERCACHE_TEST_REDIS_DB', 0),
                'password' => $password,
            ];
        }
        if (env('SUPERCACHE_TEST_REDIS_CLUSTER')) {
            $redis['clusters']['cluster'] = array_map(function (string $node) use ($password) {
                [$host, $port] = explode(':', trim($node));

                return ['host' => $host, 'port' => (int) $port, 'password' => $password];
            }, explode(',', (string) env('SUPERCACHE_TEST_REDIS_CLUSTER')));
        }
        $app['config']->set('database.redis', $redis);
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]);
    }

    protected function assertLockLifecycle(string $connection): void
    {
        $helper = new SuperCacheInvalidationHelper();
        $redis = Redis::connection($connection);
        // più shard: in cluster le chiavi finiscono su slot (e nodi) diversi
        foreach ([0, 1, 2, 3, 4] as $shard) {
            $key = $helper->getShardLockKey($shard, 0, $connection);
            $redis->del($key);

            $token = $helper->acquireShardLock($shard, 0, 30, $connection);
            $this->assertIsString($token, "shard $shard: lock non acquisito");
            $this->assertSame($token, $redis->get($key));
            $ttl = (int) $redis->ttl($key);
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(30, $ttl);

            $this->assertFalse($helper->acquireShardLock($shard, 0, 30, $connection), "shard $shard: il lock deve essere esclusivo");

            $this->assertTrue($helper->refreshShardLock($shard, 0, $token, 60, $connection));
            $this->assertGreaterThan(30, (int) $redis->ttl($key));
            $this->assertFalse($helper->refreshShardLock($shard, 0, 'not-mine', 60, $connection));

            $this->assertFalse($helper->releaseShardLock($shard, 0, 'not-mine', $connection));
            $this->assertSame($token, $redis->get($key));
            $this->assertTrue($helper->releaseShardLock($shard, 0, $token, $connection));
            $this->assertNull($redis->get($key) ?: null);
            $this->assertFalse($helper->refreshShardLock($shard, 0, $token, 60, $connection));
        }
    }

    public function test_lock_lifecycle_on_standalone_redis(): void
    {
        if (!env('SUPERCACHE_TEST_REDIS_HOST')) {
            $this->markTestSkipped('SUPERCACHE_TEST_REDIS_HOST non impostato');
        }
        $this->assertLockLifecycle('standalone');
    }

    public function test_lock_lifecycle_on_redis_cluster(): void
    {
        if (!env('SUPERCACHE_TEST_REDIS_CLUSTER')) {
            $this->markTestSkipped('SUPERCACHE_TEST_REDIS_CLUSTER non impostato');
        }
        $this->assertLockLifecycle('cluster');
    }
}
