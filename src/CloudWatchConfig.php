<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aws\Credentials\CredentialsInterface;
use Monolog\Level;

/**
 * Validated, type-safe view of a CloudWatch channel configuration array.
 *
 * Every value the factory needs is resolved and checked here, so a bad channel
 * definition fails with an {@see IncompleteCloudWatchConfig} naming the offending
 * key instead of surfacing later as an opaque AWS or Monolog error on the first
 * log write.
 */
final readonly class CloudWatchConfig
{
    /**
     * Default log retention period in days.
     */
    public const int DEFAULT_RETENTION_DAYS = 14;

    /**
     * Default number of log entries to batch before sending.
     */
    public const int DEFAULT_BATCH_SIZE = 10000;

    /**
     * Hard ceiling enforced by the CloudWatch PutLogEvents API.
     */
    public const int MAX_BATCH_SIZE = 10000;

    /**
     * Default lifetime, in seconds, of a cached "group/stream exists" marker.
     */
    public const int DEFAULT_CACHE_TTL = 300;

    /**
     * Default cap on buffered events; the oldest are dropped beyond it.
     */
    public const int DEFAULT_MAX_BUFFER_SIZE = 10000;

    /**
     * Default age, in seconds, after which a write flushes the buffer.
     */
    public const int DEFAULT_FLUSH_INTERVAL = 10;

    /**
     * Default number of seconds to stop sending after a failed request.
     */
    public const int DEFAULT_CIRCUIT_BREAKER = 30;

    /**
     * HTTP options merged under the user's `aws.http`, so an unreachable
     * endpoint costs about a second instead of minutes per request.
     *
     * @var array<string, int|float>
     */
    public const array DEFAULT_HTTP_OPTIONS = [
        'connect_timeout' => 1,
        'timeout' => 3,
    ];

    /**
     * Retries used when `aws.retries` is not set: one retry (two attempts).
     *
     * Deliberately an integer rather than `['mode' => 'standard', ...]`: the
     * SDK hands the client's `retries` value to its default credential
     * provider, and the instance-profile (IMDS) provider compares an array
     * `retries` as "always more attempts left", so off EC2 with no other
     * credentials an array value makes the first request retry IMDS forever
     * (verified with aws/aws-sdk-php 3.400). An integer is safe for both.
     */
    public const int DEFAULT_RETRIES = 1;

    /**
     * Default PHP date() format for the {date} stream placeholder (UTC).
     */
    public const string DEFAULT_STREAM_DATE_FORMAT = 'Y-m-d';

    /**
     * Maximum length of a log group or log stream name.
     */
    private const int MAX_NAME_LENGTH = 512;

    /**
     * The only retention periods CloudWatch Logs accepts for PutRetentionPolicy.
     *
     * @var list<int>
     */
    public const array RETENTION_DAYS = [
        1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096,
        1827, 2192, 2557, 2922, 3288, 3653,
    ];

    /**
     * @param  array<string, mixed>  $aws  Arguments for the CloudWatchLogsClient constructor.
     * @param  int|null  $retention  Days to retain events, or null to never expire.
     * @param  array<string, string>  $tags  Tags applied when the log group is created.
     * @param  mixed  $cache  Raw PSR-6 cache setting; resolved by the factory.
     * @param  mixed  $formatter  Raw formatter setting; resolved by the factory.
     * @param  array<array-key, mixed>  $formatterWith  Constructor arguments for a formatter class.
     * @param  class-string<CloudWatchHandler>|null  $handlerClass  Optional CloudWatchHandler subclass.
     * @param  array<string, mixed>  $handlerWith  Named handler constructor arguments overriding the computed ones.
     * @param  array<string, mixed>  $originalConfig  The unmodified channel config.
     */
    private function __construct(
        public array $aws,
        public string $group,
        public string $stream,
        public string $name,
        public ?int $retention,
        public int $batchSize,
        public int $maxBufferSize,
        public int $flushInterval,
        public int $circuitBreaker,
        public array $tags,
        public Level $level,
        public bool $bubble,
        public bool $createGroup,
        public bool $createStream,
        public int $rpsLimit,
        public mixed $cache,
        public int $cacheTtl,
        public bool $suppressFailures,
        public bool $replacePlaceholders,
        public mixed $formatter,
        public array $formatterWith,
        public ?string $handlerClass,
        public array $handlerWith,
        public bool $enforceGroupSettings,
        public string $streamDateFormat,
        public string $environment,
        public ?string $fallbackChannel,
        public array $originalConfig,
    ) {}

    /**
     * Build a validated config from a raw Laravel channel definition.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    public static function fromArray(array $config, ?string $environment = null): self
    {
        $environment ??= self::defaultEnvironment();
        $streamDateFormat = self::streamDateFormat($config);
        $aws = self::aws($config);
        $group = self::groupName($config);
        $stream = self::streamName($config, $streamDateFormat, $environment);
        $name = self::requiredString($config, 'name', 'logger name');
        $retention = self::retention($config);
        $batchSize = self::batchSize($config);

        return new self(
            aws: $aws,
            group: $group,
            stream: $stream,
            name: $name,
            retention: $retention,
            batchSize: $batchSize,
            maxBufferSize: self::maxBufferSize($config, $batchSize),
            flushInterval: self::nonNegativeInt($config, 'flush_interval', self::DEFAULT_FLUSH_INTERVAL),
            circuitBreaker: self::nonNegativeInt($config, 'circuit_breaker', self::DEFAULT_CIRCUIT_BREAKER),
            tags: self::tags($config),
            level: self::level($config['level'] ?? Level::Debug),
            bubble: self::bool($config, 'bubble', true),
            createGroup: self::bool($config, 'create_group', true),
            createStream: self::bool($config, 'create_stream', true),
            rpsLimit: self::rpsLimit($config),
            cache: $config['cache'] ?? null,
            cacheTtl: self::cacheTtl($config),
            suppressFailures: self::bool($config, 'suppress_failures', true),
            replacePlaceholders: self::bool($config, 'replace_placeholders', false),
            formatter: $config['formatter'] ?? null,
            formatterWith: self::formatterWith($config),
            handlerClass: self::handlerClass($config),
            handlerWith: self::handlerWith($config),
            enforceGroupSettings: self::bool($config, 'enforce_group_settings', false),
            streamDateFormat: $streamDateFormat,
            environment: $environment,
            fallbackChannel: self::optionalString($config, 'fallback_channel'),
            originalConfig: $config,
        );
    }

    /**
     * Validate the AWS client arguments.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function aws(array $config): array
    {
        $aws = $config['aws'] ?? null;

        if (! is_array($aws) || $aws === []) {
            throw new IncompleteCloudWatchConfig('Missing or invalid AWS configuration in CloudWatch configuration.');
        }

        foreach (['region', 'version'] as $key) {
            if (! is_string($aws[$key] ?? null) || trim($aws[$key]) === '') {
                throw new IncompleteCloudWatchConfig("Missing or invalid AWS '$key' in CloudWatch configuration.");
            }
        }

        $aws = self::withClientDefaults($aws);

        $credentials = $aws['credentials'] ?? null;

        if ($credentials === null || $credentials instanceof CredentialsInterface || is_callable($credentials)) {
            return $aws;
        }

        if (! is_array($credentials)) {
            throw new IncompleteCloudWatchConfig("AWS 'credentials' must be an array, a CredentialsInterface or a callable when provided.");
        }

        $key = is_string($credentials['key'] ?? null) ? trim($credentials['key']) : '';
        $secret = is_string($credentials['secret'] ?? null) ? trim($credentials['secret']) : '';

        // Partial credentials (one set, one empty) is clearly a config error.
        // Both empty is intentional: defer to the AWS default credential chain
        // (IAM role, env vars, ~/.aws/credentials) — drop the empty block so
        // the SDK doesn't reject it.
        if (($key === '') !== ($secret === '')) {
            throw new IncompleteCloudWatchConfig("AWS credentials require both 'key' and 'secret' or neither.");
        }

        if ($key === '') {
            unset($aws['credentials']);
        }

        return $aws;
    }

    /**
     * Apply the package's client defaults underneath the user's values.
     *
     * @param  array<string, mixed>  $aws
     * @return array<string, mixed>
     */
    private static function withClientDefaults(array $aws): array
    {
        $http = $aws['http'] ?? [];

        if (is_array($http)) {
            $aws['http'] = array_replace(self::DEFAULT_HTTP_OPTIONS, $http);
        }

        if (! array_key_exists('retries', $aws) || $aws['retries'] === null) {
            $aws['retries'] = self::DEFAULT_RETRIES;
        }

        return $aws;
    }

    /**
     * Validate the log group name against CloudWatch's naming rules, so an
     * invalid name fails here rather than as an AWS InvalidParameterException
     * on the first flush (which the handler retries after a one-second sleep).
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function groupName(array $config): string
    {
        $group = self::requiredString($config, 'group', 'log group name');

        if (mb_strlen($group) > self::MAX_NAME_LENGTH) {
            throw new IncompleteCloudWatchConfig('CloudWatch log group name may not exceed '.self::MAX_NAME_LENGTH.' characters.');
        }

        if (preg_match('/^[A-Za-z0-9_\-\/.#]+$/', $group) !== 1) {
            throw new IncompleteCloudWatchConfig(
                "Invalid CloudWatch log group name '$group': only letters, numbers, '_', '-', '/', '.' and '#' are allowed."
            );
        }

        return $group;
    }

    /**
     * Validate the log stream name against CloudWatch's naming rules, after
     * expanding the {hostname}, {pid}, {env} and {date} placeholders the
     * handler supports.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function streamName(array $config, string $dateFormat, string $environment): string
    {
        $stream = self::requiredString($config, 'stream', 'log stream name');

        $expanded = str_replace(
            '{date}',
            gmdate($dateFormat),
            CloudWatchHandler::resolveStaticPlaceholders($stream, ['env' => $environment])
        );

        if (mb_strlen($expanded) > self::MAX_NAME_LENGTH) {
            throw new IncompleteCloudWatchConfig('CloudWatch log stream name may not exceed '.self::MAX_NAME_LENGTH.' characters.');
        }

        if (str_contains($expanded, ':') || str_contains($expanded, '*')) {
            throw new IncompleteCloudWatchConfig("Invalid CloudWatch log stream name '$expanded': ':' and '*' are not allowed.");
        }

        return $stream;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function streamDateFormat(array $config): string
    {
        $format = $config['stream_date_format'] ?? self::DEFAULT_STREAM_DATE_FORMAT;

        if (! is_string($format) || trim($format) === '') {
            throw new IncompleteCloudWatchConfig("CloudWatch 'stream_date_format' must be a non-empty date() format.");
        }

        return $format;
    }

    /**
     * The value of {env}: Laravel's app.env when available.
     */
    private static function defaultEnvironment(): string
    {
        $env = getenv('APP_ENV');

        return is_string($env) && $env !== '' ? $env : 'production';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<array-key, mixed>
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function formatterWith(array $config): array
    {
        $with = $config['formatter_with'] ?? [];

        if (! is_array($with)) {
            throw new IncompleteCloudWatchConfig("CloudWatch 'formatter_with' must be an array of constructor arguments.");
        }

        return $with;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return class-string<CloudWatchHandler>|null
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function handlerClass(array $config): ?string
    {
        $class = $config['handler'] ?? null;

        if ($class === null || $class === CloudWatchHandler::class) {
            return null;
        }

        if (! is_string($class) || ! is_a($class, CloudWatchHandler::class, true)) {
            throw new IncompleteCloudWatchConfig("CloudWatch 'handler' must be the name of a ".CloudWatchHandler::class.' subclass.');
        }

        return $class;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function handlerWith(array $config): array
    {
        $with = $config['handler_with'] ?? [];

        if (! is_array($with)) {
            throw new IncompleteCloudWatchConfig("CloudWatch 'handler_with' must be an array of named handler arguments.");
        }

        $validated = [];

        foreach ($with as $key => $value) {
            if (! is_string($key)) {
                throw new IncompleteCloudWatchConfig("CloudWatch 'handler_with' must be keyed by constructor parameter name.");
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_string($value)) {
            throw new IncompleteCloudWatchConfig("CloudWatch '$key' must be a string.");
        }

        return $value;
    }

    /**
     * Resolve the retention period.
     *
     * `null` (and an empty string, which is what `env()` yields for an unset
     * variable) means "never expire". Anything else must be one of the periods
     * CloudWatch actually accepts.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function retention(array $config): ?int
    {
        $retention = array_key_exists('retention', $config) ? $config['retention'] : self::DEFAULT_RETENTION_DAYS;

        // null / '' (an unset env var) / false all mean "never expire".
        if ($retention === null || $retention === '' || $retention === false) {
            return null;
        }

        if (! is_int($retention) && ! (is_string($retention) && ctype_digit($retention))) {
            throw new IncompleteCloudWatchConfig('CloudWatch log retention must be an integer number of days or null.');
        }

        $retention = (int) $retention;

        if (! in_array($retention, self::RETENTION_DAYS, true)) {
            throw new IncompleteCloudWatchConfig(
                "Invalid CloudWatch log retention '$retention'. Use null (never expire) or one of: ".implode(', ', self::RETENTION_DAYS).'.'
            );
        }

        return $retention;
    }

    /**
     * Resolve the batch size, enforcing the PutLogEvents ceiling up front
     * rather than letting the handler throw an InvalidArgumentException.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function batchSize(array $config): int
    {
        $batchSize = self::intValue($config, 'batch_size', self::DEFAULT_BATCH_SIZE);

        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new IncompleteCloudWatchConfig(
                'CloudWatch batch size must be between 1 and '.self::MAX_BATCH_SIZE.", got '$batchSize'."
            );
        }

        return $batchSize;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function maxBufferSize(array $config, int $batchSize): int
    {
        $max = self::intValue($config, 'max_buffer_size', max(self::DEFAULT_MAX_BUFFER_SIZE, $batchSize));

        if ($max < $batchSize) {
            throw new IncompleteCloudWatchConfig(
                "CloudWatch max_buffer_size ('$max') may not be smaller than batch_size ('$batchSize')."
            );
        }

        return $max;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function nonNegativeInt(array $config, string $key, int $default): int
    {
        $value = self::intValue($config, $key, $default);

        if ($value < 0) {
            throw new IncompleteCloudWatchConfig("CloudWatch $key may not be negative.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function rpsLimit(array $config): int
    {
        $rpsLimit = self::intValue($config, 'rps_limit', 0);

        if ($rpsLimit < 0) {
            throw new IncompleteCloudWatchConfig('CloudWatch rps_limit may not be negative.');
        }

        return $rpsLimit;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function cacheTtl(array $config): int
    {
        $ttl = self::intValue($config, 'cache_ttl', self::DEFAULT_CACHE_TTL);

        if ($ttl < 1) {
            throw new IncompleteCloudWatchConfig('CloudWatch cache_ttl must be a positive number of seconds.');
        }

        return $ttl;
    }

    /**
     * Log group tags must be a flat string map; CloudWatch rejects anything else.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function tags(array $config): array
    {
        $tags = $config['tags'] ?? [];

        if (! is_array($tags)) {
            throw new IncompleteCloudWatchConfig('Tags must be an array in CloudWatch configuration.');
        }

        $validated = [];

        foreach ($tags as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new IncompleteCloudWatchConfig('CloudWatch log group tags must be a map of string keys to string values.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * Coerce a raw config value into a Monolog Level.
     *
     * Delegates to Monolog so every spelling Laravel's other channels accept
     * (a Level, an int/numeric-string severity, or a level name in any case)
     * behaves identically here.
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function level(mixed $value): Level
    {
        if ($value instanceof Level) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\\d+$/', trim($value)) === 1)) {
            $level = Level::tryFrom((int) $value);

            if ($level === null) {
                throw new IncompleteCloudWatchConfig(
                    "Invalid log level '$value' in CloudWatch configuration. Use one of: ".implode(', ', Level::VALUES).'.'
                );
            }

            return $level;
        }

        if (is_string($value)) {
            $level = match (strtolower(trim($value))) {
                'debug' => Level::Debug,
                'info' => Level::Info,
                'notice' => Level::Notice,
                'warning', 'warn' => Level::Warning,
                'error', 'err' => Level::Error,
                'critical', 'crit' => Level::Critical,
                'alert' => Level::Alert,
                'emergency', 'emerg', 'panic' => Level::Emergency,
                default => null,
            };

            if ($level === null) {
                throw new IncompleteCloudWatchConfig(
                    "Invalid log level '$value' in CloudWatch configuration. Use one of: ".implode(', ', Level::NAMES).'.'
                );
            }

            return $level;
        }

        throw new IncompleteCloudWatchConfig('Invalid log level in CloudWatch configuration.');
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($filtered === null) {
            throw new IncompleteCloudWatchConfig("CloudWatch configuration value '$key' must be a boolean.");
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function intValue(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new IncompleteCloudWatchConfig("CloudWatch configuration value '$key' must be an integer.");
    }

    /**
     * Fetch a required, non-empty string configuration value.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function requiredString(array $config, string $key, string $description): string
    {
        $value = $config[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new IncompleteCloudWatchConfig("Missing or invalid $description in CloudWatch configuration.");
        }

        return $value;
    }
}
