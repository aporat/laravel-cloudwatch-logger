<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchConfig;
use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aporat\CloudWatchLogger\Tests\Fixtures\CustomCloudWatchHandler;
use Aporat\CloudWatchLogger\Tests\Fixtures\SneakyCloudWatchFactory;
use Aporat\CloudWatchLogger\Tests\Support\FakeCloudWatchLogs;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * formatter_with, handler/handler_with, stream placeholders and
 * fallback_channel, through Laravel's log manager.
 */
#[CoversClass(CloudWatchLoggerFactory::class)]
#[CoversClass(CloudWatchConfig::class)]
#[CoversClass(CloudWatchHandler::class)]
final class ChannelOptionsTest extends TestCase
{
    private FakeCloudWatchLogs $aws;

    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->aws = new FakeCloudWatchLogs;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== '') {
            @unlink($this->errorLog);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [CloudWatchLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'staging');
        $app['config']->set('logging.channels.cloudwatch.aws.handler', $this->aws);
        $app['config']->set('logging.channels.cloudwatch.aws.retries', 0);
        $app['config']->set('logging.channels.cloudwatch.aws.credentials', ['key' => 'AKIDTEST', 'secret' => 'secret']);
        $app['config']->set('logging.channels.cloudwatch.level', 'debug');
        $app['config']->set('logging.channels.cloudwatch.group', 'app');
        $app['config']->set('logging.channels.cloudwatch.formatter', '%message%');
    }

    #[Test]
    public function formatter_with_passes_named_constructor_arguments_like_laravel(): void
    {
        $this->channel([
            'formatter' => LineFormatter::class,
            'formatter_with' => ['format' => '[%level_name%] %message%', 'ignoreEmptyContextAndExtra' => true],
        ])->warning('careful');

        $this->assertSame(['[WARNING] careful'], $this->deliver());
    }

    #[Test]
    public function formatter_with_accepts_a_positional_list(): void
    {
        $this->channel([
            'formatter' => LineFormatter::class,
            'formatter_with' => ['<%message%>', null, false, true],
        ])->info('hi');

        $this->assertSame(['<hi>'], $this->deliver());
    }

    #[Test]
    public function formatter_with_configures_a_json_formatter(): void
    {
        $this->channel([
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['appendNewline' => false, 'includeStacktraces' => true],
        ])->info('json');

        $decoded = json_decode($this->deliver()[0], true);
        $this->assertSame('json', $decoded['message']);
    }

    #[Test]
    public function the_default_keyword_selects_the_default_formatter(): void
    {
        $logger = $this->channel(['formatter' => 'default']);

        $this->assertInstanceOf(LineFormatter::class, $this->handler($logger)->getFormatter());
    }

    #[Test]
    public function formatter_with_without_a_formatter_class_is_a_config_error(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessage("'formatter_with' requires 'formatter' to be a");

        $this->factory(['formatter' => '%message%', 'formatter_with' => ['x' => 1]]);
    }

    #[Test]
    public function bad_formatter_with_arguments_are_a_config_error(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessage('Unable to build the CloudWatch formatter');

        $this->factory(['formatter' => LineFormatter::class, 'formatter_with' => ['format' => ['not a string']]]);
    }

    #[Test]
    public function handler_with_overrides_the_computed_handler_arguments(): void
    {
        $logger = $this->channel(['batch_size' => 100, 'handler_with' => ['batchSize' => 1, 'group' => 'override']]);

        $logger->info('now');

        $this->assertSame('override', $this->handler($logger)->getGroup());
        $this->assertSame(['now'], $this->aws->delivered(), 'batchSize 1 sends immediately.');
    }

    #[Test]
    public function a_handler_subclass_is_built_through_the_container(): void
    {
        $logger = $this->channel(['handler' => CustomCloudWatchHandler::class, 'handler_with' => ['level' => Level::Critical]]);

        $handler = $this->handler($logger);
        $this->assertInstanceOf(CustomCloudWatchHandler::class, $handler);
        $this->assertFalse($handler->isHandling(new LogRecord(new \DateTimeImmutable, 't', Level::Error, 'x')));
    }

    #[Test]
    public function the_handler_option_must_be_a_cloudwatch_handler(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        $this->factory(['handler' => TestHandler::class]);
    }

    #[Test]
    public function unknown_handler_with_arguments_are_a_config_error(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessage('Unable to build the CloudWatch log handler');

        $this->factory(['handler_with' => ['noSuchArgument' => 1]]);
    }

    #[Test]
    public function the_env_placeholder_uses_the_application_environment(): void
    {
        $logger = $this->channel(['stream' => '{env}-{hostname}-{pid}']);

        $this->assertSame('staging-'.gethostname().'-'.getmypid(), $this->handler($logger)->getStream());
    }

    #[Test]
    public function the_date_placeholder_uses_the_configured_format_in_utc(): void
    {
        $logger = $this->channel(['stream' => 'app-{date}', 'stream_date_format' => 'Y/m']);

        $this->assertSame('app-'.gmdate('Y/m'), $this->handler($logger)->getStream());
    }

    #[Test]
    public function the_channel_config_stays_cacheable(): void
    {
        // `php artisan config:cache` var_exports the config: only scalars and arrays survive.
        $this->app['config']->set('logging.channels.cloudwatch.formatter', LineFormatter::class);
        $this->app['config']->set('logging.channels.cloudwatch.formatter_with', ['format' => '%message%']);
        $this->app['config']->set('logging.channels.cloudwatch.handler_with', ['batchSize' => 5]);
        $this->app['config']->set('logging.channels.cloudwatch.fallback_channel', 'single');
        $config = $this->app['config']->get('logging.channels.cloudwatch');
        unset($config['aws']['handler']); // test-only object

        $restored = eval('return '.var_export($config, true).';');

        $this->assertSame($config, $restored);
        $restored['aws']['handler'] = $this->aws;
        $this->assertInstanceOf(Logger::class, $this->app->make(CloudWatchLoggerFactory::class)($restored));
    }

    #[Test]
    public function undeliverable_records_reach_the_fallback_channel_with_their_context(): void
    {
        $this->app['config']->set('logging.channels.spy', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $spy = Log::channel('spy')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $spy);
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'cwl');
        ini_set('error_log', $this->errorLog);

        $logger = $this->channel(['fallback_channel' => 'spy', 'batch_size' => 1, 'max_buffer_size' => 1]);
        $logger->error('first', ['order' => 7]);  // send fails, kept, circuit opens
        $logger->error('second');                // overflow: 'first' goes to the fallback

        $this->assertCount(1, $spy->getRecords());
        $record = $spy->getRecords()[0];
        $this->assertSame('first', $record->message);
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame(['order' => 7, 'cloudwatch_fallback' => 'buffer full'], $record->context);

        $logger->getLogger()->close();
        $this->assertSame(['first', 'second'], array_map(fn ($r) => $r->message, $spy->getRecords()));
    }

    #[Test]
    public function a_cloudwatch_fallback_channel_is_refused(): void
    {
        $this->app['config']->set('logging.channels.other', ['driver' => 'custom', 'via' => CloudWatchLoggerFactory::class]);

        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessage("'fallback_channel' 'other' is itself a CloudWatch channel");

        $this->factory(['fallback_channel' => 'other']);
    }

    #[Test]
    public function a_stack_containing_cloudwatch_is_refused_as_fallback(): void
    {
        $this->app['config']->set('logging.channels.files', ['driver' => 'stack', 'channels' => ['single', 'nested']]);
        $this->app['config']->set('logging.channels.nested', ['driver' => 'stack', 'channels' => ['cloudwatch']]);

        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessage("'cloudwatch' is itself a CloudWatch channel");

        $this->factory(['fallback_channel' => 'files']);
    }

    #[Test]
    public function a_fallback_that_resolves_to_a_cloudwatch_handler_is_refused_at_runtime(): void
    {
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'cwl');
        ini_set('error_log', $this->errorLog);
        $this->app['config']->set('logging.channels.sneaky', ['driver' => 'custom', 'via' => SneakyCloudWatchFactory::class]);
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('InvalidParameterException') : null;

        $this->channel(['fallback_channel' => 'sneaky', 'batch_size' => 1])->error('x');

        $this->assertStringContainsString("Fallback channel 'sneaky' writes to CloudWatch; refusing to forward.", (string) file_get_contents($this->errorLog));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function channel(array $options): \Illuminate\Log\Logger
    {
        foreach ($options as $key => $value) {
            $this->app['config']->set("logging.channels.cloudwatch.$key", $value);
        }

        return Log::channel('cloudwatch');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function factory(array $options): Logger
    {
        $config = array_replace($this->app['config']->get('logging.channels.cloudwatch'), $options);

        return $this->app->make(CloudWatchLoggerFactory::class)($config);
    }

    private function handler(\Illuminate\Log\Logger $logger): CloudWatchHandler
    {
        $handler = $logger->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(CloudWatchHandler::class, $handler);

        return $handler;
    }

    /**
     * @return list<string>
     */
    private function deliver(): array
    {
        Log::channel('cloudwatch')->getLogger()->close();

        return $this->aws->delivered();
    }
}
