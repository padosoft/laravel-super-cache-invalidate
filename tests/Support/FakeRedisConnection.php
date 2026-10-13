<?php

namespace Padosoft\SuperCacheInvalidate\Test\Support;

/**
 * Minimal in-memory Redis connection supporting the plain single-key commands used by the package
 * (SET with EX/NX, GET, DEL, TTL, EXPIRE, EXISTS). EVAL throws on purpose: Lua scripts are not available on the
 * production Redis Cluster, so the package must never use them.
 *
 * Like phpredis with the `serializer`/`compression` options, an optional pack/unpack pair is applied to the values
 * of SET and GET only (what is stored differs from what the client wrote and reads back).
 */
class FakeRedisConnection
{
    /** @var array<string, array{value: string, expires: int|null}> */
    public array $store = [];

    /** @var (\Closure(string): string)|null */
    public ?\Closure $pack = null;

    /** @var (\Closure(string): string)|null */
    public ?\Closure $unpack = null;

    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    public function __construct(public string $name)
    {
    }

    public function __call($method, $parameters)
    {
        throw new \BadMethodCallException("FakeRedisConnection: command [$method] not supported (the package must use single-key commands only)");
    }

    protected function purgeExpired(): void
    {
        $now = time();
        foreach ($this->store as $key => $item) {
            if ($item['expires'] !== null && $item['expires'] <= $now) {
                unset($this->store[$key]);
            }
        }
    }

    public function set($key, $value, $expireResolution = null, $expireTTL = null, $flag = null)
    {
        $this->calls[] = ['set', func_get_args()];
        $this->purgeExpired();

        if ($flag === 'NX' && isset($this->store[$key])) {
            return false;
        }
        if ($flag === 'XX' && !isset($this->store[$key])) {
            return false;
        }

        $expires = null;
        if ($expireResolution === 'EX') {
            $expires = time() + (int) $expireTTL;
        } elseif ($expireResolution === 'PX') {
            $expires = time() + (int) ceil(((int) $expireTTL) / 1000);
        }
        $this->store[$key] = ['value' => $this->pack ? ($this->pack)((string) $value) : (string) $value, 'expires' => $expires];

        return true;
    }

    public function get($key)
    {
        $this->calls[] = ['get', func_get_args()];
        $this->purgeExpired();

        if (!isset($this->store[$key])) {
            return null;
        }

        return $this->unpack ? ($this->unpack)($this->store[$key]['value']) : $this->store[$key]['value'];
    }

    public function del(...$keys)
    {
        $this->calls[] = ['del', $keys];
        $deleted = 0;
        foreach ($keys as $key) {
            if (isset($this->store[$key])) {
                unset($this->store[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function exists($key)
    {
        $this->calls[] = ['exists', func_get_args()];
        $this->purgeExpired();

        return isset($this->store[$key]) ? 1 : 0;
    }

    public function ttl($key)
    {
        $this->calls[] = ['ttl', func_get_args()];
        $this->purgeExpired();
        if (!isset($this->store[$key])) {
            return -2;
        }
        $expires = $this->store[$key]['expires'];

        return $expires === null ? -1 : max(0, $expires - time());
    }

    public function expire($key, $seconds)
    {
        $this->calls[] = ['expire', func_get_args()];
        $this->purgeExpired();
        if (!isset($this->store[$key])) {
            return false;
        }
        $this->store[$key]['expires'] = time() + (int) $seconds;

        return true;
    }

    public function eval($script, $numberOfKeys, ...$arguments)
    {
        throw new \BadMethodCallException('FakeRedisConnection: EVAL non supportato, gli script Lua non sono disponibili sul Redis Cluster di produzione');
    }

    public function evalsha($sha, $numberOfKeys, ...$arguments)
    {
        return $this->eval($sha, $numberOfKeys, ...$arguments);
    }

    public function flushall()
    {
        $this->store = [];

        return true;
    }

    public function flushdb()
    {
        return $this->flushall();
    }
}
