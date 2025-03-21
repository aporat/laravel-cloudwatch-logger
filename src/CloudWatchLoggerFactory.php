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
 * Configures a Monolog logger to send logs to AWS CloudWatch Logs using provided
 * configuration settings for credentials, log group, stream, and formatting.
 */
final class CloudWatchLoggerFactory
{
    /**
     * The Laravel container instance for dependency resolution.
     */
    private ?Container $container;

    /**
     * Create a new CloudWatch logger factory instance.
     *
     * @param Container|null $container Laravel container instance (optional, defaults to null)
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Create a configured CloudWatch logger instance.
     *
     * @param array<string, mixed> $config Configuration array for CloudWatch logging
     *
     * @throws IncompleteCloudWatchConfig If required config is missing or invalid
     *
     * @return Logger Configured Monolog logger instance
     */
    public function __invoke(array $config): Logger
    {
        $client = new CloudWatchLogsClient($this->validateConfig($config, 'aws', 'AWS credentials'));
        $handler = new CloudWatch(
            $client,
            $this->validateConfig($config, 'group', 'log group name'),
            $this->validateConfig($config, 'stream', 'log stream name'),
            $config['retention'] ?? 14, // Default retention: 14 days
            $config['batch_size'] ?? 10000,
            $config['tags'] ?? [],
            $config['level'] ?? Level::Debug
        );

        $logger = new Logger($this->validateConfig($config, 'name', 'logger name'));
        $handler->setFormatter($this->resolveFormatter($config));
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Resolve the formatter for CloudWatch logs based on configuration.
     *
     * @param array<string, mixed> $config Configuration array with optional formatter settings
     *
     * @throws IncompleteCloudWatchConfig If formatter configuration is invalid
     *
     * @return FormatterInterface Formatter instance for Monolog
     */
    private function resolveFormatter(array $config): FormatterInterface
    {
        if (!isset($config['formatter'])) {
            return new LineFormatter(
                '%channel%: %level_name%: %message% %context% %extra%',
                null,
                false,
                true
            );
        }

        $formatter = $config['formatter'];

        if (is_string($formatter) && class_exists($formatter)) {
            if (!$this->container) {
                return new $formatter();
            }

            return $this->container->make($formatter);
        }

        if (is_callable($formatter)) {
            return $formatter($config);
        }

        throw new IncompleteCloudWatchConfig('Invalid formatter configuration for CloudWatch logs');
    }

    /**
     * Validate and retrieve a required configuration value.
     *
     * @param array<string, mixed> $config      Configuration array
     * @param string               $key         Config key to retrieve
     * @param string               $description Description of the key for error messaging
     *
     * @throws IncompleteCloudWatchConfig If the key is missing or empty
     *
     * @return mixed Config value
     */
    private function validateConfig(array $config, string $key, string $description): mixed
    {
        if (empty($config[$key])) {
            throw new IncompleteCloudWatchConfig("Missing or invalid $description in CloudWatch configuration");
        }

        return $config[$key];
    }
}
