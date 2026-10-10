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

    /** @var list<array{timestamp: int, message: string}> */
    private array $buffer = [];

    /** Clock time at which the oldest buffered event was buffered. */
    private ?float $bufferStartedAt = null;

    private bool $initialized = false;

    /** Clock time until which no request is sent. */
    private float $circuitOpenUntil = 0.0;

    private int $droppedEvents = 0;

    private int $droppedEventsReported = 0;

    private int $requestsThisSecond = 0;

    private int $currentSecond = -1;

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @var Closure(string): void */
    private Closure $errorReporter;

    /**
     * @param  array<string, string>  $tags  Applied only when this handler creates the group.
     * @param  int  $batchSize  Buffered events that trigger a send.
     * @param  int  $maxBufferSize  Hard cap on buffered events; the oldest are dropped beyond it.
     * @param  int  $flushInterval  Seconds after which a write flushes an older buffer; 0 disables.
     * @param  int  $circuitBreakerSeconds  Seconds to stop sending after a failure; 0 disables.
     * @param  int  $rpsLimit  Max PutLogEvents calls per second from this handler; 0 disables.
     * @param  (Closure(): float)|null  $clock  Seconds as a float; defaults to microtime(true).
     * @param  (Closure(int): void)|null  $sleeper  Sleeps for N microseconds; defaults to usleep().
     * @param  (Closure(string): void)|null  $errorReporter  Defaults to error_log().
     */
    public function __construct(
        private readonly CloudWatchLogsClient $client,
        private readonly string $group,
        private readonly string $stream,
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

        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->errorReporter = $errorReporter ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function handle(LogRecord $record): bool
    {
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
            $this->buffer[] = $event;
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
     * non-retryable reason are dropped and counted.
     *
     * @throws Throwable When sending fails and failures are not suppressed.
     */
    public function flush(): void
    {
        if ($this->buffer === [] || $this->circuitIsOpen()) {
            return;
        }

        try {
            if (! $this->initialized) {
                $this->initialize();
            }
        } catch (Throwable $e) {
            // Group/stream setup failed: keep the events (bounded) and back off.
            $this->openCircuit();
            $this->enforceBufferCap();
            $this->fail('could not initialise the log group/stream', $e);

            return;
        }

        $batches = EventBatcher::batches($this->buffer);
        $this->buffer = [];

        while (($batch = array_shift($batches)) !== null) {
            try {
                $this->throttle();
                $this->client->putLogEvents([
                    'logGroupName' => $this->group,
                    'logStreamName' => $this->stream,
                    'logEvents' => $batch,
                ]);
            } catch (Throwable $e) {
                $this->openCircuit();

                if ($this->isRetryable($e)) {
                    // Keep this batch and everything after it for the next attempt.
                    $this->buffer = array_merge($batch, ...$batches);
                    $this->enforceBufferCap();
                } else {
                    $this->droppedEvents += count($batch);
                    $this->buffer = array_merge(...$batches);
                }

                if ($this->buffer === []) {
                    $this->bufferStartedAt = null;
                }

                $this->fail('PutLogEvents failed ('.($this->isRetryable($e) ? 'will retry' : 'events dropped').')', $e);

                return;
            }
        }

        $this->bufferStartedAt = null;
        $this->reportDroppedEvents();
    }

    /**
     * Flush on close (shutdown, Octane worker stop, explicit Log::close()).
     * The circuit breaker is honoured, so an outage cannot stall shutdown.
     */
    public function close(): void
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            if (! $this->suppressFailures) {
                throw $e;
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
     * Number of events dropped because the buffer was full or CloudWatch
     * rejected them for a non-retryable reason, since this handler was built.
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

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('%channel%: %level_name%: %message% %context% %extra%', null, false, true);
    }

    private function initialize(): void
    {
        if ($this->createGroup && ! $this->cached('group')) {
            $groups = $this->client->describeLogGroups(['logGroupNamePrefix' => $this->group])->get('logGroups');

            if (! in_array($this->group, array_column(is_array($groups) ? $groups : [], 'logGroupName'), true)) {
                $arguments = ['logGroupName' => $this->group];

                if ($this->tags !== []) {
                    $arguments['tags'] = $this->tags;
                }

                if ($this->createIgnoringExisting(fn () => $this->client->createLogGroup($arguments)) && $this->retention !== null) {
                    $this->client->putRetentionPolicy([
                        'logGroupName' => $this->group,
                        'retentionInDays' => $this->retention,
                    ]);
                }
            }

            $this->remember('group');
        }

        if ($this->createStream && ! $this->cached('stream')) {
            $streams = $this->client->describeLogStreams([
                'logGroupName' => $this->group,
                'logStreamNamePrefix' => $this->stream,
            ])->get('logStreams');

            if (! in_array($this->stream, array_column(is_array($streams) ? $streams : [], 'logStreamName'), true)) {
                $this->createIgnoringExisting(fn () => $this->client->createLogStream([
                    'logGroupName' => $this->group,
                    'logStreamName' => $this->stream,
                ]));
            }

            $this->remember('stream');
        }

        $this->initialized = true;
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
     * an entry, and any PSR-6 pool accepts the key.
     */
    private function cacheKey(string $kind): string
    {
        $name = $kind === 'group' ? $this->group : $this->group."\0".$this->stream;

        return 'cloudwatch_logger_'.$kind.'_'.sha1($name);
    }

    private function cached(string $kind): bool
    {
        if ($this->cacheItemPool === null) {
            return false;
        }

        try {
            return $this->cacheItemPool->getItem($this->cacheKey($kind))->isHit();
        } catch (Throwable) {
            return false;
        }
    }

    private function remember(string $kind): void
    {
        if ($this->cacheItemPool === null) {
            return;
        }

        try {
            $item = $this->cacheItemPool->getItem($this->cacheKey($kind));
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
            $this->buffer = array_slice($this->buffer, $overflow);
            $this->droppedEvents += $overflow;
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
            $this->report("dropped {$unreported} log event(s) (buffer full or rejected by CloudWatch).");
        }
    }

    private function report(string $message, ?Throwable $e = null): void
    {
        $line = "[cloudwatch-logger] {$this->group}/{$this->stream}: {$message}";

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
