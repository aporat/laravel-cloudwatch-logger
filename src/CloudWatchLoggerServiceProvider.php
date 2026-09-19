<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel CloudWatch Logger package.
 *
 * Registers the CloudWatch logger factory as a service, merges the package
 * configuration, and exposes it as a ready-to-use `cloudwatch` logging channel.
 */
class CloudWatchLoggerServiceProvider extends ServiceProvider
{
    /**
     * Path to the package's configuration file.
     */
    private const string CONFIG_PATH = __DIR__.'/../config/cloudwatch-logger.php';

    /**
     * Register services with the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'cloudwatch-logger');
        $this->registerCloudWatchLogger();
        $this->registerDefaultChannel();
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

    /**
     * Expose the package configuration as a `cloudwatch` logging channel.
     *
     * Applications that define their own `cloudwatch` channel in
     * `config/logging.php` keep it — this only fills the gap so the package is
     * usable straight after installation.
     */
    protected function registerDefaultChannel(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if ($config->has('logging.channels.cloudwatch')) {
            return;
        }

        $config->set('logging.channels.cloudwatch', $config->get('cloudwatch-logger'));
    }
}
