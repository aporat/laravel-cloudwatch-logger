<?php

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Mockery;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testLoggerConfig(): void
    {
        $cloudwatch_config = [
            'driver' => 'custom',
            'via'    => CloudWatchLoggerFactory::class,
            'aws'    => [
                'region'      => 'us-east-1',
                'version'     => 'latest',
                'credentials' => [
                    'key'    => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                ],
            ],
            'name'      => 'CLOUDWATCH_LOG_NAME',
            'group'     => 'CLOUDWATCH_LOG_GROUP_NAME',
            'stream'    => 'CLOUDWATCH_LOG_STREAM',
            'retention' => 7,
            'level'     => Level::Error,
            'formatter' => JsonFormatter::class,
        ];

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('logging.channels')
            ->andReturn([
                'cloudwatch' => $cloudwatch_config,
            ]);

        $config->shouldReceive('get')
            ->once()
            ->with('logging.channels.cloudwatch')
            ->andReturn($cloudwatch_config);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')
            ->once()
            ->with('config')
            ->andReturn($config);

        $formatter = Mockery::mock(JsonFormatter::class);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')
            ->once()
            ->with('config')
            ->andReturn($config);
        $app->shouldReceive('make')
            ->once()
            ->with(JsonFormatter::class)
            ->andReturn($formatter);

        $logger_factory = new CloudWatchLoggerFactory($app);
        $logger = $logger_factory($cloudwatch_config);

        $this->assertInstanceOf(CloudWatchLoggerFactory::class, $logger_factory);
        $this->assertInstanceOf(Logger::class, $logger);

        $this->assertNotEmpty($logger->getHandlers());
        $this->assertInstanceOf(
            JsonFormatter::class,
            $logger->getHandlers()[0]->getFormatter()
        );
    }

    public function testInvalidFormatterWillThrowException()
    {
        $cloudwatch_config = [
            'driver' => 'custom',
            'via'    => CloudWatchLoggerFactory::class,
            'aws'    => [
                'region'      => 'us-east-1',
                'version'     => 'latest',
                'credentials' => [
                    'key'    => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                ],
            ],
            'name'      => 'CLOUDWATCH_LOG_NAME',
            'group'     => 'CLOUDWATCH_LOG_GROUP_NAME',
            'stream'    => 'CLOUDWATCH_LOG_STREAM',
            'retention' => 7,
            'level'     => Level::Error,
            'formatter' => 'InvalidFormatter',
        ];

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('logging.channels')
            ->andReturn([
                'cloudwatch' => $cloudwatch_config,
            ]);

        $config->shouldReceive('get')
            ->once()
            ->with('logging.channels.cloudwatch')
            ->andReturn($cloudwatch_config);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')
            ->once()
            ->with('config')
            ->andReturn($config);

        $this->expectException(IncompleteCloudWatchConfig::class);
        $logger_factory = new CloudWatchLoggerFactory($app);
        $logger = $logger_factory($cloudwatch_config);
    }
}
