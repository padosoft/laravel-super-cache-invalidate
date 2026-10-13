<?php

namespace Padosoft\SuperCacheInvalidate\Test\Integration;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;
use Padosoft\SuperCacheInvalidate\SuperCacheInvalidateServiceProvider;

/**
 * Runs the shard lock against a REAL Redis (standalone and/or cluster), with and without the phpredis
 * `serializer`/`compression` options (the production cluster has LZ4 compression on every connection), to prove that
 * only plain single-key commands are used (no Lua) and that the owner check survives the client-side packing.
 * Skipped unless these env vars are set:
 *   SUPERCACHE_TEST_REDIS_HOST / SUPERCACHE_TEST_REDIS_PORT / SUPERCACHE_TEST_REDIS_DB -> standalone node
 *   SUPERCACHE_TEST_REDIS_CLUSTER=host:port,host:port,...                               -> cluster nodes
 *   SUPERCACHE_TEST_REDIS_PASSWORD (optional), SUPERCACHE_TEST_REDIS_CLIENT (phpredis|predis, default phpredis)
 */
class RedisShardLockIntegrationTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SuperCacheInvalidateServiceProvider::class];
    }

    /**
     * phpredis options that change what is stored on Redis (what the production cluster does with LZ4):
     * the PHP serializer is always available, LZ4 only when phpredis was built with it.
     */
    protected function packedOptions(): array
    {
        if (env('SUPERCACHE_TEST_REDIS_CLIENT', 'phpredis') !== 'phpredis' || !extension_loaded('redis')) {
            return [];
        }
        $options = ['serializer' => \Redis::SERIALIZER_PHP];
        if (defined('Redis::COMPRESSION_LZ4')) {
            $options['compression'] = \Redis::COMPRESSION_LZ4;
        }

        return $options;
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
            $node = [
                'host' => env('SUPERCACHE_TEST_REDIS_HOST'),
                'port' => (int) env('SUPERCACHE_TEST_REDIS_PORT', 6379),
                'database' => (int) env('SUPERCACHE_TEST_REDIS_DB', 0),
                'password' => $password,
            ];
            $redis['standalone'] = $node;
            $redis['standalone_packed'] = $node + ['options' => $this->packedOptions()];
        }
        if (env('SUPERCACHE_TEST_REDIS_CLUSTER')) {
            $nodes = array_map(function (string $node) use ($password) {
                [$host, $port] = explode(':', trim($node));

                return ['host' => $host, 'port' => (int) $port, 'password' => $password];
            }, explode(',', (string) env('SUPERCACHE_TEST_REDIS_CLUSTER')));
            $redis['clusters']['cluster'] = $nodes;
            // in cluster le opzioni stanno nella lista dei nodi (è lì che il connector Laravel le legge)
            $redis['clusters']['cluster_packed'] = $nodes + ['options' => $this->packedOptions()];
        }
        $app['config']->set('database.redis', $redis);
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]);
    }

    /**
     * @param string      $connection    connection used by the lock
     * @param string|null $rawConnection same node(s) without serializer/compression, to look at what is really stored
     */
    protected function assertLockLifecycle(string $connection, ?string $rawConnection = null): void
    {
        $helper = new SuperCacheInvalidationHelper();
        $redis = Redis::connection($connection);
        $raw = Redis::connection($rawConnection ?? $connection);
        // più shard: in cluster le chiavi finiscono su slot (e nodi) diversi
        foreach ([0, 1, 2, 3, 4] as $shard) {
            $key = $helper->getShardLockKey($shard, 0, $connection);
            $raw->del($key);

            $token = $helper->acquireShardLock($shard, 0, 30, $connection);
            $this->assertIsString($token, "shard $shard: lock non acquisito");
            $this->assertSame($token, $redis->get($key));
            if ($rawConnection !== null && $this->packedOptions() !== []) {
                $this->assertNotSame($token, (string) $raw->get($key), "shard $shard: con serializer/compression il valore su Redis deve essere impacchettato");
            }
            $ttl = (int) $raw->ttl($key);
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(30, $ttl);

            $this->assertFalse($helper->acquireShardLock($shard, 0, 30, $connection), "shard $shard: il lock deve essere esclusivo");

            $this->assertTrue($helper->refreshShardLock($shard, 0, $token, 60, $connection), "shard $shard: refresh del proprietario fallito");
            $this->assertGreaterThan(30, (int) $raw->ttl($key));
            $this->assertFalse($helper->refreshShardLock($shard, 0, 'not-mine', 60, $connection));
            $this->assertSame(SuperCacheInvalidationHelper::LOCK_OWNED, $helper->inspectShardLock($shard, 0, $token, $connection));
            $this->assertSame(SuperCacheInvalidationHelper::LOCK_FOREIGN, $helper->inspectShardLock($shard, 0, 'not-mine', $connection));

            $this->assertFalse($helper->releaseShardLock($shard, 0, 'not-mine', $connection));
            $this->assertSame($token, $redis->get($key));
            $this->assertTrue($helper->releaseShardLock($shard, 0, $token, $connection));
            $this->assertNull($redis->get($key) ?: null);
            $this->assertSame(SuperCacheInvalidationHelper::LOCK_MISSING, $helper->inspectShardLock($shard, 0, $token, $connection));
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

    public function test_lock_lifecycle_on_standalone_redis_with_serializer_and_compression(): void
    {
        if (!env('SUPERCACHE_TEST_REDIS_HOST')) {
            $this->markTestSkipped('SUPERCACHE_TEST_REDIS_HOST non impostato');
        }
        $this->assertLockLifecycle('standalone_packed', 'standalone');
    }

    public function test_lock_lifecycle_on_redis_cluster(): void
    {
        if (!env('SUPERCACHE_TEST_REDIS_CLUSTER')) {
            $this->markTestSkipped('SUPERCACHE_TEST_REDIS_CLUSTER non impostato');
        }
        $this->assertLockLifecycle('cluster');
    }

    public function test_lock_lifecycle_on_redis_cluster_with_serializer_and_compression(): void
    {
        if (!env('SUPERCACHE_TEST_REDIS_CLUSTER')) {
            $this->markTestSkipped('SUPERCACHE_TEST_REDIS_CLUSTER non impostato');
        }
        $this->assertLockLifecycle('cluster_packed', 'cluster');
    }
}
