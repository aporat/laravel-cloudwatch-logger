<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\Cache\LaravelCacheItemPool;
use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Orchestra\Testbench\TestCase;
use PhpNexus\Cwh\Handler\CloudWatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

#[CoversClass(CloudWatchLoggerServiceProvider::class)]
#[CoversClass(CloudWatchLoggerFactory::class)]
class CloudWatchLoggerServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [CloudWatchLoggerServiceProvider::class];
    }

    #[Test]
    public function it_registers_the_factory_as_a_singleton(): void
    {
        $this->assertTrue($this->app->bound(CloudWatchLoggerFactory::class));

        $factory1 = $this->app->make(CloudWatchLoggerFactory::class);
        $factory2 = $this->app->make(CloudWatchLoggerFactory::class);

        $this->assertInstanceOf(CloudWatchLoggerFactory::class, $factory1);
        $this->assertSame($factory1, $factory2, 'The CloudWatchLoggerFactory should be registered as a singleton.');
    }

    #[Test]
    public function it_merges_the_configuration_correctly(): void
    {
        $config = $this->app['config']->get('cloudwatch-logger');

        $this->assertIsArray($config);
        $this->assertNotEmpty($config, 'Configuration should not be empty after merging.');
        $this->assertEquals('custom', $config['driver']);
        $this->assertEquals(CloudWatchLoggerFactory::class, $config['via']);
        $this->assertEquals('us-east-1', $config['aws']['region']);
    }

    /**
     * The package configuration is a complete channel definition, so it should
     * be usable straight after installation without editing config/logging.php.
     */
    #[Test]
    public function it_registers_a_ready_to_use_cloudwatch_channel(): void
    {
        $this->assertSame(
            $this->app['config']->get('cloudwatch-logger'),
            $this->app['config']->get('logging.channels.cloudwatch')
        );

        $logger = Log::channel('cloudwatch')->getLogger();

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertInstanceOf(CloudWatch::class, $logger->getHandlers()[0]);
    }

    /**
     * An application that defines its own cloudwatch channel keeps it.
     */
    #[Test]
    public function it_leaves_an_existing_cloudwatch_channel_alone(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch', ['driver' => 'single', 'path' => '/dev/null']);

        (new CloudWatchLoggerServiceProvider($this->app))->register();

        $this->assertSame('single', $this->app['config']->get('logging.channels.cloudwatch.driver'));
    }

    #[Test]
    public function it_resolves_the_application_cache_as_a_psr6_pool(): void
    {
        $this->app['config']->set('logging.channels.cw-cached', $this->channelConfig(['cache' => true]));

        $handler = Log::channel('cw-cached')->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(
            LaravelCacheItemPool::class,
            (new ReflectionProperty($handler, 'cacheItemPool'))->getValue($handler)
        );
    }

    #[Test]
    public function it_resolves_a_named_cache_store(): void
    {
        $this->app['config']->set('logging.channels.cw-array', $this->channelConfig(['cache' => 'array']));

        $handler = Log::channel('cw-array')->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(
            LaravelCacheItemPool::class,
            (new ReflectionProperty($handler, 'cacheItemPool'))->getValue($handler)
        );
    }

    /**
     * The handler rejects a pool it could never populate, so the factory has to
     * drop the pool rather than hand it over and blow up.
     */
    #[Test]
    public function it_drops_the_cache_when_nothing_would_ever_be_created(): void
    {
        $this->app['config']->set('logging.channels.cw-nocreate', $this->channelConfig([
            'cache' => true,
            'create_group' => false,
            'create_stream' => false,
        ]));

        $handler = Log::channel('cw-nocreate')->getLogger()->getHandlers()[0];

        $this->assertNull((new ReflectionProperty($handler, 'cacheItemPool'))->getValue($handler));
    }

    #[Test]
    public function an_invalid_channel_fails_with_the_package_exception(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        $this->app->make(CloudWatchLoggerFactory::class)($this->channelConfig(['group' => 'not valid!']));
    }

    /**
     * Laravel's LogManager swallows anything a channel factory throws and hands
     * back its emergency file logger instead. A misconfigured channel therefore
     * never surfaces as an exception at the call site — it quietly stops
     * reaching CloudWatch. This pins that behaviour so the package's
     * fail-fast validation is understood for what it is: a precise message in
     * the emergency log, not a hard failure.
     */
    #[Test]
    public function laravel_falls_back_to_the_emergency_logger_for_a_broken_channel(): void
    {
        $this->app['config']->set('logging.channels.cw-broken', $this->channelConfig(['group' => 'not valid!']));

        $handler = Log::channel('cw-broken')->getLogger()->getHandlers()[0];

        $this->assertNotInstanceOf(CloudWatch::class, $handler);
    }

    #[Test]
    public function it_publishes_the_configuration_file(): void
    {
        $sourcePath = realpath(__DIR__.'/../config/cloudwatch-logger.php');
        $targetPath = $this->app->configPath('cloudwatch-logger.php');

        $this->artisan('vendor:publish', [
            '--provider' => CloudWatchLoggerServiceProvider::class,
            '--tag' => 'config',
        ]);

        $this->assertFileExists($targetPath, 'The config file should be published to the application config directory.');
        $this->assertFileEquals($sourcePath, $targetPath, 'The published config file should match the source file.');

        // Cleanup
        @unlink($targetPath);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function channelConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'custom',
            'via' => CloudWatchLoggerFactory::class,
            'aws' => ['region' => 'us-east-1', 'version' => 'latest'],
            'group' => 'test-group',
            'stream' => 'test-stream',
            'name' => 'test',
        ], $overrides);
    }
}
