<?php

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Illuminate\Contracts\Foundation\Application;
use Mockery;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testLoggerConfig(): void
    {
        $cloudwatch_config = [
            'driver'     => 'custom',
            'via'        => CloudWatchLoggerFactory::class,
            'aws'        => [
                'region'      => 'us-east-1',
                'version'     => 'latest',
                'credentials' => [
                    'key'    => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                ],
            ],
            'name'       => 'CLOUDWATCH_LOG_NAME',
            'group'      => 'CLOUDWATCH_LOG_GROUP_NAME',
            'stream'     => 'CLOUDWATCH_LOG_STREAM',
            'retention'  => 7,
            'level'      => Level::Error,
            'formatter'  => JsonFormatter::class,
        ];

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')
            ->once()
            ->with(JsonFormatter::class)
            ->andReturn(Mockery::mock(JsonFormatter::class));

        $logger_factory = new CloudWatchLoggerFactory($app);
        $logger = $logger_factory($cloudwatch_config);

        $this->assertInstanceOf(CloudWatchLoggerFactory::class, $logger_factory);
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertNotEmpty($logger->getHandlers());
        $this->assertInstanceOf(JsonFormatter::class, $logger->getHandlers()[0]->getFormatter());
    }

    public function testInvalidFormatterWillThrowException(): void
    {
        $cloudwatch_config = [
            'driver'     => 'custom',
            'via'        => CloudWatchLoggerFactory::class,
            'aws'        => [
                'region'      => 'us-east-1',
                'version'     => 'latest',
                'credentials' => [
                    'key'    => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                ],
            ],
            'name'       => 'CLOUDWATCH_LOG_NAME',
            'group'      => 'CLOUDWATCH_LOG_GROUP_NAME',
            'stream'     => 'CLOUDWATCH_LOG_STREAM',
            'retention'  => 7,
            'level'      => Level::Error,
            'formatter'  => 'InvalidFormatter',
        ];

        $app = Mockery::mock(Application::class);

        $logger_factory = new CloudWatchLoggerFactory($app);

        $this->expectException(IncompleteCloudWatchConfig::class);
        $logger_factory($cloudwatch_config);
    }

    public function testLineFormatterConfig(): void
    {
        $cloudwatch_config = [
            'driver'     => 'custom',
            'via'        => CloudWatchLoggerFactory::class,
            'aws'        => [
                'region'      => 'us-east-1',
                'version'     => 'latest',
                'credentials' => [
                    'key'    => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                ],
            ],
            'group'      => 'myapp-testing',
            'stream'     => 'default',
            'name'       => '',
            'retention'  => 7,
            'level'      => Level::Error,
            'formatter'  => function ($configs) {
                return new LineFormatter(
                    '%channel%: %level_name%: %message% %context% %extra%',
                    null,
                    false,
                    true
                );
            },
        ];

        $app = Mockery::mock(Application::class);

        $logger_factory = new CloudWatchLoggerFactory($app);
        $logger = $logger_factory($cloudwatch_config);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertNotEmpty($logger->getHandlers());
        $formatter = $logger->getHandlers()[0]->getFormatter();
        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $record = new LogRecord(
            new \DateTimeImmutable('2025-02-21 10:00:00'),
            '',
            Level::Error,
            'Test log message',
            ['user_id' => 123],
            ['key'     => 'value']
        );
        $formatted = $formatter->format($record);

        $expected = ': ERROR: Test log message {"user_id":123} {"key":"value"}';
        $this->assertEquals($expected, $formatted);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
