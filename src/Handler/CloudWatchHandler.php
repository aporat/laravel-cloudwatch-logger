<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Closure;
use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;
use WeakMap;

/**
 * Monolog handler that buffers records and ships them to CloudWatch Logs.
 *
 * Guarantees, in order of importance:
 *
 *  - The buffer is bounded. After every send attempt it holds at most the
 *    events that failed with a retryable error, and never more than
 *    `$maxBufferSize` events; overflow drops the oldest events and is counted.
 *  - A failure never blocks the next caller: it opens a circuit breaker for
 *    `$circuitBreakerSeconds`, during which no request is made and records are
 *    only buffered (within the cap).
 *  - Every request satisfies the PutLogEvents limits (see {@see EventBatcher}).
 *  - With `$suppressFailures` (the default in the package config) nothing ever
 *    throws out of a log call; failures are reported through `error_log()`,
 *    never through a PSR logger, so a broken CloudWatch cannot recurse into
 *    itself via Laravel's exception reporting.
 *  - Records that are dropped (buffer overflow, a non-retryable rejection, or
 *    still undelivered when the handler is closed) are handed to the optional
 *    `$fallback` instead of being lost.
 *
 * The stream name may contain `{date}`, which is resolved per event from the
 * event's own timestamp (UTC, `$streamDateFormat`), so a long-running process
 * rolls over to a new stream at midnight UTC without being restarted.
 */
class CloudWatchHandler extends AbstractProcessingHandler
{
    /**
     * Error codes that describe a transient condition: the batch is kept and
     * sent again once the circuit breaker closes.
     */
    private const array RETRYABLE_ERROR_CODES = [
        'ThrottlingException',
        'Throttling',
        'ServiceUnavailableException',
        'ServiceUnavailable',
        'InternalFailure',
        'RequestTimeout',
        'RequestTimeoutException',
        'RequestLimitExceeded',
        'OperationAbortedException',
    ];

    /**
     * Streams remembered as ready per process; old entries are evicted so a
     * `{date}` stream cannot grow this without bound.
     */
    private const int MAX_READY_STREAMS = 16;

    /**
     * Set while records are being handed to a fallback channel. CloudWatch
     * handlers ignore records during that time, so a fallback that (directly
     * or via a stack) writes to CloudWatch can never loop.
     */
    private static int $forwardingDepth = 0;

    /** @var list<array{timestamp: int, message: string, record: LogRecord}> */
    private array $buffer = [];

    /** Clock time at which the oldest buffered event was buffered. */
    private ?float $bufferStartedAt = null;

    private bool $groupReady = false;

    private bool $groupSettingsApplied = false;

    /** @var array<string, true> */
    private array $readyStreams = [];

    /** Clock time until which no request is sent. */
    private float $circuitOpenUntil = 0.0;

    private int $droppedEvents = 0;

    private int $droppedEventsReported = 0;

    private int $requestsThisSecond = 0;

    private int $currentSecond = -1;

    /** Stream name with the static placeholders resolved; may still contain {date}. */
    private readonly string $streamTemplate;

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @var Closure(string): void */
    private Closure $errorReporter;

    /** @var (Closure(list<LogRecord>, string): void)|null */
    private ?Closure $fallback;

    /** @var WeakMap<LogRecord, true> Records already handed to the fallback. */
    private WeakMap $forwarded;

    /**
     * @param  string  $stream  Stream name; may contain {hostname}, {pid}, {date} and any key of `$streamPlaceholders`.
     * @param  array<string, string>  $tags  Applied when this handler creates the group (or always, with `$enforceGroupSettings`).
     * @param  int  $batchSize  Buffered events that trigger a send.
     * @param  int  $maxBufferSize  Hard cap on buffered events; the oldest are dropped beyond it.
     * @param  int  $flushInterval  Seconds after which a write flushes an older buffer; 0 disables.
     * @param  int  $circuitBreakerSeconds  Seconds to stop sending after a failure; 0 disables.
     * @param  int  $rpsLimit  Max PutLogEvents calls per second from this handler; 0 disables.
     * @param  bool  $enforceGroupSettings  Also apply retention and tags to a group that already exists.
     * @param  string  $streamDateFormat  PHP date() format for {date}, evaluated in UTC.
     * @param  array<string, string>  $streamPlaceholders  Extra static placeholders, e.g. ['env' => 'production'].
     * @param  (Closure(): float)|null  $clock  Seconds as a float; defaults to microtime(true).
     * @param  (Closure(int): void)|null  $sleeper  Sleeps for N microseconds; defaults to usleep().
     * @param  (Closure(string): void)|null  $errorReporter  Defaults to error_log().
     * @param  (Closure(list<LogRecord>, string): void)|null  $fallback  Receives records that would otherwise be lost.
     */
    public function __construct(
        private readonly CloudWatchLogsClient $client,
        private readonly string $group,
        string $stream,
        private readonly ?int $retention = 14,
        private readonly int $batchSize = 10000,
        private readonly array $tags = [],
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly bool $createGroup = true,
        private readonly bool $createStream = true,
        private readonly int $rpsLimit = 0,
        private readonly ?CacheItemPoolInterface $cacheItemPool = null,
        private readonly int $cacheItemTtl = 300,
        private readonly int $maxBufferSize = 10000,
        private readonly int $flushInterval = 10,
        private readonly int $circuitBreakerSeconds = 30,
        private readonly bool $suppressFailures = true,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
        ?Closure $errorReporter = null,
        private readonly bool $enforceGroupSettings = false,
        private readonly string $streamDateFormat = 'Y-m-d',
        array $streamPlaceholders = [],
        ?Closure $fallback = null,
    ) {
        if ($batchSize < 1 || $batchSize > EventBatcher::MAX_EVENTS_PER_BATCH) {
            throw new InvalidArgumentException('Batch size must be between 1 and '.EventBatcher::MAX_EVENTS_PER_BATCH.'.');
        }

        if ($maxBufferSize < $batchSize) {
            throw new InvalidArgumentException('The maximum buffer size may not be smaller than the batch size.');
        }

        if ($rpsLimit < 0 || $flushInterval < 0 || $circuitBreakerSeconds < 0 || $cacheItemTtl < 1) {
            throw new InvalidArgumentException('rps limit, flush interval and circuit breaker may not be negative; cache TTL must be positive.');
        }

        parent::__construct($level, $bubble);

        $this->streamTemplate = self::resolveStaticPlaceholders($stream, $streamPlaceholders);
        $this->fallback = $fallback;
        $this->forwarded = new WeakMap;
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->errorReporter = $errorReporter ?? static function (string $message): void {
            error_log($message);
        };
    }

    /**
     * Replace {hostname}, {pid} and the given extra placeholders; {date} is
     * left in place because it is resolved per event.
     *
     * @param  array<string, string>  $extra
     */
    public static function resolveStaticPlaceholders(string $stream, array $extra = []): string
    {
        $replacements = ['{hostname}' => (string) gethostname(), '{pid}' => (string) getmypid()];

        foreach ($extra as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return strtr($stream, $replacements);
    }

    public function handle(LogRecord $record): bool
    {
        if (self::$forwardingDepth > 0) {
            // A fallback channel is writing; never feed it back into CloudWatch.
            return false;
        }

        if (! $this->suppressFailures) {
            return parent::handle($record);
        }

        try {
            return parent::handle($record);
        } catch (Throwable $e) {
            $this->report('failed to handle a log record', $e);

            return $this->bubble === false;
        }
    }

    protected function write(LogRecord $record): void
    {
        $timestamp = (int) $record->datetime->format('Uv');

        foreach (EventBatcher::toEvents((string) $record->formatted, $timestamp) as $event) {
            $this->buffer[] = $event + ['record' => $record];
        }

        $this->bufferStartedAt ??= $this->now();

        if (count($this->buffer) >= $this->batchSize || $this->flushIntervalElapsed()) {
            $this->flush();
        }

        $this->enforceBufferCap();
    }

    /**
     * Send everything buffered, unless the circuit breaker is open.
     *
     * Afterwards the buffer holds only events that hit a retryable error
     * (capped at the maximum buffer size). Events rejected for a
     * non-retryable reason are dropped, counted and handed to the fallback.
     *
     * @throws Throwable When sending fails and failures are not suppressed.
     */
    public function flush(): void
    {
        if ($this->buffer === [] || $this->circuitIsOpen()) {
            return;
        }

        // Partition by stream: with {date} each event goes to its own day's stream.
        $byStream = [];

        foreach ($this->buffer as $event) {
            $byStream[$this->streamFor($event['timestamp'])][] = $event;
        }

        $this->buffer = [];

        while ($byStream !== []) {
            $stream = array_key_first($byStream);
            $events = $byStream[$stream];
            unset($byStream[$stream]);

            try {
                $this->initialize($stream);
            } catch (Throwable $e) {
                // Group/stream setup failed: keep the events (bounded) and back off.
                $this->buffer = array_merge($events, ...array_values($byStream));
                $this->openCircuit();
                $this->enforceBufferCap();
                $this->fail('could not initialise the log group/stream', $e);

                return;
            }

            $batches = EventBatcher::batches($events);

            while (($batch = array_shift($batches)) !== null) {
                try {
                    $this->throttle();
                    $this->client->putLogEvents([
                        'logGroupName' => $this->group,
                        'logStreamName' => $stream,
                        'logEvents' => array_map(
                            static fn (array $e): array => ['timestamp' => $e['timestamp'], 'message' => $e['message']],
                            $batch
                        ),
                    ]);
                } catch (Throwable $e) {
                    $this->openCircuit();
                    $retryable = $this->isRetryable($e);
                    $remaining = array_merge(...$batches, ...array_values($byStream));

                    if ($retryable) {
                        // Keep this batch and everything after it for the next attempt.
                        $this->buffer = array_merge($batch, $remaining);
                        $this->enforceBufferCap();
                    } else {
                        $this->drop($batch, 'rejected by CloudWatch');
                        $this->buffer = $remaining;
                    }

                    if ($this->buffer === []) {
                        $this->bufferStartedAt = null;
                    }

                    $this->fail('PutLogEvents failed ('.($retryable ? 'will retry' : 'events dropped').')', $e);

                    return;
                }
            }
        }

        $this->bufferStartedAt = null;
        $this->reportDroppedEvents();
    }

    /**
     * Flush on close (shutdown, Octane worker stop, explicit Log::close()).
     * The circuit breaker is honoured, so an outage cannot stall shutdown.
     * With a fallback configured, whatever could not be delivered is handed
     * to it rather than lost with the process.
     */
    public function close(): void
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            if (! $this->suppressFailures) {
                throw $e;
            }
        } finally {
            if ($this->fallback !== null && $this->buffer !== []) {
                $undelivered = $this->buffer;
                $this->buffer = [];
                $this->bufferStartedAt = null;
                $this->drop($undelivered, 'undelivered when the handler closed');
            }
        }

        $this->reportDroppedEvents();
    }

    /**
     * Monolog calls reset() between Octane requests (FlushMonologState) and
     * long-running consumers call it between units of work; send what is
     * buffered so it does not wait for the next batch to fill.
     */
    public function reset(): void
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            if (! $this->suppressFailures) {
                throw $e;
            }
        }

        parent::reset();
    }

    /**
     * Number of events dropped because the buffer was full, CloudWatch
     * rejected them for a non-retryable reason, or they were still undelivered
     * when the handler closed with a fallback configured.
     */
    public function getDroppedEventCount(): int
    {
        return $this->droppedEvents;
    }

    public function getBufferedEventCount(): int
    {
        return count($this->buffer);
    }

    public function isCircuitOpen(): bool
    {
        return $this->circuitIsOpen();
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * The stream an event logged now would be written to.
     */
    public function getStream(): string
    {
        return $this->streamFor((int) floor($this->now() * 1000));
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('%channel%: %level_name%: %message% %context% %extra%', null, false, true);
    }

    private function streamFor(int $timestampMs): string
    {
        if (! str_contains($this->streamTemplate, '{date}')) {
            return $this->streamTemplate;
        }

        return str_replace('{date}', gmdate($this->streamDateFormat, intdiv($timestampMs, 1000)), $this->streamTemplate);
    }

    private function initialize(string $stream): void
    {
        if (! $this->groupReady) {
            $this->initializeGroup();
            $this->groupReady = true;
        }

        if (isset($this->readyStreams[$stream])) {
            return;
        }

        if ($this->createStream && ! $this->cached('stream', $stream)) {
            $streams = $this->client->describeLogStreams([
                'logGroupName' => $this->group,
                'logStreamNamePrefix' => $stream,
            ])->get('logStreams');

            if (! in_array($stream, array_column(is_array($streams) ? $streams : [], 'logStreamName'), true)) {
                $this->createIgnoringExisting(fn () => $this->client->createLogStream([
                    'logGroupName' => $this->group,
                    'logStreamName' => $stream,
                ]));
            }

            $this->remember('stream', $stream);
        }

        if (count($this->readyStreams) >= self::MAX_READY_STREAMS) {
            array_shift($this->readyStreams);
        }

        $this->readyStreams[$stream] = true;
    }

    private function initializeGroup(): void
    {
        $needGroup = $this->createGroup && ! $this->cached('group');
        $needSettings = $this->wantsGroupSettings() && ! $this->groupSettingsApplied && ! $this->cached('settings');

        if (! $needGroup && ! $needSettings) {
            return;
        }

        $existing = $this->describeGroup();
        $created = false;

        if ($existing === null && $this->createGroup) {
            $arguments = ['logGroupName' => $this->group];

            if ($this->tags !== []) {
                $arguments['tags'] = $this->tags;
            }

            $created = $this->createIgnoringExisting(fn () => $this->client->createLogGroup($arguments));

            if ($created && $this->retention !== null) {
                $this->client->putRetentionPolicy([
                    'logGroupName' => $this->group,
                    'retentionInDays' => $this->retention,
                ]);
            }

            if (! $created) {
                // Another process created it first; look it up for its ARN.
                $existing = $this->describeGroup();
            }
        }

        if ($needGroup) {
            $this->remember('group');
        }

        if ($needSettings) {
            $this->groupSettingsApplied = true;

            if ($created) {
                // Created with these settings a moment ago.
                $this->remember('settings');
            } elseif ($existing !== null) {
                $this->applyGroupSettings($existing);
            }
        }
    }

    private function wantsGroupSettings(): bool
    {
        return $this->enforceGroupSettings && ($this->retention !== null || $this->tags !== []);
    }

    /**
     * Apply retention and tags to a group this handler did not create.
     *
     * Failures (typically a missing logs:PutRetentionPolicy / logs:TagResource
     * permission) are reported and never block delivery; they are retried in
     * the next process, or after the cache TTL.
     *
     * @param  array<string, mixed>  $group  The DescribeLogGroups entry.
     */
    private function applyGroupSettings(array $group): void
    {
        try {
            if ($this->retention !== null && ($group['retentionInDays'] ?? null) !== $this->retention) {
                $this->client->putRetentionPolicy([
                    'logGroupName' => $this->group,
                    'retentionInDays' => $this->retention,
                ]);
            }

            if ($this->tags !== []) {
                $this->client->tagResource([
                    'resourceArn' => self::groupArn($group),
                    'tags' => $this->tags,
                ]);
            }

            $this->remember('settings');
        } catch (Throwable $e) {
            $this->report('could not apply retention/tags to the existing log group (logging continues).', $e);
        }
    }

    /**
     * TagResource wants the log group ARN without the trailing ":*" that the
     * legacy `arn` field carries.
     *
     * @param  array<string, mixed>  $group
     */
    public static function groupArn(array $group): string
    {
        if (is_string($group['logGroupArn'] ?? null)) {
            return $group['logGroupArn'];
        }

        $arn = is_string($group['arn'] ?? null) ? $group['arn'] : '';

        return str_ends_with($arn, ':*') ? substr($arn, 0, -2) : $arn;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeGroup(): ?array
    {
        $groups = $this->client->describeLogGroups(['logGroupNamePrefix' => $this->group])->get('logGroups');

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group) && ($group['logGroupName'] ?? null) === $this->group) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Run a create call, treating "already exists" (another process won the
     * race) as success.
     *
     * @param  Closure(): mixed  $create
     * @return bool Whether this call created the resource.
     */
    private function createIgnoringExisting(Closure $create): bool
    {
        try {
            $create();

            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceAlreadyExistsException') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Cache keys are distinct per kind and per group, and hashed, so a stream
     * named like its group, or two groups sharing a stream name, never share
     * an entry, and any PSR-6 pool accepts the key. The settings key covers
     * the desired retention and tags, so changing them re-applies them.
     */
    private function cacheKey(string $kind, string $stream = ''): string
    {
        $name = match ($kind) {
            'group' => $this->group,
            'stream' => $this->group."\0".$stream,
            default => $this->group."\0".json_encode([$this->retention, $this->tags]),
        };

        return 'cloudwatch_logger_'.$kind.'_'.sha1($name);
    }

    private function cached(string $kind, string $stream = ''): bool
    {
        if ($this->cacheItemPool === null) {
            return false;
        }

        try {
            return $this->cacheItemPool->getItem($this->cacheKey($kind, $stream))->isHit();
        } catch (Throwable) {
            return false;
        }
    }

    private function remember(string $kind, string $stream = ''): void
    {
        if ($this->cacheItemPool === null) {
            return;
        }

        try {
            $item = $this->cacheItemPool->getItem($this->cacheKey($kind, $stream));
            $this->cacheItemPool->save($item->set(true)->expiresAfter($this->cacheItemTtl));
        } catch (Throwable) {
            // A cache failure only costs a describe call next time.
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof CredentialsException) {
            return true;
        }

        if (! $e instanceof AwsException) {
            return false;
        }

        if ($e->isConnectionError()) {
            return true;
        }

        if (in_array($e->getAwsErrorCode(), self::RETRYABLE_ERROR_CODES, true)) {
            return true;
        }

        $status = $e->getStatusCode();

        if ($status === null) {
            // No HTTP response and no error code: the request never completed.
            return $e->getAwsErrorCode() === null;
        }

        return $status >= 500 || $status === 429;
    }

    private function throttle(): void
    {
        if ($this->rpsLimit === 0) {
            return;
        }

        $now = $this->now();
        $second = (int) floor($now);

        if ($second !== $this->currentSecond) {
            $this->currentSecond = $second;
            $this->requestsThisSecond = 0;
        }

        if ($this->requestsThisSecond >= $this->rpsLimit) {
            ($this->sleeper)((int) ceil(($second + 1 - $now) * 1_000_000));
            $this->currentSecond = $second + 1;
            $this->requestsThisSecond = 0;
        }

        $this->requestsThisSecond++;
    }

    private function enforceBufferCap(): void
    {
        $overflow = count($this->buffer) - $this->maxBufferSize;

        if ($overflow > 0) {
            // Drop the oldest: under an outage the most recent events are the
            // ones still worth delivering.
            $this->drop(array_slice($this->buffer, 0, $overflow), 'buffer full');
            $this->buffer = array_slice($this->buffer, $overflow);
        }
    }

    /**
     * Count dropped events and hand their records to the fallback, once per
     * record even when a record was split into several events.
     *
     * @param  list<array{timestamp: int, message: string, record: LogRecord}>  $events
     */
    private function drop(array $events, string $reason): void
    {
        $this->droppedEvents += count($events);

        if ($this->fallback === null || $events === []) {
            return;
        }

        $records = [];

        foreach ($events as $event) {
            $record = $event['record'];

            // A record split into several events may be dropped in parts.
            if (! isset($this->forwarded[$record])) {
                $this->forwarded[$record] = true;
                $records[spl_object_id($record)] = $record;
            }
        }

        if ($records === []) {
            return;
        }

        self::$forwardingDepth++;

        try {
            ($this->fallback)(array_values($records), $reason);
        } catch (Throwable $e) {
            $this->report('the fallback channel failed', $e);
        } finally {
            self::$forwardingDepth--;
        }
    }

    private function flushIntervalElapsed(): bool
    {
        return $this->flushInterval > 0
            && $this->bufferStartedAt !== null
            && $this->now() - $this->bufferStartedAt >= $this->flushInterval;
    }

    private function circuitIsOpen(): bool
    {
        return $this->now() < $this->circuitOpenUntil;
    }

    private function openCircuit(): void
    {
        if ($this->circuitBreakerSeconds > 0) {
            $this->circuitOpenUntil = $this->now() + $this->circuitBreakerSeconds;
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }

    /**
     * @throws Throwable
     */
    private function fail(string $what, Throwable $e): void
    {
        if (! $this->suppressFailures) {
            throw $e;
        }

        $suffix = $this->circuitBreakerSeconds > 0 ? " Pausing sends for {$this->circuitBreakerSeconds}s." : '';
        $this->report($what.'.'.$suffix, $e);
    }

    private function reportDroppedEvents(): void
    {
        $unreported = $this->droppedEvents - $this->droppedEventsReported;

        if ($unreported > 0) {
            $this->droppedEventsReported = $this->droppedEvents;
            $where = $this->fallback !== null ? ' and sent to the fallback channel' : '';
            $this->report("dropped {$unreported} log event(s) (buffer full, rejected by CloudWatch or undelivered at close){$where}.");
        }
    }

    private function report(string $message, ?Throwable $e = null): void
    {
        $line = "[cloudwatch-logger] {$this->group}/{$this->streamTemplate}: {$message}";

        if ($e !== null) {
            $line .= ' '.$e::class.': '.$e->getMessage();
        }

        try {
            ($this->errorReporter)($line);
        } catch (Throwable) {
            // Reporting must never be the thing that breaks logging.
        }
    }
}
