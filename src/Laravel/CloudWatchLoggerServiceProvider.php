<?php

namespace Aporat\CloudWatchLogger\Laravel;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class CloudWatchLoggerServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register services with the container
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/cloudwatch-logger.php',
            'cloudwatch-logger'
        );
    }

    /**
     * Bootstrap any application services
     *
     * @return void
     */
    public function boot(): void
    {
        $configPath = __DIR__ . '/../../config/cloudwatch-logger.php';
        $this->publishes([
            $configPath => $this->getConfigPath()
        ], 'config');

        $this->registerLogger();
    }

    /**
     * Get the path to the configuration file destination
     *
     * @return string The full path to the config file
     */
    protected function getConfigPath(): string
    {
        return config_path('cloudwatch-logger.php');
    }

    /**
     * Register the CloudWatch logger factory with the application
     *
     * @return void
     */
    protected function registerLogger(): void
    {
        $this->app->bind(CloudWatchLoggerFactory::class, fn() => new CloudWatchLoggerFactory($this->app));
    }
}
