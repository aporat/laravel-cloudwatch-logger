<?php

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch;

final class CloudWatchLoggerFactory
{
    /**
     * @var Application|null The Laravel application instance
     */
    private ?Application $app;

    /**
     * Create a new CloudWatch logger factory instance.
     *
     * @param Application|null $app The Laravel application instance (optional)
     */
    public function __construct(?Application $app = null)
    {
        $this->app = $app;
    }

    /**
     * Create a CloudWatch logger instance.
     *
     * @param array $config Configuration array for the logger
     *
     * @throws IncompleteCloudWatchConfig If formatter configuration is invalid
     * @throws Exception
     *
     * @return Logger The configured Monolog logger instance
     */
    public function __invoke(array $config): Logger
    {
        $client = new CloudWatchLogsClient($config['aws']);
        $tags = $config['tags'] ?? [];
        $name = $config['name'];
        $groupName = $config['group'];
        $streamName = $config['stream'];
        $retentionDays = $config['retention'];
        $batchSize = $config['batch_size'] ?? 10000;
        $level = $config['level'] ?? Level::Debug;

        $handler = new CloudWatch(
            $client,
            $groupName,
            $streamName,
            $retentionDays,
            $batchSize,
            $tags,
            $level
        );

        $logger = new Logger($name);
        $handler->setFormatter($this->resolveFormatter($config));
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Resolve the formatter for the CloudWatch logger.
     *
     * @param array $config Configuration array containing formatter settings
     *
     * @throws IncompleteCloudWatchConfig If formatter configuration is invalid
     * @throws BindingResolutionException
     *
     * @return FormatterInterface The resolved formatter instance
     */
    private function resolveFormatter(array $config): FormatterInterface
    {
        if (!isset($config['formatter'])) {
            return new LineFormatter(
                format: '%channel%: %level_name%: %message% %context% %extra%',
                dateFormat: null,
                allowInlineLineBreaks: false,
                ignoreEmptyContextAndExtra: true
            );
        }

        $formatter = $config['formatter'];

        if (is_string($formatter) && class_exists($formatter)) {
            return $this->app->make($formatter);
        }

        if (is_callable($formatter)) {
            return $formatter($config);
        }

        throw new IncompleteCloudWatchConfig('Invalid formatter configuration for CloudWatch logs');
    }
}
