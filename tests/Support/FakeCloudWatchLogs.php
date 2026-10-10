<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Support;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Closure;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * An in-memory CloudWatch Logs endpoint for the AWS SDK's `handler` option.
 *
 * Every call is answered by an {@see MockHandler} queue entry that is
 * appended on demand, so tests describe behaviour ("PutLogEvents fails while
 * $down") rather than an exact call sequence. Like the real service it
 * rejects PutLogEvents requests that break the documented limits, and it
 * records the serialized request body so tests can check what went on the
 * wire.
 */
final class FakeCloudWatchLogs
{
    public const int MAX_EVENTS = 10000;

    public const int MAX_BYTES = 1048576;

    private readonly MockHandler $mock;

    /** @var list<array{name: string, args: array<string, mixed>, body: string}> */
    public array $calls = [];

    /** @var array<string, true> */
    public array $groups = [];

    /** @var array<string, true> */
    public array $streams = [];

    /** @var (Closure(string, array<string, mixed>): (Throwable|Result|null))|null */
    public ?Closure $failWith = null;

    public function __construct()
    {
        $this->mock = new MockHandler;
    }

    public function __invoke(CommandInterface $command, ?RequestInterface $request = null): mixed
    {
        $this->mock->append(fn (CommandInterface $cmd) => $this->respond($cmd, $request));

        return ($this->mock)($command, $request);
    }

    public static function error(string $code, string $operation = 'PutLogEvents', array $context = []): CloudWatchLogsException
    {
        return new CloudWatchLogsException("$code: simulated", new Command($operation), ['code' => $code] + $context);
    }

    public static function connectionError(string $operation = 'PutLogEvents'): CloudWatchLogsException
    {
        return new CloudWatchLogsException('cURL error 7: Failed to connect (simulated)', new Command($operation), ['connection_error' => true]);
    }

    /**
     * @return list<array{name: string, args: array<string, mixed>, body: string}>
     */
    public function calls(string $name): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['name'] === $name));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->calls, 'name');
    }

    /**
     * Every event message that was accepted, in order.
     *
     * @return list<string>
     */
    public function delivered(): array
    {
        $messages = [];

        foreach ($this->calls('PutLogEvents') as $call) {
            if (($call['accepted'] ?? false) === true) {
                foreach ($call['args']['logEvents'] as $event) {
                    $messages[] = $event['message'];
                }
            }
        }

        return $messages;
    }

    private function respond(CommandInterface $command, ?RequestInterface $request): Throwable|Result
    {
        $name = $command->getName();
        $args = $command->toArray();
        unset($args['@http'], $args['@context']);

        $index = count($this->calls);
        $this->calls[] = ['name' => $name, 'args' => $args, 'body' => $request !== null ? (string) $request->getBody() : ''];

        $injected = $this->failWith !== null ? ($this->failWith)($name, $args) : null;

        if ($injected !== null) {
            return $injected;
        }

        $result = match ($name) {
            'DescribeLogGroups' => new Result(['logGroups' => array_map(
                fn (string $g) => ['logGroupName' => $g],
                array_values(array_filter(array_keys($this->groups), fn (string $g) => str_starts_with($g, (string) ($args['logGroupNamePrefix'] ?? ''))))
            )]),
            'DescribeLogStreams' => new Result(['logStreams' => array_map(
                fn (string $s) => ['logStreamName' => substr($s, strlen($args['logGroupName']) + 1)],
                array_values(array_filter(array_keys($this->streams), fn (string $s) => str_starts_with($s, $args['logGroupName'].'|'.($args['logStreamNamePrefix'] ?? ''))))
            )]),
            'CreateLogGroup' => $this->create($this->groups, $args['logGroupName'], $name),
            'CreateLogStream' => $this->create($this->streams, $args['logGroupName'].'|'.$args['logStreamName'], $name),
            'PutLogEvents' => $this->put($args),
            default => new Result([]),
        };

        if ($name === 'PutLogEvents' && $result instanceof Result) {
            $this->calls[$index]['accepted'] = true;
        }

        return $result;
    }

    /**
     * @param  array<string, true>  $registry
     */
    private function create(array &$registry, string $key, string $operation): Throwable|Result
    {
        if (isset($registry[$key])) {
            return self::error('ResourceAlreadyExistsException', $operation);
        }

        $registry[$key] = true;

        return new Result([]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function put(array $args): Throwable|Result
    {
        if (! isset($this->streams[$args['logGroupName'].'|'.$args['logStreamName']])) {
            return self::error('ResourceNotFoundException');
        }

        $events = $args['logEvents'];
        $bytes = array_sum(array_map(fn (array $e) => strlen($e['message']) + 26, $events));
        $timestamps = array_column($events, 'timestamp');
        $sorted = $timestamps;
        sort($sorted);

        if (count($events) > self::MAX_EVENTS || $bytes > self::MAX_BYTES || $sorted !== $timestamps
            || max($timestamps) - min($timestamps) >= 86_400_000) {
            return self::error('InvalidParameterException');
        }

        return new Result(['nextSequenceToken' => null]);
    }
}
