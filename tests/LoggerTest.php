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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CloudWatchLoggerFactory::class)]
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_creates_logger_with_json_formatter(): void
    {
        $config = $this->getBaseConfig(['formatter' => JsonFormatter::class]);
        $this->app->shouldReceive('make')
            ->once()
            ->with(JsonFormatter::class)
            ->andReturn(new JsonFormatter);

        $logger = ($this->factory)($config);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertCount(1, $logger->getHandlers());
        $this->assertInstanceOf(JsonFormatter::class, $logger->getHandlers()[0]->getFormatter());
    }

    #[Test]
    public function it_creates_logger_with_line_formatter_callable(): void
    {
        $config = $this->getBaseConfig([
            'formatter' => fn (array $configs) => new LineFormatter(
                format: '%channel%: %level_name%: %message% %context% %extra%',
                allowInlineLineBreaks: false,
                ignoreEmptyContextAndExtra: true
            ),
        ]);

        $logger = ($this->factory)($config);
        $formatter = $logger->getHandlers()[0]->getFormatter();

        $this->assertCount(1, $logger->getHandlers());
        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test-channel',
            level: Level::Error,
            message: 'Test log message',
            context: ['user_id' => 123],
            extra: ['key' => 'value']
        );
        $formatted = $formatter->format($record);

        $this->assertEquals('test-channel: ERROR: Test log message {"user_id":123} {"key":"value"}', $formatted);
    }

    #[Test]
    public function it_creates_logger_with_format_string(): void
    {
        $format = '[%datetime%] %channel%.%level_name%: %message% %context% %extra%';
        $config = $this->getBaseConfig(['formatter' => $format]);

        $logger = ($this->factory)($config);
        $formatter = $logger->getHandlers()[0]->getFormatter();

        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2025-09-18T12:00:00Z'),
            channel: 'test-channel',
            level: Level::Warning,
            message: 'This is a test log',
            context: ['user_id' => 1],
        );
        $output = $formatter->format($record);

        $this->assertStringContainsString('test-channel.WARNING: This is a test log {"user_id":1}', $output);
    }

    #[Test]
    public function it_creates_logger_with_default_line_formatter(): void
    {
        $config = $this->getBaseConfig();
        $logger = ($this->factory)($config);

        $this->assertInstanceOf(LineFormatter::class, $logger->getHandlers()[0]->getFormatter());
    }

    #[Test]
    public function it_throws_exception_when_required_config_is_missing(): void
    {
        $config = $this->getBaseConfig();
        unset($config['stream']);

        $this->expectException(IncompleteCloudWatchConfig::class);
        ($this->factory)($config);
    }

    /**
     * Generate a base CloudWatch configuration with optional overrides.
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
}
