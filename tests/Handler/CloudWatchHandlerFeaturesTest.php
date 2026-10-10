<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Handler;

use Aporat\CloudWatchLogger\Cache\LaravelCacheItemPool;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aporat\CloudWatchLogger\Tests\Support\FakeCloudWatchLogs;
use Aporat\CloudWatchLogger\Tests\Support\ManualClock;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

/**
 * Stream placeholders, enforce_group_settings and the fallback hook, against
 * the in-memory CloudWatch.
 */
#[CoversClass(CloudWatchHandler::class)]
final class CloudWatchHandlerFeaturesTest extends TestCase
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
    }

    #[Test]
    public function static_placeholders_are_resolved_when_the_handler_is_created(): void
    {
        $handler = $this->handler(stream: '{env}-{hostname}-{pid}-{unknown}', placeholders: ['env' => 'staging']);

        $expected = 'staging-'.gethostname().'-'.getmypid().'-{unknown}';
        $this->assertSame($expected, $handler->getStream());

        $handler->handle($this->record('hello'));
        $handler->close();

        $this->assertSame([$expected], array_column(array_column($this->aws->calls('PutLogEvents'), 'args'), 'logStreamName'));
    }

    #[Test]
    public function date_streams_roll_over_at_midnight_utc_by_event_time(): void
    {
        $handler = $this->handler(stream: 'web-{date}', batchSize: 100);

        // 23:59 UTC is still the 9th even where the local date is the 10th.
        $handler->handle($this->record('late', new DateTimeImmutable('2026-10-09 23:59:59', new DateTimeZone('UTC'))));
        $handler->handle($this->record('early', new DateTimeImmutable('2026-10-10 02:00:00', new DateTimeZone('+05:00'))));
        $handler->handle($this->record('next', new DateTimeImmutable('2026-10-10 00:00:01', new DateTimeZone('UTC'))));
        $handler->close();

        $sent = array_map(fn (array $c) => [$c['args']['logStreamName'], array_column($c['args']['logEvents'], 'message')], $this->aws->calls('PutLogEvents'));
        $this->assertSame([
            ['web-2026-10-09', ['early', 'late']], // 21:00 UTC, sorted before 23:59
            ['web-2026-10-10', ['next']],
        ], $sent);
        $this->assertSame(['app|web-2026-10-09', 'app|web-2026-10-10'], array_keys($this->aws->streams));
        $this->assertSame('web-'.gmdate('Y-m-d', (int) $this->clock->now()), $handler->getStream(), 'The current stream follows the handler clock.');
    }

    #[Test]
    public function a_long_running_process_creates_the_next_days_stream_once(): void
    {
        $handler = $this->handler(stream: 'worker-{date}', batchSize: 1, dateFormat: 'Ymd');

        $handler->handle($this->record('a', new DateTimeImmutable('2026-01-01 12:00:00 UTC')));
        $handler->handle($this->record('b', new DateTimeImmutable('2026-01-01 13:00:00 UTC')));
        $handler->handle($this->record('c', new DateTimeImmutable('2026-01-02 00:00:00 UTC')));
        $handler->handle($this->record('d', new DateTimeImmutable('2026-01-02 01:00:00 UTC')));

        $this->assertSame(['app|worker-20260101', 'app|worker-20260102'], array_keys($this->aws->streams));
        $this->assertCount(2, $this->aws->calls('CreateLogStream'));
        $this->assertCount(2, $this->aws->calls('DescribeLogStreams'));
        $this->assertCount(1, $this->aws->calls('DescribeLogGroups'), 'The group is checked once per process.');
    }

    #[Test]
    public function existing_group_settings_are_left_alone_by_default(): void
    {
        $this->aws->groups['app'] = true;
        $handler = $this->handler(retention: 30, tags: ['team' => 'core']);

        $handler->handle($this->record('x'));
        $handler->close();

        $this->assertSame([], $this->aws->calls('PutRetentionPolicy'));
        $this->assertSame([], $this->aws->calls('TagResource'));
    }

    #[Test]
    public function enforcing_applies_retention_and_tags_to_an_existing_group_by_arn(): void
    {
        $this->aws->groups['app'] = true;
        $this->aws->retention['app'] = 7;
        $handler = $this->handler(retention: 30, tags: ['team' => 'core'], enforce: true);

        $handler->handle($this->record('x'));
        $handler->close();

        $this->assertSame(30, $this->aws->retention['app']);
        $this->assertSame(['team' => 'core'], $this->aws->tags['app']);
        $this->assertSame(FakeCloudWatchLogs::ARN_PREFIX.'app', $this->aws->calls('TagResource')[0]['args']['resourceArn']);
        $this->assertSame(['x'], $this->aws->delivered());
        $this->assertSame([], $this->reported);
    }

    #[Test]
    public function enforcing_skips_put_retention_when_it_already_matches_and_null_retention_is_not_enforced(): void
    {
        $this->aws->groups['app'] = true;
        $this->aws->retention['app'] = 30;
        $this->handler(retention: 30, tags: ['a' => 'b'], enforce: true, batchSize: 1)->handle($this->record('x'));
        $this->assertSame([], $this->aws->calls('PutRetentionPolicy'));
        $this->assertCount(1, $this->aws->calls('TagResource'));

        $aws = $this->aws = new FakeCloudWatchLogs;
        $aws->groups['app'] = true;
        $aws->retention['app'] = 7;
        $this->handler(retention: null, enforce: true, batchSize: 1)->handle($this->record('y'));
        $this->assertSame(['DescribeLogGroups', 'DescribeLogStreams', 'CreateLogStream', 'PutLogEvents'], $aws->names(), 'Nothing to enforce: no extra calls.');
        $this->assertSame(7, $aws->retention['app']);
    }

    #[Test]
    public function a_group_this_handler_creates_gets_its_settings_without_a_second_pass(): void
    {
        $handler = $this->handler(retention: 30, tags: ['team' => 'core'], enforce: true, batchSize: 1);

        $handler->handle($this->record('x'));

        $this->assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogStreams', 'CreateLogStream', 'PutLogEvents'], $this->aws->names());
        $this->assertSame(['team' => 'core'], $this->aws->tags['app']);
        $this->assertSame(30, $this->aws->retention['app']);
    }

    #[Test]
    public function enforcement_failures_are_reported_and_never_block_delivery(): void
    {
        $this->aws->groups['app'] = true;
        $this->aws->failWith = fn (string $name) => in_array($name, ['PutRetentionPolicy', 'TagResource'], true)
            ? FakeCloudWatchLogs::error('AccessDeniedException', $name)
            : null;
        $handler = $this->handler(retention: 30, tags: ['team' => 'core'], enforce: true, batchSize: 1);

        $handler->handle($this->record('one'));
        $handler->handle($this->record('two'));

        $this->assertSame(['one', 'two'], $this->aws->delivered());
        $this->assertCount(1, $this->aws->calls('PutRetentionPolicy'), 'Attempted once per process, not on every flush.');
        $this->assertFalse($handler->isCircuitOpen());
        $this->assertCount(1, $this->reported);
        $this->assertStringContainsString('could not apply retention/tags', $this->reported[0]);
        $this->assertStringContainsString('AccessDeniedException', $this->reported[0]);
    }

    #[Test]
    public function enforcement_is_cached_per_group_and_settings_for_the_cache_ttl(): void
    {
        $this->aws->groups['app'] = true;
        $this->aws->streams['app|web'] = true;
        $cache = new LaravelCacheItemPool(new Repository(new ArrayStore), 'app');

        $this->handler(retention: 30, tags: ['a' => 'b'], enforce: true, cache: $cache, batchSize: 1)->handle($this->record('1'));
        $this->assertCount(1, $this->aws->calls('TagResource'));

        // A new process (handler) with the same settings: nothing to do.
        $this->aws->calls = [];
        $this->handler(retention: 30, tags: ['a' => 'b'], enforce: true, cache: $cache, batchSize: 1)->handle($this->record('2'));
        $this->assertSame(['PutLogEvents'], $this->aws->names());

        // Changed settings are a different cache entry, so they are applied.
        $this->aws->calls = [];
        $this->handler(retention: 60, tags: ['a' => 'b'], enforce: true, cache: $cache, batchSize: 1)->handle($this->record('3'));
        $this->assertSame(['DescribeLogGroups', 'PutRetentionPolicy', 'TagResource', 'PutLogEvents'], $this->aws->names());
        $this->assertSame(60, $this->aws->retention['app']);
    }

    #[Test]
    public function a_failed_enforcement_is_not_cached(): void
    {
        $this->aws->groups['app'] = true;
        $this->aws->streams['app|web'] = true;
        $cache = new LaravelCacheItemPool(new Repository(new ArrayStore), 'app');
        $this->aws->failWith = fn (string $name) => $name === 'TagResource' ? FakeCloudWatchLogs::error('AccessDeniedException', $name) : null;

        $this->handler(tags: ['a' => 'b'], enforce: true, cache: $cache, batchSize: 1)->handle($this->record('1'));

        $this->aws->failWith = null;
        $this->aws->calls = [];
        $this->handler(tags: ['a' => 'b'], enforce: true, cache: $cache, batchSize: 1)->handle($this->record('2'));

        // Retention was applied the first time; only the failed tagging is retried.
        $this->assertSame(['DescribeLogGroups', 'TagResource', 'PutLogEvents'], $this->aws->names());
    }

    #[Test]
    public function the_group_arn_is_derived_from_the_legacy_arn_field(): void
    {
        $this->assertSame('arn:aws:logs:eu-west-1:1:log-group:g', CloudWatchHandler::groupArn(['arn' => 'arn:aws:logs:eu-west-1:1:log-group:g:*']));
        $this->assertSame('arn:x', CloudWatchHandler::groupArn(['logGroupArn' => 'arn:x', 'arn' => 'arn:y:*']));
        $this->assertSame('', CloudWatchHandler::groupArn([]));
    }

    #[Test]
    public function enforcing_still_finds_and_tags_the_group_after_losing_a_creation_race(): void
    {
        // Another process creates the group between our describe and create.
        $first = true;
        $this->aws->failWith = function (string $name) use (&$first) {
            if ($name === 'CreateLogGroup' && $first) {
                $first = false;
                $this->aws->groups['app'] = true;

                return FakeCloudWatchLogs::error('ResourceAlreadyExistsException', $name);
            }

            return null;
        };
        $handler = $this->handler(retention: 30, tags: ['a' => 'b'], enforce: true, batchSize: 1);

        $handler->handle($this->record('x'));

        $this->assertSame(['DescribeLogGroups', 'CreateLogGroup', 'DescribeLogGroups', 'PutRetentionPolicy', 'TagResource', 'DescribeLogStreams', 'CreateLogStream', 'PutLogEvents'], $this->aws->names());
        $this->assertSame(['a' => 'b'], $this->aws->tags['app']);
    }

    #[Test]
    public function overflowing_records_go_to_the_fallback_once_each(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;
        $received = [];
        $handler = $this->handler(batchSize: 2, maxBufferSize: 2, fallback: function (array $records, string $reason) use (&$received): void {
            foreach ($records as $record) {
                $received[] = [$record->message, $reason];
            }
        });

        $handler->handle($this->record('one'));
        $handler->handle($this->record('two'));   // flush fails, circuit opens, both kept
        $handler->handle($this->record('three')); // overflow: 'one' goes to the fallback

        $this->assertSame([['one', 'buffer full']], $received);
        $this->assertSame(2, $handler->getBufferedEventCount(), 'Buffered records are not duplicated to the fallback while the circuit is open.');

        $handler->close();

        $this->assertSame([['one', 'buffer full'], ['two', 'undelivered when the handler closed'], ['three', 'undelivered when the handler closed']], $received);
        $this->assertSame(0, $handler->getBufferedEventCount());
        $this->assertStringContainsString('sent to the fallback channel', implode("\n", $this->reported));
    }

    #[Test]
    public function rejected_records_go_to_the_fallback_with_their_original_record(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('InvalidParameterException') : null;
        $received = [];
        $handler = $this->handler(batchSize: 1, fallback: function (array $records, string $reason) use (&$received): void {
            $received = [$records, $reason];
        });
        $record = $this->record('bad', level: Level::Critical);

        $handler->handle($record);

        $this->assertSame([[$record], 'rejected by CloudWatch'], $received);
    }

    #[Test]
    public function a_record_split_into_several_events_is_forwarded_once(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('InvalidParameterException') : null;
        $count = 0;
        $handler = $this->handler(fallback: function (array $records) use (&$count): void {
            $count += count($records);
        });

        $handler->handle($this->record(str_repeat('x', 2_500_000)));
        $handler->close();

        $this->assertSame(1, $count);
        $this->assertGreaterThan(1, $handler->getDroppedEventCount());
    }

    #[Test]
    public function nothing_goes_to_the_fallback_when_delivery_succeeds_or_while_retrying(): void
    {
        $down = true;
        $this->aws->failWith = function (string $name) use (&$down) {
            return $name === 'PutLogEvents' && $down ? FakeCloudWatchLogs::error('ServiceUnavailableException') : null;
        };
        $calls = 0;
        $handler = $this->handler(batchSize: 1, fallback: function () use (&$calls): void {
            $calls++;
        });

        $handler->handle($this->record('kept'));
        $down = false;
        $this->clock->advance(31);
        $handler->close();

        $this->assertSame(0, $calls);
        $this->assertSame(['kept'], $this->aws->delivered());
    }

    #[Test]
    public function a_fallback_that_logs_back_into_cloudwatch_cannot_recurse(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('InvalidParameterException') : null;
        $logger = new Logger('loop');
        $spy = new TestHandler;
        $handler = $this->handler(batchSize: 1, fallback: function (array $records) use (&$logger): void {
            foreach ($records as $record) {
                $logger->addRecord($record->level, 'fallback: '.$record->message);
            }
        });
        $logger->pushHandler($handler);
        $logger->pushHandler($spy);

        $logger->error('first');

        $this->assertSame(1, $handler->getDroppedEventCount(), 'The fallback write was not re-buffered by CloudWatch.');
        $this->assertSame(0, $handler->getBufferedEventCount());
        $this->assertTrue($spy->hasErrorThatContains('fallback: first'), 'Other handlers still receive it.');
    }

    #[Test]
    public function a_failing_fallback_is_reported_and_does_not_break_logging(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('InvalidParameterException') : null;
        $handler = $this->handler(batchSize: 1, fallback: function (): void {
            throw new RuntimeException('disk full');
        });

        $handler->handle($this->record('x'));

        $this->assertStringContainsString('the fallback channel failed RuntimeException: disk full', implode("\n", $this->reported));
    }

    /**
     * @param  array<string, string>  $tags
     * @param  array<string, string>  $placeholders
     */
    private function handler(
        string $stream = 'web',
        int $batchSize = 10000,
        int $maxBufferSize = 10000,
        ?int $retention = 14,
        array $tags = [],
        bool $enforce = false,
        ?CacheItemPoolInterface $cache = null,
        string $dateFormat = 'Y-m-d',
        array $placeholders = [],
        ?Closure $fallback = null,
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
            group: 'app',
            stream: $stream,
            retention: $retention,
            batchSize: $batchSize,
            tags: $tags,
            cacheItemPool: $cache,
            maxBufferSize: $maxBufferSize,
            flushInterval: 0,
            clock: $this->clock->now(...),
            sleeper: $this->clock->sleep(...),
            errorReporter: function (string $message): void {
                $this->reported[] = $message;
            },
            enforceGroupSettings: $enforce,
            streamDateFormat: $dateFormat,
            streamPlaceholders: $placeholders,
            fallback: $fallback,
        );
        $handler->setFormatter(new LineFormatter('%message%'));

        return $handler;
    }

    private function record(string $message, ?DateTimeImmutable $at = null, Level $level = Level::Error): LogRecord
    {
        return new LogRecord($at ?? new DateTimeImmutable, 'test', $level, $message);
    }
}
