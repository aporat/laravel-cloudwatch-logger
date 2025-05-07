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
    private CloudWatchLoggerFactory $factory;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = Mockery::mock(Application::class);
        $this->factory = new CloudWatchLoggerFactory($this->app);
    }

    public function test_creates_logger_with_json_formatter(): void
    {
        $config = $this->getBaseConfig(['formatter' => JsonFormatter::class]);
        $this->app->shouldReceive('make')
            ->once()
            ->with(JsonFormatter::class)
            ->andReturn(Mockery::mock(JsonFormatter::class));

        $logger = $this->factory->__invoke($config); // Explicitly call __invoke

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertCount(1, $logger->getHandlers());
        $this->assertInstanceOf(JsonFormatter::class, $logger->getHandlers()[0]->getFormatter());
    }

    /**
     * @throws \Exception
     */
    public function test_creates_logger_with_line_formatter_callable(): void
    {
        $config = $this->getBaseConfig([
            'group' => 'myapp-testing',
            'stream' => 'default',
            'name' => 'default',
            'formatter' => fn (array $configs) => new LineFormatter(
                '%channel%: %level_name%: %message% %context% %extra%',
                null,
                false,
                true
            ),
        ]);

        $logger = $this->factory->__invoke($config);
        $formatter = $logger->getHandlers()[0]->getFormatter();

        $this->assertCount(1, $logger->getHandlers());
        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $record = new LogRecord(
            new \DateTimeImmutable('2025-02-21 10:00:00'),
            '',
            Level::Error,
            'Test log message',
            ['user_id' => 123],
            ['key' => 'value']
        );
        $formatted = $formatter->format($record);

        $this->assertEquals(': ERROR: Test log message {"user_id":123} {"key":"value"}', $formatted);
    }

    public function test_creates_logger_with_format_string(): void
    {
        $format = '[%datetime%] %channel%.%level_name%: %message% %context% %extra%';

        $config = $this->getBaseConfig([
            'formatter' => $format,
        ]);

        $logger = $this->factory->__invoke($config);
        $formatter = $logger->getHandlers()[0]->getFormatter();

        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $record = new LogRecord(
            new \DateTimeImmutable('2025-05-07T12:00:00Z'),
            'test-channel',
            Level::Warning,
            'This is a test log',
            ['user_id' => 1],
            []
        );

        $output = $formatter->format($record);

        $this->assertStringContainsString('test-channel.WARNING: This is a test log {"user_id":1}', $output);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Generate a base CloudWatch configuration with optional overrides.
     *
     * @param  array<string, mixed>  $overrides  Custom config values to merge
     * @return array<string, mixed> Complete config array
     */
    private function getBaseConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'custom',
            'via' => CloudWatchLoggerFactory::class,
            'aws' => [
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => [
                    'key' => 'AWS_ACCESS_KEY_ID',
                    'secret' => 'AWS_SECRET_ACCESS_KEY',
                ],
            ],
            'name' => 'CLOUDWATCH_LOG_NAME',
            'group' => 'CLOUDWATCH_LOG_GROUP_NAME',
            'stream' => 'CLOUDWATCH_LOG_STREAM',
            'retention' => 7,
            'level' => Level::Error,
        ], $overrides);
    }

    public function test_creates_logger_with_default_line_formatter(): void
    {
        $config = $this->getBaseConfig();
        $logger = $this->factory->__invoke($config);

        $this->assertInstanceOf(LineFormatter::class, $logger->getHandlers()[0]->getFormatter());
    }

    public function test_missing_required_config_throws_exception(): void
    {
        $config = $this->getBaseConfig();
        unset($config['stream']);

        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->factory->__invoke($config);
    }
}
