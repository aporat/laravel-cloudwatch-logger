<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Fixtures;

use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\MockHandler;
use Monolog\Logger;

/**
 * A custom channel that writes to CloudWatch without using the package
 * factory, so only the runtime fallback guard can catch it.
 */
final class SneakyCloudWatchFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $client = new CloudWatchLogsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIDTEST', 'secret' => 'secret'],
            'handler' => new MockHandler,
        ]);

        return new Logger('sneaky', [new CloudWatchHandler($client, 'sneaky', 'sneaky')]);
    }
}
