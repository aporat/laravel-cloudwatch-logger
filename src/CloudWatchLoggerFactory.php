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
            is_string($formatterConfig) && is_subclass_of($formatterConfig, FormatterInterface::class) => $this->resolveFormatterClass($formatterConfig),
            is_string($formatterConfig) => new LineFormatter($formatterConfig, null, false, true),
            is_callable($formatterConfig) => $formatterConfig($config->originalConfig),
            default => throw new IncompleteCloudWatchConfig('Invalid formatter configuration for CloudWatch logs.'),
        };
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
        return new self(
            aws: self::validate($config, 'aws', 'AWS credentials'),
            group: self::validate($config, 'group', 'log group name'),
            stream: self::validate($config, 'stream', 'log stream name'),
            name: self::validate($config, 'name', 'logger name'),
            retention: $config['retention'] ?? CloudWatchLoggerFactory::DEFAULT_RETENTION_DAYS,
            batchSize: $config['batch_size'] ?? CloudWatchLoggerFactory::DEFAULT_BATCH_SIZE,
            tags: $config['tags'] ?? [],
            level: $config['level'] ?? Level::Debug,
            formatter: $config['formatter'] ?? null,
            originalConfig: $config
        );
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
        if (empty($config[$key])) {
            throw new IncompleteCloudWatchConfig("Missing or invalid $description in CloudWatch configuration.");
        }

        return $config[$key];
    }
}
