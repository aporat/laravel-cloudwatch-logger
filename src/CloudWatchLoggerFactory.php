<?php

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch;

use function is_callable;
use function is_string;

class CloudWatchLoggerFactory
{
    private Application $app;

    public function __construct(Application $app = null)
    {
        $this->app = $app;
    }

    /**
     * @param array $config
     *
     * @throws Exception
     *
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $aws = $config['aws'];
        $tags = $config['tags'] ?? [];
        $name = $config['name'];

        // AWS SDK Cloudwatch Logs Client
        $client = new CloudWatchLogsClient($aws);

        $groupName = $config['group'];
        $streamName = $config['stream'];
        $retentionDays = $config['retention'];

        $batchSize = $config['batch_size'] ?? 10000;
        $level = $config['level'] ?? Level::Debug;

        $handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, $batchSize, $tags, $level);

        $logger = new Logger($name);

        $formatter = $this->resolveFormatter($config);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * @param array $configs
     *
     * @throws IncompleteCloudWatchConfig
     *
     * @return FormatterInterface
     */
    private function resolveFormatter(array $configs): FormatterInterface
    {
        if (!isset($configs['formatter'])) {
            return new LineFormatter(
                '%channel%: %level_name%: %message% %context% %extra%',
                null,
                false,
                true
            );
        }

        $formatter = $configs['formatter'];

        if (is_string($formatter) && class_exists($formatter)) {
            return $this->app->make($formatter);
        }

        if (is_callable($formatter)) {
            return $formatter($configs);
        }

        throw new IncompleteCloudWatchConfig('Formatter is missing for the logs');
    }
}
