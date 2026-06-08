<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel CloudWatch Logger package.
 *
 * Registers the CloudWatch logger factory as a service and handles configuration
 * merging and publishing for CloudWatch logging integration.
 */
class CloudWatchLoggerServiceProvider extends ServiceProvider
{
    /**
     * Path to the package's configuration file.
     *
     * @var string
     */
    private const CONFIG_PATH = __DIR__.'/../config/cloudwatch-logger.php';

    /**
     * Register services with the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'cloudwatch-logger');
        $this->registerCloudWatchLogger();
    }

    /**
     * Bootstrap application services and publish configuration.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => config_path('cloudwatch-logger.php')], 'config');
        }
    }

    /**
     * Register the CloudWatch logger factory with the application.
     */
    protected function registerCloudWatchLogger(): void
    {
        $this->app->singleton(CloudWatchLoggerFactory::class, fn ($app) => new CloudWatchLoggerFactory($app));
    }
}
