<?php

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Aporat\CloudWatchLogger\Laravel\CloudWatchLoggerServiceProvider;
use Orchestra\Testbench\TestCase;

class CloudWatchLoggerServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [CloudWatchLoggerServiceProvider::class];
    }

    public function test_registers_factory_as_singleton(): void
    {
        $this->assertTrue($this->app->bound(CloudWatchLoggerFactory::class));

        $factory1 = $this->app->make(CloudWatchLoggerFactory::class);
        $factory2 = $this->app->make(CloudWatchLoggerFactory::class);

        $this->assertInstanceOf(CloudWatchLoggerFactory::class, $factory1);
        $this->assertSame($factory1, $factory2, 'CloudWatchLoggerFactory should be a singleton');
    }

    public function test_merges_configuration(): void
    {
        $config = $this->app['config']->get('cloudwatch-logger');

        $this->assertIsArray($config);
        $this->assertNotEmpty($config, 'Configuration should not be empty');
        $this->assertEquals('custom', $config['cloudwatch']['driver']);
        $this->assertEquals(CloudWatchLoggerFactory::class, $config['cloudwatch']['via']);
        $this->assertEquals('us-east-1', $config['cloudwatch']['aws']['region']);
    }

    public function test_publishes_configuration(): void
    {
        $sourcePath = realpath(__DIR__.'/../config/cloudwatch-logger.php');
        $targetPath = $this->app->configPath('cloudwatch-logger.php');

        $this->artisan('vendor:publish', [
            '--provider' => CloudWatchLoggerServiceProvider::class,
            '--tag' => 'config',
            '--force' => true,
        ]);

        $this->assertFileExists($targetPath, 'Config file should be published');
        $this->assertFileEquals($sourcePath, $targetPath, 'Published config should match source');

        // Cleanup
        unlink($targetPath);
    }
}
