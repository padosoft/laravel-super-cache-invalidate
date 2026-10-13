<?php

namespace Padosoft\SuperCacheInvalidate\Test\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Padosoft\SuperCacheInvalidate\Console\ProcessCacheInvalidationEventsCommand;
use Padosoft\SuperCacheInvalidate\Events\BatchCompletedEvent;
use Padosoft\SuperCacheInvalidate\Helpers\SuperCacheInvalidationHelper;
use Padosoft\SuperCacheInvalidate\Test\Support\CallbackSpy;
use Symfony\Component\Console\Tester\CommandTester;

class ProcessCacheInvalidationEventsTest extends TestCase
{
    protected SuperCacheInvalidationHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEventsTable();
        CallbackSpy::reset();
        Config::set('super_cache_invalidate.tag_invalidation_callback', 'sci_test_flush_tags');
        Config::set('super_cache_invalidate.key_invalidation_callback', 'sci_test_forget_key');
        Config::set('super_cache_invalidate.invalidation_window', 120);
        Config::set('super_cache_invalidate.lock_timeout', 600);
        Config::set('super_cache_invalidate.max_runtime', 0);
        Config::set('super_cache_invalidate.chunk_size', 200);
        $this->helper = $this->app->make(SuperCacheInvalidationHelper::class);
    }

    protected function insertEvent(string $type, string $identifier, int $ageSeconds, int $shard = 0, string $connection = 'cache', int $priority = 0, int $processed = 0): int
    {
        return DB::table('cache_invalidation_events')->insertGetId([
            'type' => $type,
            'identifier' => $identifier,
            'connection_name' => $connection,
            'reason' => 'test',
            'priority' => $priority,
            'event_time' => now()->subSeconds($ageSeconds)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            'processed' => $processed,
            'shard' => $shard,
            'partition_key' => $processed === 0 ? ($priority * 10) + $shard : 20261000,
        ]);
    }

    protected function countEvents(array $where): int
    {
        return DB::table('cache_invalidation_events')->where($where)->count();
    }

    protected function makeCommand(): ProcessCacheInvalidationEventsCommand
    {
        $command = $this->app->make(ProcessCacheInvalidationEventsCommand::class);
        $command->setLaravel($this->app);

        return $command;
    }

    /**
     * @return array{0: int, 1: string} exit code and console output
     */
    protected function runCommand(array $options = [], ?ProcessCacheInvalidationEventsCommand $command = null): array
    {
        $command ??= $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(array_merge([
            '--shard' => '0',
            '--priority' => '0',
            '--connection_name' => 'cache',
            '--log_attivo' => '1',
        ], array_map('strval', $options)));

        return [$exitCode, $tester->getDisplay()];
    }

    protected function assertLockReleased(): void
    {
        $this->assertNull(
            $this->redis->connection('cache')->get($this->helper->getShardLockKey(0, 0, 'cache')),
            'il lock dello shard deve essere rilasciato a fine run'
        );
    }

    public function test_identifiers_outside_the_window_are_flushed_once_and_all_their_events_are_marked(): void
    {
        // tag A: 6 eventi vecchi (solo 5 entrano nel primo fetch con limit 5)
        foreach (range(0, 5) as $i) {
            $this->insertEvent('tag', 'product:A', 300 + $i);
        }
        // tag B: 3 eventi vecchi
        foreach (range(0, 2) as $i) {
            $this->insertEvent('tag', 'product:B', 400);
        }
        // key K: 2 eventi vecchi
        $this->insertEvent('key', 'home:key', 500);
        $this->insertEvent('key', 'home:key', 501);
        // tag C: 2 eventi freschi, dentro la finestra di 120s
        $this->insertEvent('tag', 'product:C', 10);
        $this->insertEvent('tag', 'product:C', 11);
        Event::fake([BatchCompletedEvent::class]);

        // tag-batch-size=1 disattiva la regola "siti piccoli": vale solo la finestra
        [$exit, $output] = $this->runCommand(['--limit' => 5, '--chunk-size' => 2, '--tag-batch-size' => 1]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['home:key'], CallbackSpy::forgottenKeys());
        $flushedTags = CallbackSpy::flushedTags();
        sort($flushedTags);
        $this->assertSame(['product:A', 'product:B'], $flushedTags, 'ogni tag va flushato una volta sola');
        $this->assertSame(['cache'], array_unique(array_column(CallbackSpy::$tags, 1)));

        // tutti gli eventi di A, B e K sono processed (anche il sesto di A, mai letto dal fetch)
        $this->assertSame(11, $this->countEvents(['processed' => 1]));
        $this->assertSame(0, DB::table('cache_invalidation_events')->where('processed', 1)->whereNull('batch_ID')->count());
        $this->assertSame(2, $this->countEvents(['processed' => 0]));
        $this->assertSame(2, $this->countEvents(['processed' => 0, 'identifier' => 'product:C']));

        // due chunk: [K, B] e [A]
        Event::assertDispatchedTimes(BatchCompletedEvent::class, 2);
        Event::assertDispatched(BatchCompletedEvent::class, function (BatchCompletedEvent $event) {
            return $event->shard === 0 && $event->connection_name === 'cache' && count($event->identifiers) > 0 && $event->batch_ID !== '';
        });

        $this->assertLockReleased();
        $this->assertStringContainsString('Fine Elaborazione - esito OK, chunk 2, identificatori 3, eventi marcati 11', $output);
    }

    public function test_small_sites_rule_flushes_fresh_identifiers_too_when_the_oldest_waits_two_minutes(): void
    {
        // Con Carbon 3 diffInSeconds è col segno: questa regola era diventata codice morto
        $this->insertEvent('tag', 'product:old', 130);
        $this->insertEvent('tag', 'product:fresh', 5);
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand(['--limit' => 100, '--tag-batch-size' => 100]);

        $this->assertSame(0, $exit, $output);
        $flushedTags = CallbackSpy::flushedTags();
        sort($flushedTags);
        $this->assertSame(['product:fresh', 'product:old'], $flushedTags);
        $this->assertSame(2, $this->countEvents(['processed' => 1]));
        $this->assertStringContainsString('inferiore al batchSize', $output);
    }

    public function test_nothing_is_flushed_when_all_events_are_inside_the_window(): void
    {
        $this->insertEvent('tag', 'product:A', 5);
        $this->insertEvent('tag', 'product:B', 10);
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand(['--limit' => 100, '--tag-batch-size' => 100]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame([], CallbackSpy::$tags);
        $this->assertSame(0, $this->countEvents(['processed' => 1]));
        Event::assertNotDispatched(BatchCompletedEvent::class);
        $this->assertLockReleased();
    }

    public function test_skips_when_another_process_holds_the_lock(): void
    {
        $this->insertEvent('tag', 'product:A', 300);
        $token = $this->helper->acquireShardLock(0, 0, 600, 'cache');
        $this->assertNotFalse($token);
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Lock già attivo', $output);
        $this->assertSame([], CallbackSpy::$tags);
        $this->assertSame(0, $this->countEvents(['processed' => 1]));
        Event::assertNotDispatched(BatchCompletedEvent::class);
        // il lock dell'altro processo non deve essere toccato
        $this->assertSame($token, $this->redis->connection('cache')->get($this->helper->getShardLockKey(0, 0, 'cache')));
    }

    public function test_aborts_when_the_lock_is_lost(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $tag) {
            $this->insertEvent('tag', 'product:' . $tag, 300);
        }
        // simulo la scadenza del lock mentre gira il listener del primo chunk
        Event::listen(BatchCompletedEvent::class, function () {
            $this->redis->connection('cache')->flushall();
        });

        [$exit, $output] = $this->runCommand(['--chunk-size' => 2, '--tag-batch-size' => 1]);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Lock dello shard perso', $output);
        $this->assertCount(1, CallbackSpy::$tags, 'solo il primo chunk deve essere stato flushato');
        $this->assertSame(2, $this->countEvents(['processed' => 1]));
        $this->assertSame(2, $this->countEvents(['processed' => 0]));
        $this->assertStringContainsString('esito ERRORE', $output);
    }

    public function test_excluded_tags_are_marked_as_processed_but_not_flushed_on_redis(): void
    {
        $this->insertEvent('tag', 'fullpage:home', 300);
        $this->insertEvent('tag', 'product:1', 300);
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['product:1'], CallbackSpy::flushedTags());
        $this->assertSame(2, $this->countEvents(['processed' => 1]));
        Event::assertDispatched(BatchCompletedEvent::class, function (BatchCompletedEvent $event) {
            $identifiers = $event->identifiers;
            sort($identifiers);

            return $identifiers === ['fullpage:home', 'product:1'];
        });
    }

    public function test_callback_exception_leaves_events_unprocessed_and_releases_the_lock(): void
    {
        $this->insertEvent('tag', 'product:1', 300);
        CallbackSpy::$onTag = function () {
            throw new \RuntimeException('redis down');
        };
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Eccezione: redis down', $output);
        $this->assertSame(0, $this->countEvents(['processed' => 1]));
        Event::assertNotDispatched(BatchCompletedEvent::class);
        $this->assertLockReleased();
    }

    public function test_stop_request_stops_cleanly_after_the_current_chunk(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $tag) {
            $this->insertEvent('tag', 'product:' . $tag, 300);
        }
        $command = $this->makeCommand();
        Event::listen(BatchCompletedEvent::class, function () use ($command) {
            $command->requestStop('test');
        });

        [$exit, $output] = $this->runCommand(['--chunk-size' => 2, '--tag-batch-size' => 1], $command);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Stop richiesto (test)', $output);
        $this->assertSame(2, $this->countEvents(['processed' => 1]));
        $this->assertSame(2, $this->countEvents(['processed' => 0]));
        $this->assertLockReleased();
    }

    public function test_max_runtime_budget_stops_cleanly_and_the_next_run_resumes(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $tag) {
            $this->insertEvent('tag', 'product:' . $tag, 300);
        }
        CallbackSpy::$onTag = function () {
            usleep(1100000); // ogni chunk dura più del budget di 1s
        };
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand(['--chunk-size' => 2, '--tag-batch-size' => 1, '--max-runtime' => 1]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Budget di tempo', $output);
        $this->assertSame(2, $this->countEvents(['processed' => 1]));
        $this->assertLockReleased();

        // il run successivo riparte da dove si era fermato
        CallbackSpy::$onTag = null;
        [$exit, $output] = $this->runCommand(['--chunk-size' => 2, '--tag-batch-size' => 1, '--max-runtime' => 0]);
        $this->assertSame(0, $exit, $output);
        $this->assertSame(4, $this->countEvents(['processed' => 1]));
    }

    public function test_only_the_requested_shard_priority_and_connection_are_processed(): void
    {
        $this->insertEvent('tag', 'product:1', 300, 0, 'cache');
        $this->insertEvent('tag', 'product:2', 300, 1, 'cache');
        $this->insertEvent('tag', 'product:3', 300, 0, 'other-connection');
        $this->insertEvent('tag', 'product:4', 300, 0, 'cache', 1);
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['product:1'], CallbackSpy::flushedTags());
        $this->assertSame(1, $this->countEvents(['processed' => 1]));
        $this->assertSame(1, $this->countEvents(['processed' => 1, 'identifier' => 'product:1']));
    }

    public function test_events_inserted_after_the_fetch_are_not_marked_as_processed(): void
    {
        $this->insertEvent('tag', 'product:1', 300);
        // Durante il flush arriva un nuovo evento sullo stesso tag: non deve essere coperto da questo giro
        CallbackSpy::$onTag = function () {
            sleep(1);
            $this->insertEvent('tag', 'product:1', 0);
        };
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertSame(1, $this->countEvents(['processed' => 1]));
        $this->assertSame(1, $this->countEvents(['processed' => 0]));
    }

    public function test_lock_lost_during_the_flush_still_dispatches_the_event_of_the_marked_chunk(): void
    {
        // Il lock scade durante il flush (chunk più lungo di lock_timeout): gli eventi del chunk sono già marcati con il
        // loro batch_ID, quindi il BatchCompletedEvent DEVE partire comunque, altrimenti la CDN non verrebbe mai pulita
        $this->insertEvent('tag', 'fullpage:home', 300);
        $this->insertEvent('tag', 'product:1', 300);
        CallbackSpy::$onTag = function () {
            $this->redis->connection('cache')->flushall();
        };
        Event::fake([BatchCompletedEvent::class]);

        [$exit, $output] = $this->runCommand(['--tag-batch-size' => 1]);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Lock dello shard perso', $output);
        $this->assertSame(2, $this->countEvents(['processed' => 1]));
        $this->assertSame(0, DB::table('cache_invalidation_events')->where('processed', 1)->whereNull('batch_ID')->count());
        Event::assertDispatchedTimes(BatchCompletedEvent::class, 1);
        Event::assertDispatched(BatchCompletedEvent::class, function (BatchCompletedEvent $event) {
            $identifiers = $event->identifiers;
            sort($identifiers);

            return $identifiers === ['fullpage:home', 'product:1'];
        });
        $this->assertStringContainsString('esito ERRORE', $output);
    }
}
