<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Console;

use Aporat\CloudWatchLogger\CloudWatchConfig;
use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Checks a CloudWatch log channel end to end: configuration, credentials,
 * log group, log stream and a test event, with a pass/fail and timing per
 * step. Exits non-zero when any step fails. Secrets are never printed: the
 * access key ID is masked to its last four characters.
 */
final class CloudWatchTestCommand extends Command
{
    public const string PASS = 'PASS';

    public const string FAIL = 'FAIL';

    public const string WARN = 'WARN';

    public const string SKIP = 'SKIP';

    public const string DRY = 'DRY';

    /** @var string */
    protected $signature = 'cloudwatch:test
        {channel? : The log channel to test (defaults to the default channel when it uses CloudWatch, otherwise "cloudwatch")}
        {--dry-run : Only read: report what would be created and do not send the test event}';

    /** @var string */
    protected $description = 'Check that a CloudWatch log channel can reach CloudWatch Logs and deliver an event';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(CloudWatchLoggerFactory $factory, ConfigRepository $config): int
    {
        // The command instance is reused when called more than once in a process.
        $this->rows = [];
        $this->failed = false;
        $dryRun = (bool) $this->option('dry-run');
        $channel = $this->channelName($config);

        $this->line(sprintf('Testing log channel <info>%s</info>%s', $channel, $dryRun ? ' (dry run: nothing is created or sent)' : ''));

        /** @var CloudWatchConfig|null $resolved */
        $resolved = $this->step('Configuration', function () use ($config, $channel, $factory): array {
            $definition = $config->get("logging.channels.$channel");

            if (! is_array($definition)) {
                return [self::FAIL, "Log channel '$channel' is not defined in config/logging.php.", null];
            }

            if (($definition['via'] ?? null) !== CloudWatchLoggerFactory::class) {
                return [self::FAIL, "Log channel '$channel' does not use ".CloudWatchLoggerFactory::class.'.', null];
            }

            $resolved = $factory->resolveConfig($definition);

            return [self::PASS, sprintf('region %s, group %s, stream %s', $resolved->aws['region'], $resolved->group, $this->streamName($resolved)), $resolved];
        });

        $client = $resolved === null ? null : $this->step('Credentials', function () use ($factory, $resolved): array {
            $client = $factory->createClient($resolved);
            $credentials = $client->getCredentials()->wait();
            $key = $credentials->getAccessKeyId();

            return [self::PASS, sprintf(
                'access key ID ****%s%s, from %s',
                substr($key, -4),
                $credentials->getSecurityToken() ? ' (temporary)' : '',
                isset($resolved->aws['credentials']) ? 'the channel config' : 'the default AWS provider chain'
            ), $client];
        });

        $group = $client === null ? null : $this->step('Log group', fn () => $this->checkGroup($client, $resolved, $dryRun));

        if ($group !== null && $resolved->enforceGroupSettings && $group['exists']) {
            $this->step('Group settings', fn () => $this->checkGroupSettings($client, $resolved, $group['group'], $dryRun), required: false);
        }

        $stream = $group === null ? null : $this->step('Log stream', fn () => $this->checkStream($client, $resolved, $group['exists'], $dryRun));

        if ($stream !== null && $dryRun) {
            $this->skip('Send test event', '--dry-run: no event sent');
        } elseif ($stream !== null) {
            $this->step('Send test event', fn () => $this->sendEvent($client, $resolved, $stream, $channel));
        }

        foreach (['Configuration', 'Credentials', 'Log group', 'Log stream', 'Send test event'] as $name) {
            if (! in_array($name, array_column($this->rows, 0), true)) {
                $this->skip($name, 'skipped: a previous step failed');
            }
        }

        $this->table(['Step', 'Result', 'Time', 'Details'], array_map(
            fn (array $row) => [$row[0], $this->colour($row[1]), $row[2], $row[3]],
            $this->rows
        ));

        if ($this->failed) {
            $this->error('CloudWatch test failed.');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'CloudWatch dry run passed.' : 'CloudWatch test passed: a test event was delivered.');

        return self::SUCCESS;
    }

    private function channelName(ConfigRepository $config): string
    {
        $channel = $this->argument('channel');

        if (is_string($channel) && $channel !== '') {
            return $channel;
        }

        $default = $config->get('logging.default');

        if (is_string($default) && ($config->get("logging.channels.$default.via") === CloudWatchLoggerFactory::class)) {
            return $default;
        }

        return 'cloudwatch';
    }

    private function streamName(CloudWatchConfig $config): string
    {
        $stream = CloudWatchHandler::resolveStaticPlaceholders($config->stream, ['env' => $config->environment]);

        return str_replace('{date}', gmdate($config->streamDateFormat), $stream);
    }

    /**
     * @return array{0: string, 1: string, 2: array{exists: bool, group: array<string, mixed>|null}|null}
     */
    private function checkGroup(CloudWatchLogsClient $client, CloudWatchConfig $config, bool $dryRun): array
    {
        $existing = $this->describeGroup($client, $config->group);

        if ($existing !== null) {
            $retention = $existing['retentionInDays'] ?? null;

            return [self::PASS, 'exists, retention '.(is_int($retention) ? "$retention days" : 'never expire'), ['exists' => true, 'group' => $existing]];
        }

        if (! $config->createGroup) {
            return [self::FAIL, "does not exist and 'create_group' is false; create it or allow logs:CreateLogGroup", null];
        }

        if ($dryRun) {
            return [self::DRY, 'does not exist; would be created'.($config->retention !== null ? " with {$config->retention}-day retention" : ''), ['exists' => false, 'group' => null]];
        }

        $arguments = ['logGroupName' => $config->group];

        if ($config->tags !== []) {
            $arguments['tags'] = $config->tags;
        }

        $client->createLogGroup($arguments);

        if ($config->retention !== null) {
            $client->putRetentionPolicy(['logGroupName' => $config->group, 'retentionInDays' => $config->retention]);
        }

        return [self::PASS, 'created'.($config->retention !== null ? " with {$config->retention}-day retention" : ''), ['exists' => true, 'group' => $this->describeGroup($client, $config->group)]];
    }

    /**
     * @param  array<string, mixed>|null  $group
     * @return array{0: string, 1: string, 2: true}
     */
    private function checkGroupSettings(CloudWatchLogsClient $client, CloudWatchConfig $config, ?array $group, bool $dryRun): array
    {
        $changes = [];

        if ($config->retention !== null && ($group['retentionInDays'] ?? null) !== $config->retention) {
            $changes[] = "retention {$config->retention} days";
        }

        if ($config->tags !== []) {
            $changes[] = count($config->tags).' tag(s)';
        }

        if ($changes === []) {
            return [self::PASS, 'retention already matches; no tags configured', true];
        }

        if ($dryRun) {
            return [self::DRY, 'would apply '.implode(' and ', $changes), true];
        }

        if ($config->retention !== null && ($group['retentionInDays'] ?? null) !== $config->retention) {
            $client->putRetentionPolicy(['logGroupName' => $config->group, 'retentionInDays' => $config->retention]);
        }

        if ($config->tags !== []) {
            $client->tagResource(['resourceArn' => CloudWatchHandler::groupArn($group ?? []), 'tags' => $config->tags]);
        }

        return [self::PASS, 'applied '.implode(' and ', $changes), true];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function checkStream(CloudWatchLogsClient $client, CloudWatchConfig $config, bool $groupExists, bool $dryRun): array
    {
        $stream = $this->streamName($config);

        if (! $groupExists) {
            return [self::DRY, "$stream would be created with the group", $stream];
        }

        $streams = $client->describeLogStreams(['logGroupName' => $config->group, 'logStreamNamePrefix' => $stream])->get('logStreams');

        if (in_array($stream, array_column(is_array($streams) ? $streams : [], 'logStreamName'), true)) {
            return [self::PASS, "$stream exists", $stream];
        }

        if (! $config->createStream) {
            return [self::FAIL, "$stream does not exist and 'create_stream' is false", null];
        }

        if ($dryRun) {
            return [self::DRY, "$stream does not exist; would be created", $stream];
        }

        $client->createLogStream(['logGroupName' => $config->group, 'logStreamName' => $stream]);

        return [self::PASS, "$stream created", $stream];
    }

    /**
     * @return array{0: string, 1: string, 2: true|null}
     */
    private function sendEvent(CloudWatchLogsClient $client, CloudWatchConfig $config, string $stream, string $channel): array
    {
        $result = $client->putLogEvents([
            'logGroupName' => $config->group,
            'logStreamName' => $stream,
            'logEvents' => [[
                'timestamp' => (int) floor(microtime(true) * 1000),
                'message' => (string) json_encode([
                    'message' => 'cloudwatch:test event',
                    'channel' => $channel,
                    'host' => gethostname(),
                ], JSON_UNESCAPED_SLASHES),
            ]],
        ]);

        $rejected = $result->get('rejectedLogEventsInfo');

        if (is_array($rejected) && $rejected !== []) {
            return [self::FAIL, 'event rejected: '.(string) json_encode($rejected), null];
        }

        return [self::PASS, "event accepted by $config->group/$stream", true];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeGroup(CloudWatchLogsClient $client, string $name): ?array
    {
        $groups = $client->describeLogGroups(['logGroupNamePrefix' => $name])->get('logGroups');

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group) && ($group['logGroupName'] ?? null) === $name) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Run one step, time it and record the outcome. Returns the step's value,
     * or null when it failed (a non-required step never fails the run).
     *
     * @template T
     *
     * @param  Closure(): array{0: string, 1: string, 2: T}  $step
     * @return T|null
     */
    private function step(string $name, Closure $step, bool $required = true): mixed
    {
        $start = hrtime(true);

        try {
            [$status, $details, $value] = $step();
        } catch (Throwable $e) {
            [$status, $details, $value] = [$required ? self::FAIL : self::WARN, $this->describeError($e), null];
        }

        if ($status === self::FAIL && ! $required) {
            $status = self::WARN;
        }

        $this->rows[] = [$name, $status, sprintf('%.1f ms', (hrtime(true) - $start) / 1e6), $details];

        if ($status === self::FAIL) {
            $this->failed = true;

            return null;
        }

        return $value;
    }

    private function skip(string $name, string $details): void
    {
        $this->rows[] = [$name, self::SKIP, '-', $details];
    }

    private function describeError(Throwable $e): string
    {
        if ($e instanceof AwsException) {
            $operation = $e->getCommand()->getName();
            $code = $e->getAwsErrorCode();

            if ($e->isConnectionError()) {
                return "could not reach CloudWatch Logs ($operation): check the region, network and endpoint";
            }

            if (in_array($code, ['AccessDeniedException', 'AccessDenied', 'UnauthorizedOperation'], true)) {
                return "access denied: grant logs:$operation";
            }

            if (in_array($code, ['UnrecognizedClientException', 'InvalidSignatureException', 'ExpiredTokenException', 'InvalidClientTokenId'], true)) {
                return "$code: the credentials were rejected";
            }

            return sprintf('%s failed: %s', $operation, $code ?? $e->getAwsErrorMessage() ?? 'unknown error');
        }

        return $e::class.': '.$e->getMessage();
    }

    private function colour(string $status): string
    {
        return match ($status) {
            self::PASS => "<fg=green>$status</>",
            self::FAIL => "<fg=red>$status</>",
            self::WARN, self::DRY => "<fg=yellow>$status</>",
            default => $status,
        };
    }
}
