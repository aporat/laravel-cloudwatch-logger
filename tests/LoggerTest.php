<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Illuminate\Contracts\Foundation\Application;
use Mockery;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use PhpNexus\Cwh\Handler\CloudWatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

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
        $this->assertInstanceOf(JsonFormatter::class, $this->formatterOf($logger));
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
        $formatter = $this->formatterOf($logger);

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
        $formatter = $this->formatterOf($logger);

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

        $this->assertInstanceOf(LineFormatter::class, $this->formatterOf($logger));
    }

    #[Test]
    public function it_accepts_a_formatter_instance(): void
    {
        $formatter = new JsonFormatter;
        $logger = ($this->factory)($this->getBaseConfig(['formatter' => $formatter]));

        $this->assertSame($formatter, $this->formatterOf($logger));
    }

    /**
     * A misspelled formatter class must not be silently accepted as a
     * LineFormatter template — every log line would then be the class name.
     */
    #[Test]
    public function it_rejects_a_formatter_class_that_does_not_exist(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessageMatches('/does not exist or does not implement/');

        ($this->factory)($this->getBaseConfig(['formatter' => 'App\\Logging\\NoSuchFormatter']));
    }

    #[Test]
    public function it_rejects_a_callable_formatter_returning_the_wrong_type(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        ($this->factory)($this->getBaseConfig(['formatter' => fn (array $config) => 'not a formatter']));
    }

    #[Test]
    public function it_throws_exception_when_required_config_is_missing(): void
    {
        $config = $this->getBaseConfig();
        unset($config['stream']);

        $this->expectException(IncompleteCloudWatchConfig::class);
        ($this->factory)($config);
    }

    #[Test]
    public function it_passes_the_handler_options_through(): void
    {
        $logger = ($this->factory)($this->getBaseConfig([
            'retention' => 30,
            'batch_size' => 25,
            'rps_limit' => 5,
            'bubble' => false,
            'create_group' => false,
            'create_stream' => true,
            'tags' => ['Team' => 'platform'],
        ]));

        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(CloudWatch::class, $handler);

        $this->assertSame(30, $this->handlerProperty($handler, 'retention'));
        $this->assertSame(25, $this->handlerProperty($handler, 'batchSize'));
        $this->assertSame(5, $this->handlerProperty($handler, 'rpsLimit'));
        $this->assertFalse($this->handlerProperty($handler, 'bubble'));
        $this->assertFalse($this->handlerProperty($handler, 'createGroup'));
        $this->assertTrue($this->handlerProperty($handler, 'createStream'));
        $this->assertSame(['Team' => 'platform'], $this->handlerProperty($handler, 'tags'));
        $this->assertSame(Level::Error, $this->handlerProperty($handler, 'level'));
    }

    #[Test]
    public function a_null_retention_reaches_the_handler_as_null(): void
    {
        $logger = ($this->factory)($this->getBaseConfig(['retention' => null]));

        $this->assertNull($this->handlerProperty($logger->getHandlers()[0], 'retention'));
    }

    /**
     * With suppress_failures on, an unreachable CloudWatch must drop the record
     * rather than throw out of the caller's Log:: call — otherwise a request
     * path that logs its own errors turns a handled failure into a 500.
     */
    #[Test]
    public function it_wraps_the_handler_so_failures_do_not_escape(): void
    {
        $logger = ($this->factory)($this->unreachableConfig(['suppress_failures' => true]));

        $this->assertInstanceOf(WhatFailureGroupHandler::class, $logger->getHandlers()[0]);

        $logger->error('boom');

        $this->assertTrue(true, 'A failing CloudWatch write must not throw.');
    }

    #[Test]
    public function a_failing_write_throws_when_failures_are_not_suppressed(): void
    {
        $logger = ($this->factory)($this->unreachableConfig());

        $this->expectException(Throwable::class);

        $logger->error('boom');
    }

    #[Test]
    public function it_does_not_wrap_the_handler_by_default(): void
    {
        $logger = ($this->factory)($this->getBaseConfig());

        $this->assertInstanceOf(CloudWatch::class, $logger->getHandlers()[0]);
    }

    #[Test]
    public function it_adds_the_placeholder_processor_on_request(): void
    {
        $this->assertSame([], ($this->factory)($this->getBaseConfig())->getProcessors());

        $processors = ($this->factory)($this->getBaseConfig(['replace_placeholders' => true]))->getProcessors();

        $this->assertCount(1, $processors);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $processors[0]);
    }

    #[Test]
    public function it_names_the_logger_after_the_name_option(): void
    {
        $this->assertSame('CLOUDWATCH_LOG_NAME', ($this->factory)($this->getBaseConfig())->getName());
    }

    #[Test]
    public function it_works_without_a_container(): void
    {
        $logger = (new CloudWatchLoggerFactory)($this->getBaseConfig(['formatter' => JsonFormatter::class]));

        $this->assertInstanceOf(JsonFormatter::class, $this->formatterOf($logger));
    }

    #[Test]
    public function it_rejects_a_cache_value_that_is_not_a_psr6_pool(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessageMatches('/is not a PSR-6/');

        (new CloudWatchLoggerFactory)($this->getBaseConfig(['cache' => \stdClass::class]));
    }

    /**
     * A channel pointed at a closed local port: every AWS call fails
     * immediately, with no network access and no retry back-off. batch_size 1
     * makes the very first record attempt a PutLogEvents call.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unreachableConfig(array $overrides = []): array
    {
        return $this->getBaseConfig(array_merge([
            'aws' => [
                'region' => 'us-east-1',
                'version' => 'latest',
                'endpoint' => 'http://127.0.0.1:1',
                'retries' => 0,
                'credentials' => ['key' => 'key', 'secret' => 'secret'],
            ],
            'level' => Level::Debug,
            'batch_size' => 1,
        ], $overrides));
    }

    private function formatterOf(Logger $logger): FormatterInterface
    {
        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(CloudWatch::class, $handler);

        return $handler->getFormatter();
    }

    private function handlerProperty(HandlerInterface $handler, string $property): mixed
    {
        return (new ReflectionProperty($handler, $property))->getValue($handler);
    }

    /**
     * Generate a base CloudWatch configuration with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
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
