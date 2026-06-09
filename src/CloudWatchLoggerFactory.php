<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Contracts\Container\Container;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch;

/**
 * Factory for creating CloudWatch-integrated Monolog logger instances.
 *
 * This factory is responsible for parsing a configuration array, constructing
 * the necessary AWS and Monolog components, and returning a fully configured
 * logger instance ready to send logs to AWS CloudWatch.
 */
final class CloudWatchLoggerFactory
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
     * Create a new CloudWatch logger factory instance.
     *
     * @param  Container|null  $container  The Laravel container for dependency resolution.
     */
    public function __construct(private readonly ?Container $container = null) {}

    /**
     * Create a configured CloudWatch logger instance.
     *
     * @param  array<string, mixed>  $config  Configuration array for CloudWatch logging.
     * @return Logger Configured Monolog logger instance.
     *
     * @throws IncompleteCloudWatchConfig If the required config is missing or invalid.
     */
    public function __invoke(array $config): Logger
    {
        $configDto = CloudWatchConfig::fromArray($config);

        $client = $this->createClient($configDto);
        $handler = $this->createHandler($client, $configDto);
        $logger = $this->createLogger($configDto);

        $handler->setFormatter($this->resolveFormatter($configDto));
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Creates the AWS CloudWatch Logs client.
     */
    private function createClient(CloudWatchConfig $config): CloudWatchLogsClient
    {
        return new CloudWatchLogsClient($config->aws);
    }

    /**
     * Creates the Monolog CloudWatch handler.
     */
    private function createHandler(CloudWatchLogsClient $client, CloudWatchConfig $config): CloudWatch
    {
        return new CloudWatch(
            client: $client,
            group: $config->group,
            stream: $config->stream,
            retention: $config->retention,
            batchSize: $config->batchSize,
            tags: $config->tags,
            level: $config->level
        );
    }

    /**
     * Creates the base Monolog Logger instance.
     */
    private function createLogger(CloudWatchConfig $config): Logger
    {
        return new Logger($config->name);
    }

    /**
     * Resolve the formatter for CloudWatch logs based on configuration.
     *
     * @throws IncompleteCloudWatchConfig If formatter configuration is invalid.
     */
    private function resolveFormatter(CloudWatchConfig $config): FormatterInterface
    {
        $formatterConfig = $config->formatter;

        return match (true) {
            is_null($formatterConfig) => new LineFormatter('%channel%: %level_name%: %message% %context% %extra%', null, false, true),
            $formatterConfig instanceof FormatterInterface => $formatterConfig,
            is_string($formatterConfig) && is_subclass_of($formatterConfig, FormatterInterface::class) => $this->resolveFormatterClass($formatterConfig),
            is_string($formatterConfig) && $this->looksLikeClassName($formatterConfig) => throw new IncompleteCloudWatchConfig(
                "Formatter class '$formatterConfig' does not exist or does not implement ".FormatterInterface::class.'.'
            ),
            is_string($formatterConfig) => new LineFormatter($formatterConfig, null, false, true),
            is_callable($formatterConfig) => $formatterConfig($config->originalConfig),
            default => throw new IncompleteCloudWatchConfig('Invalid formatter configuration for CloudWatch logs.'),
        };
    }

    /**
     * Detect strings that look like a fully-qualified class name (rather than
     * a LineFormatter template) so a typo or missing class surfaces as an
     * explicit error instead of silently becoming a format string.
     */
    private function looksLikeClassName(string $value): bool
    {
        return (bool) preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value);
    }

    /**
     * Instantiates a formatter from a class string.
     *
     * @param  class-string<FormatterInterface>  $formatterClass
     */
    private function resolveFormatterClass(string $formatterClass): FormatterInterface
    {
        if ($this->container) {
            return $this->container->make($formatterClass);
        }

        return new $formatterClass;
    }
}

/**
 * A Data Transfer Object (DTO) for holding and validating CloudWatch configuration.
 *
 * This class centralizes configuration validation and provides type-safe,
 * readonly properties for the factory to consume.
 */
final readonly class CloudWatchConfig
{
    /**
     * @param  array<string, mixed>  $aws
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $originalConfig
     */
    public function __construct(
        public array $aws,
        public string $group,
        public string $stream,
        public string $name,
        public int $retention,
        public int $batchSize,
        public array $tags,
        public Level $level,
        public mixed $formatter,
        public array $originalConfig
    ) {}

    /**
     * Create a new DTO instance from a raw configuration array.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    public static function fromArray(array $config): self
    {
        $tags = $config['tags'] ?? [];
        if (! is_array($tags)) {
            throw new IncompleteCloudWatchConfig('Tags must be an array in CloudWatch configuration.');
        }

        return new self(
            aws: self::validateAws($config),
            group: self::validate($config, 'group', 'log group name'),
            stream: self::validate($config, 'stream', 'log stream name'),
            name: self::validate($config, 'name', 'logger name'),
            retention: (int) ($config['retention'] ?? CloudWatchLoggerFactory::DEFAULT_RETENTION_DAYS),
            batchSize: (int) ($config['batch_size'] ?? CloudWatchLoggerFactory::DEFAULT_BATCH_SIZE),
            tags: $tags,
            level: self::resolveLevel($config['level'] ?? Level::Debug),
            formatter: $config['formatter'] ?? null,
            originalConfig: $config
        );
    }

    /**
     * Coerce a raw config value into a Monolog Level enum.
     *
     * Accepts a Level instance, an int/numeric-string severity (100–600),
     * or a level name ("debug", "ERROR", "Warning"). Anything else throws.
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function resolveLevel(mixed $value): Level
    {
        if ($value instanceof Level) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '+')))) {
            return Level::from((int) $value);
        }

        if (is_string($value)) {
            $name = strtolower(trim($value));
            $map = [
                'debug' => Level::Debug,
                'info' => Level::Info,
                'notice' => Level::Notice,
                'warning' => Level::Warning,
                'error' => Level::Error,
                'critical' => Level::Critical,
                'alert' => Level::Alert,
                'emergency' => Level::Emergency,
            ];
            if (isset($map[$name])) {
                return $map[$name];
            }
        }

        throw new IncompleteCloudWatchConfig('Invalid log level in CloudWatch configuration.');
    }

    /**
     * Validate the AWS configuration sub-array.
     *
     * Ensures the required keys for constructing a CloudWatchLogsClient are
     * present so misconfiguration surfaces as IncompleteCloudWatchConfig
     * rather than an opaque AWS SDK error at first log call.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws IncompleteCloudWatchConfig
     */
    private static function validateAws(array $config): array
    {
        $aws = self::validate($config, 'aws', 'AWS credentials');

        if (! is_array($aws)) {
            throw new IncompleteCloudWatchConfig('AWS configuration must be an array.');
        }

        foreach (['region', 'version'] as $key) {
            if (! isset($aws[$key]) || ! is_string($aws[$key]) || trim($aws[$key]) === '') {
                throw new IncompleteCloudWatchConfig("Missing or invalid AWS '$key' in CloudWatch configuration.");
            }
        }

        $credentials = $aws['credentials'] ?? null;
        if ($credentials !== null) {
            if (! is_array($credentials)) {
                throw new IncompleteCloudWatchConfig("AWS 'credentials' must be an array when provided.");
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
            if ($key === '' && $secret === '') {
                unset($aws['credentials']);
            }
        }

        return $aws;
    }

    /**
     * Validate and retrieve a required configuration value.
     *
     * @param  array<string, mixed>  $config  Configuration array
     *
     * @throws IncompleteCloudWatchConfig If the key is missing or empty.
     */
    private static function validate(array $config, string $key, string $description): mixed
    {
        if (! isset($config[$key]) || (is_string($config[$key]) && trim($config[$key]) === '') || (is_array($config[$key]) && empty($config[$key]))) {
            throw new IncompleteCloudWatchConfig("Missing or invalid $description in CloudWatch configuration.");
        }

        return $config[$key];
    }
}
