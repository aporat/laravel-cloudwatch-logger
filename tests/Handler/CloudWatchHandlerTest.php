<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Handler;

use Aporat\CloudWatchLogger\Cache\LaravelCacheItemPool;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aporat\CloudWatchLogger\Handler\EventBatcher;
use Aporat\CloudWatchLogger\Tests\Support\FakeCloudWatchLogs;
use Aporat\CloudWatchLogger\Tests\Support\ManualClock;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Command;
use Aws\Exception\CredentialsException;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[CoversClass(CloudWatchHandler::class)]
#[CoversClass(EventBatcher::class)]
final class CloudWatchHandlerTest extends TestCase
{
    private FakeCloudWatchLogs $aws;

    private ManualClock $clock;

    /** @var list<string> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->aws = new FakeCloudWatchLogs;
        $this->clock = new ManualClock;
        $this->reported = [];
    }

    /**
     * Regression: after one failed flush the cwh handler kept its buffer,
     * grew past 10,000 events / 1 MB and every later request was rejected
     * forever, even after CloudWatch recovered.
     */
    #[Test]
    public function an_outage_never_leaves_the_buffer_stuck_or_unbounded(): void
    {
        $down = true;
        $this->aws->failWith = function (string $name) use (&$down) {
            return $name === 'PutLogEvents' && $down ? FakeCloudWatchLogs::error('ServiceUnavailableException') : null;
        };
        $handler = $this->handler(batchSize: 10000, maxBufferSize: 10000);

        for ($i = 0; $i < 10000; $i++) {
            $handler->handle($this->record("event $i"));
        }

        $this->assertCount(1, $this->aws->calls('PutLogEvents'), 'One attempt, no sleep-and-retry inside the handler.');
        $this->assertSame(10000, $handler->getBufferedEventCount());
        $this->assertTrue($handler->isCircuitOpen());

        // While the breaker is open nothing is sent and the cap holds.
        for ($i = 0; $i < 5; $i++) {
            $handler->handle($this->record("during outage $i"));
        }

        $this->assertCount(1, $this->aws->calls('PutLogEvents'));
        $this->assertSame(10000, $handler->getBufferedEventCount());
        $this->assertSame(5, $handler->getDroppedEventCount(), 'Overflow drops the oldest events and counts them.');

        // CloudWatch recovers and the breaker closes.
        $down = false;
        $this->clock->advance(31);
        $handler->handle($this->record('after recovery'));
        $handler->close();

        $this->assertSame(0, $handler->getBufferedEventCount());
        $delivered = $this->aws->delivered();
        // The 10,000 kept events (the 5 oldest were dropped) plus the new one.
        $this->assertCount(10001, $delivered);
        $this->assertSame('event 5', $delivered[0]);
        $this->assertSame('after recovery', end($delivered));

        foreach ($this->aws->calls('PutLogEvents') as $call) {
            $this->assertLessThanOrEqual(10000, count($call['args']['logEvents']));
        }

        $this->assertStringContainsString('dropped 5 log event(s)', implode("\n", $this->reported));
    }

    #[Test]
    public function a_non_retryable_rejection_drops_the_batch_instead_of_retrying_it(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('InvalidParameterException') : null;
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('rejected'));

        $this->assertCount(1, $this->aws->calls('PutLogEvents'), 'Non-retryable errors are not retried.');
        $this->assertSame(0, $handler->getBufferedEventCount());
        $this->assertSame(1, $handler->getDroppedEventCount());
        $this->assertStringContainsString('events dropped', $this->reported[0]);
        $this->assertStringContainsString('InvalidParameterException', $this->reported[0]);
    }

    #[Test]
    public function a_retryable_error_keeps_the_events_for_the_next_attempt(): void
    {
        $down = true;
        $this->aws->failWith = function (string $name) use (&$down) {
            return $name === 'PutLogEvents' && $down ? FakeCloudWatchLogs::connectionError() : null;
        };
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('first'));
        $this->assertSame(1, $handler->getBufferedEventCount());

        $down = false;
        $this->clock->advance(30);
        $handler->handle($this->record('second'));

        $this->assertSame(['first', 'second'], $this->aws->delivered());
        $this->assertSame(0, $handler->getDroppedEventCount());
    }

    #[Test]
    public function the_circuit_breaker_stops_requests_until_it_closes(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;
        $handler = $this->handler(batchSize: 1, circuitBreaker: 30);

        $handler->handle($this->record('a'));
        $handler->handle($this->record('b'));
        $this->clock->advance(29);
        $handler->handle($this->record('c'));
        $handler->flush();
        $handler->close();

        $this->assertCount(1, $this->aws->calls('PutLogEvents'), 'No request while the breaker is open, including on close.');
        $this->assertSame(3, $handler->getBufferedEventCount());
        $this->assertCount(1, $this->reported, 'One error report per breaker window.');

        $this->clock->advance(2);
        $handler->handle($this->record('d'));
        $this->assertCount(2, $this->aws->calls('PutLogEvents'));
    }

    #[Test]
    public function a_disabled_circuit_breaker_tries_every_flush(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;
        $handler = $this->handler(batchSize: 1, circuitBreaker: 0);

        $handler->handle($this->record('a'));
        $handler->handle($this->record('b'));

        $this->assertCount(2, $this->aws->calls('PutLogEvents'));
    }

    #[Test]
    public function a_failed_group_setup_opens_the_breaker_and_keeps_the_events(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'DescribeLogGroups' ? FakeCloudWatchLogs::connectionError('DescribeLogGroups') : null;
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('a'));
        $handler->handle($this->record('b'));

        $this->assertSame(['DescribeLogGroups'], $this->aws->names());
        $this->assertSame(2, $handler->getBufferedEventCount());
        $this->assertStringContainsString('could not initialise', $this->reported[0]);
    }

    #[Test]
    public function failures_throw_when_not_suppressed(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;
        $handler = $this->handler(batchSize: 1, suppressFailures: false);

        try {
            $handler->handle($this->record('a'));
            $this->fail('Expected the failure to be thrown.');
        } catch (CloudWatchLogsException) {
            // expected
        }

        $this->assertSame(1, $handler->getBufferedEventCount(), 'Still bounded and kept for retry.');
        $this->assertSame([], $this->reported);
    }

    #[Test]
    public function requests_respect_the_put_log_events_limits(): void
    {
        $handler = $this->handler(batchSize: 10000, maxBufferSize: 20000);
        $base = new DateTimeImmutable('2026-01-01 00:00:00');

        // 10,001 tiny events, 3 large ones (≈ 400 KB each), out of order, and one 25 h later.
        for ($i = 0; $i < 10001; $i++) {
            $handler->handle($this->record("e$i", $base->modify('+'.($i % 7).' seconds')));
        }
        for ($i = 0; $i < 3; $i++) {
            $handler->handle($this->record(str_repeat('x', 400_000), $base));
        }
        $handler->handle($this->record('next day', $base->modify('+25 hours')));
        $handler->close();

        $this->assertSame([], $this->reported, 'The fake rejects any request breaking a limit.');
        $this->assertCount(10005, $this->aws->delivered());

        foreach ($this->aws->calls('PutLogEvents') as $call) {
            $events = $call['args']['logEvents'];
            $timestamps = array_column($events, 'timestamp');
            $this->assertLessThanOrEqual(10000, count($events));
            $this->assertLessThanOrEqual(1048576, array_sum(array_map(fn ($e) => strlen($e['message']) + 26, $events)));
            $this->assertSame($timestamps, (function () use ($timestamps) {
                sort($timestamps);

                return $timestamps;
            })());
            $this->assertLessThan(86_400_000, max($timestamps) - min($timestamps));
        }
    }

    /**
     * Regression: cwh split oversized messages with str_split(), cutting a
     * multibyte character in half; the SDK then failed to JSON-encode the
     * whole batch.
     */
    #[Test]
    public function oversized_messages_are_split_on_utf8_character_boundaries(): void
    {
        $handler = $this->handler(batchSize: 1);
        $message = str_repeat('a', EventBatcher::MAX_MESSAGE_BYTES - 1).'é'.str_repeat('b', 10);

        $handler->handle($this->record($message));
        $handler->close();

        $this->assertSame([], $this->reported);
        $delivered = $this->aws->delivered();
        $this->assertCount(2, $delivered);
        $this->assertSame($message, implode('', $delivered));

        foreach ($delivered as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            $this->assertLessThanOrEqual(EventBatcher::MAX_MESSAGE_BYTES, strlen($chunk));
        }
    }

    #[Test]
    public function invalid_utf8_is_scrubbed_rather_than_failing_the_batch(): void
    {
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record("bad \xC3\x28 bytes"));

        $this->assertSame([], $this->reported);
        $this->assertCount(1, $this->aws->delivered());
        $this->assertTrue(mb_check_encoding($this->aws->delivered()[0], 'UTF-8'));
    }

    #[Test]
    public function timestamps_are_sent_as_integer_milliseconds(): void
    {
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('ts', new DateTimeImmutable('@1767225600.123456')));

        $body = $this->aws->calls('PutLogEvents')[0]['body'];
        $this->assertStringContainsString('"timestamp":1767225600123,', $body);
    }

    #[Test]
    public function it_creates_the_group_with_retention_and_tags_then_the_stream(): void
    {
        $handler = $this->handler(batchSize: 1, tags: ['Team' => 'platform']);

        $handler->handle($this->record('x'));

        $this->assertSame(
            ['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogStreams', 'CreateLogStream', 'PutLogEvents'],
            $this->aws->names()
        );
        $this->assertSame(['Team' => 'platform'], $this->aws->calls('CreateLogGroup')[0]['args']['tags']);
        $this->assertSame(14, $this->aws->calls('PutRetentionPolicy')[0]['args']['retentionInDays']);
    }

    /**
     * Two processes racing to create the same group/stream: the loser must
     * not lose its log line.
     */
    #[Test]
    public function resource_already_exists_on_create_is_treated_as_success(): void
    {
        // Describe says "missing", but another process creates them first.
        $this->aws->failWith = function (string $name) {
            return match ($name) {
                'CreateLogGroup' => FakeCloudWatchLogs::error('ResourceAlreadyExistsException', 'CreateLogGroup'),
                'CreateLogStream' => FakeCloudWatchLogs::error('ResourceAlreadyExistsException', 'CreateLogStream'),
                default => null,
            };
        };
        $this->aws->streams['app|web'] = true;
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('x'));

        $this->assertSame([], $this->reported);
        $this->assertSame(['x'], $this->aws->delivered());
        $this->assertNotContains('PutRetentionPolicy', $this->aws->names(), 'Retention is only set by the creator.');
    }

    #[Test]
    public function existing_groups_and_streams_are_not_recreated(): void
    {
        $this->aws->groups['app'] = true;
        $this->aws->streams['app|web'] = true;
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('x'));

        $this->assertSame(['DescribeLogGroups', 'DescribeLogStreams', 'PutLogEvents'], $this->aws->names());
    }

    /**
     * Regression: the Laravel pool namespaced keys by group only, so a stream
     * named like its group was never created.
     */
    #[Test]
    public function a_stream_named_like_its_group_is_created_with_a_cache(): void
    {
        $pool = new LaravelCacheItemPool(new Repository(new ArrayStore), 'app');
        $handler = $this->handler(batchSize: 1, stream: 'app', cache: $pool);

        $handler->handle($this->record('x'));

        $this->assertContains('CreateLogStream', $this->aws->names());
        $this->assertSame(['x'], $this->aws->delivered());

        // A second process skips both describe calls.
        $this->aws->calls = [];
        $this->handler(batchSize: 1, stream: 'app', cache: $pool)->handle($this->record('y'));
        $this->assertSame(['PutLogEvents'], $this->aws->names());
    }

    #[Test]
    public function two_groups_sharing_a_stream_name_do_not_share_cache_entries(): void
    {
        $pool = new LaravelCacheItemPool(new Repository(new ArrayStore), 'shared');

        $this->handler(batchSize: 1, group: 'group-a', stream: 'default', cache: $pool)->handle($this->record('a'));
        $this->handler(batchSize: 1, group: 'group-b', stream: 'default', cache: $pool)->handle($this->record('b'));

        $this->assertSame(['a', 'b'], $this->aws->delivered());
        $this->assertCount(2, $this->aws->calls('CreateLogStream'));
    }

    #[Test]
    public function reset_flushes_the_buffer(): void
    {
        $handler = $this->handler(batchSize: 100);
        $handler->handle($this->record('buffered'));
        $this->assertSame([], $this->aws->delivered());

        $handler->reset();

        $this->assertSame(['buffered'], $this->aws->delivered());
    }

    #[Test]
    public function close_flushes_the_buffer(): void
    {
        $handler = $this->handler(batchSize: 100);
        $handler->handle($this->record('buffered'));

        $handler->close();

        $this->assertSame(['buffered'], $this->aws->delivered());
    }

    #[Test]
    public function a_write_flushes_once_the_oldest_event_is_older_than_the_flush_interval(): void
    {
        $handler = $this->handler(batchSize: 100, flushInterval: 10);

        $handler->handle($this->record('first'));
        $this->clock->advance(9);
        $handler->handle($this->record('second'));
        $this->assertSame([], $this->aws->delivered());

        $this->clock->advance(1);
        $handler->handle($this->record('third'));
        $this->assertSame(['first', 'second', 'third'], $this->aws->delivered());

        // The interval restarts with the next buffered event.
        $this->clock->advance(5);
        $handler->handle($this->record('fourth'));
        $this->assertCount(3, $this->aws->delivered());
    }

    #[Test]
    public function a_zero_flush_interval_waits_for_the_batch(): void
    {
        $handler = $this->handler(batchSize: 100, flushInterval: 0);

        $handler->handle($this->record('first'));
        $this->clock->advance(3600);
        $handler->handle($this->record('second'));

        $this->assertSame([], $this->aws->delivered());
    }

    #[Test]
    public function the_rps_limit_paces_requests_within_a_second(): void
    {
        $handler = $this->handler(batchSize: 1, rpsLimit: 2);

        foreach (['a', 'b', 'c'] as $message) {
            $handler->handle($this->record($message));
        }

        $this->assertCount(1, $this->clock->sleeps, 'Third request in the same second waits for the next one.');
        $this->assertSame(['a', 'b', 'c'], $this->aws->delivered());

        // Requests a minute apart never wait.
        $this->clock->advance(60);
        $handler->handle($this->record('d'));
        $this->assertCount(1, $this->clock->sleeps);
    }

    #[Test]
    public function records_below_the_level_are_ignored(): void
    {
        $handler = $this->handler(batchSize: 1, level: Level::Error);

        $this->assertFalse($handler->isHandling($this->record('x', level: Level::Info)));
    }

    #[Test]
    public function it_rejects_a_buffer_smaller_than_the_batch(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(batchSize: 100, maxBufferSize: 10);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function retryableErrors(): iterable
    {
        yield 'credentials' => [new CredentialsException('no credentials')];
        yield 'throttling' => [FakeCloudWatchLogs::error('ThrottlingException')];
        yield 'http 500' => [FakeCloudWatchLogs::error('InternalServerError', context: ['response' => new Response(500)])];
        yield 'http 429' => [FakeCloudWatchLogs::error('SlowDown', context: ['response' => new Response(429)])];
        yield 'no response, no code' => [new CloudWatchLogsException('timed out', new Command('PutLogEvents'))];
    }

    #[Test]
    #[DataProvider('retryableErrors')]
    public function transient_errors_keep_the_events(\Throwable $error): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? $error : null;
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('kept'));

        $this->assertSame(1, $handler->getBufferedEventCount());
        $this->assertSame(0, $handler->getDroppedEventCount());
        $this->assertStringContainsString('will retry', $this->reported[0]);
    }

    #[Test]
    public function an_http_4xx_without_a_known_code_is_not_retried(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents'
            ? FakeCloudWatchLogs::error('AccessDeniedException', context: ['response' => new Response(400)])
            : null;
        $handler = $this->handler(batchSize: 1);

        $handler->handle($this->record('dropped'));

        $this->assertSame(0, $handler->getBufferedEventCount());
        $this->assertSame(1, $handler->getDroppedEventCount());
    }

    #[Test]
    public function a_failure_while_formatting_is_suppressed_and_reported(): void
    {
        $handler = $this->handler(batchSize: 1);
        $handler->setFormatter(new class implements FormatterInterface
        {
            public function format(LogRecord $record): mixed
            {
                throw new \RuntimeException('formatter exploded');
            }

            public function formatBatch(array $records): mixed
            {
                return [];
            }
        });

        $this->assertFalse($handler->handle($this->record('x')));
        $this->assertStringContainsString('formatter exploded', $this->reported[0]);
    }

    #[Test]
    public function close_and_reset_rethrow_when_failures_are_not_suppressed(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;

        foreach (['close', 'reset'] as $method) {
            $handler = $this->handler(batchSize: 100, circuitBreaker: 0, suppressFailures: false);
            $handler->handle($this->record('x'));

            try {
                $handler->{$method}();
                $this->fail("$method() should rethrow.");
            } catch (CloudWatchLogsException) {
                $this->assertSame(1, $handler->getBufferedEventCount());
            }
        }
    }

    #[Test]
    public function a_failing_cache_pool_degrades_to_describe_calls(): void
    {
        $pool = new class implements CacheItemPoolInterface
        {
            public function getItem(string $key): CacheItemInterface
            {
                throw new \RuntimeException('cache down');
            }

            public function getItems(array $keys = []): iterable
            {
                return [];
            }

            public function hasItem(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return false;
            }

            public function deleteItem(string $key): bool
            {
                return false;
            }

            public function deleteItems(array $keys): bool
            {
                return false;
            }

            public function save(CacheItemInterface $item): bool
            {
                return false;
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                return false;
            }

            public function commit(): bool
            {
                return false;
            }
        };
        $handler = $this->handler(batchSize: 1, cache: $pool);

        $handler->handle($this->record('x'));

        $this->assertSame([], $this->reported);
        $this->assertSame(['x'], $this->aws->delivered());
        $this->assertContains('DescribeLogGroups', $this->aws->names());
    }

    #[Test]
    public function the_default_formatter_matches_the_documented_template(): void
    {
        $client = new CloudWatchLogsClient(['region' => 'us-east-1', 'version' => 'latest', 'handler' => $this->aws, 'credentials' => ['key' => 'k', 'secret' => 's']]);
        $formatter = (new CloudWatchHandler($client, 'app', 'web'))->getFormatter();

        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertSame('test: ERROR: hi  ', $formatter->format($this->record('hi')));
    }

    #[Test]
    public function empty_messages_produce_no_events_and_tiny_limits_still_terminate(): void
    {
        $this->assertSame([], EventBatcher::toEvents('', 1));

        // A limit narrower than one character falls back to raw bytes rather than looping.
        $events = EventBatcher::toEvents('é', 1, 1);
        $this->assertSame("\xC3\xA9", implode('', array_column($events, 'message')));
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function handler(
        int $batchSize = 10000,
        int $maxBufferSize = 10000,
        int $flushInterval = 0,
        int $circuitBreaker = 30,
        int $rpsLimit = 0,
        bool $suppressFailures = true,
        string $group = 'app',
        string $stream = 'web',
        array $tags = [],
        ?CacheItemPoolInterface $cache = null,
        Level $level = Level::Debug,
    ): CloudWatchHandler {
        $client = new CloudWatchLogsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIDTEST', 'secret' => 'secret'],
            'retries' => 0,
            'handler' => $this->aws,
        ]);

        $handler = new CloudWatchHandler(
            client: $client,
            group: $group,
            stream: $stream,
            batchSize: $batchSize,
            tags: $tags,
            level: $level,
            rpsLimit: $rpsLimit,
            cacheItemPool: $cache,
            maxBufferSize: $maxBufferSize,
            flushInterval: $flushInterval,
            circuitBreakerSeconds: $circuitBreaker,
            suppressFailures: $suppressFailures,
            clock: $this->clock->now(...),
            sleeper: $this->clock->sleep(...),
            errorReporter: function (string $message): void {
                $this->reported[] = $message;
            },
        );
        $handler->setFormatter(new LineFormatter('%message%'));

        return $handler;
    }

    private function record(string $message, ?DateTimeImmutable $at = null, Level $level = Level::Error): LogRecord
    {
        return new LogRecord($at ?? new DateTimeImmutable, 'test', $level, $message);
    }
}
