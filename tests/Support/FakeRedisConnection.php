<?php

namespace Padosoft\SuperCacheInvalidate\Test\Support;

/**
 * Minimal in-memory Redis connection supporting the single-key commands used by the package
 * (SET with EX/NX, GET, DEL, TTL, EXPIRE, EXISTS and the two lock Lua scripts via EVAL).
 */
class FakeRedisConnection
{
    /** @var array<string, array{value: string, expires: int|null}> */
    public array $store = [];

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
        $this->store[$key] = ['value' => (string) $value, 'expires' => $expires];

        return true;
    }

    public function get($key)
    {
        $this->calls[] = ['get', func_get_args()];
        $this->purgeExpired();

        return $this->store[$key]['value'] ?? null;
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
        $this->purgeExpired();

        return isset($this->store[$key]) ? 1 : 0;
    }

    public function ttl($key)
    {
        $this->purgeExpired();
        if (!isset($this->store[$key])) {
            return -2;
        }
        $expires = $this->store[$key]['expires'];

        return $expires === null ? -1 : max(0, $expires - time());
    }

    public function expire($key, $seconds)
    {
        $this->purgeExpired();
        if (!isset($this->store[$key])) {
            return false;
        }
        $this->store[$key]['expires'] = time() + (int) $seconds;

        return true;
    }

    public function eval($script, $numberOfKeys, ...$arguments)
    {
        $this->calls[] = ['eval', [$script, $numberOfKeys, ...$arguments]];
        $this->purgeExpired();
        $keys = array_slice($arguments, 0, (int) $numberOfKeys);
        $args = array_slice($arguments, (int) $numberOfKeys);
        $key = $keys[0] ?? null;
        $owner = (string) ($args[0] ?? '');
        $current = $this->store[$key]['value'] ?? null;

        if (str_contains($script, '"del"')) {
            if ($current !== null && $current === $owner) {
                unset($this->store[$key]);

                return 1;
            }

            return 0;
        }
        if (str_contains($script, '"expire"')) {
            if ($current !== null && $current === $owner) {
                $this->store[$key]['expires'] = time() + (int) ($args[1] ?? 0);

                return 1;
            }

            return 0;
        }

        throw new \RuntimeException('FakeRedisConnection: unsupported Lua script');
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
