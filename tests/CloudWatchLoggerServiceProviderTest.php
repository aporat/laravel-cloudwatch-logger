<?php

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CloudWatchLoggerServiceProvider::class)]
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
        $this->assertEquals('custom', $config['cloudwatch']['driver']);
        $this->assertEquals(CloudWatchLoggerFactory::class, $config['cloudwatch']['via']);
        $this->assertEquals('us-east-1', $config['cloudwatch']['aws']['region']);
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
}
