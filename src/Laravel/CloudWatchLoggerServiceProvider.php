<?php

namespace Aporat\CloudWatchLogger\Laravel;

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class CloudWatchLoggerServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $configPath = __DIR__.'/../../config/cloudwatch-logger.php';
        $this->mergeConfigFrom($configPath, 'cloudwatch-logger');
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        $configPath = __DIR__.'/../../config/cloudwatch-logger.php';
        $this->publishes([$configPath => $this->getConfigPath()], 'config');

        $this->registerLogger();
    }

    /**
     * Get the config path.
     *
     * @return string
     */
    protected function getConfigPath(): string
    {
        return config_path('cloudwatch-logger.php');
    }

    /**
     * Register the logger provider.
     *
     * @return void
     */
    public function registerLogger(): void
    {
        // Register the main class to use with the facade
        $this->app->bind(CloudWatchLoggerFactory::class, function () {
            return new CloudWatchLoggerFactory($this->app);
        });
    }
}
