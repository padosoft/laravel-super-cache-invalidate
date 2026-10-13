<?php

namespace Padosoft\SuperCacheInvalidate\Test\Support {
    /**
     * Records the calls received by the invalidation callbacks configured in the tests.
     */
    class CallbackSpy
    {
        /** @var array<int, array{0: string[], 1: string}> */
        public static array $tags = [];

        /** @var array<int, array{0: string, 1: string}> */
        public static array $keys = [];

        public static ?\Closure $onTag = null;
        public static ?\Closure $onKey = null;

        public static function reset(): void
        {
            self::$tags = [];
            self::$keys = [];
            self::$onTag = null;
            self::$onKey = null;
        }

        /** @return string[] all the tags received, flattened */
        public static function flushedTags(): array
        {
            $tags = [];
            foreach (self::$tags as [$arrTags]) {
                $tags = array_merge($tags, $arrTags);
            }

            return $tags;
        }

        /** @return string[] all the keys received */
        public static function forgottenKeys(): array
        {
            return array_column(self::$keys, 0);
        }
    }
}

// Le callback sono funzioni GLOBALI, come in produzione (il nome arriva dalla config/env)

namespace {
    use Padosoft\SuperCacheInvalidate\Test\Support\CallbackSpy;

    if (!function_exists('sci_test_flush_tags')) {
        function sci_test_flush_tags(array $tags, string $connection_name): void
        {
            CallbackSpy::$tags[] = [$tags, $connection_name];
            if (CallbackSpy::$onTag !== null) {
                (CallbackSpy::$onTag)($tags, $connection_name);
            }
        }
    }

    if (!function_exists('sci_test_forget_key')) {
        function sci_test_forget_key(string $key, string $connection_name): void
        {
            CallbackSpy::$keys[] = [$key, $connection_name];
            if (CallbackSpy::$onKey !== null) {
                (CallbackSpy::$onKey)($key, $connection_name);
            }
        }
    }
}
