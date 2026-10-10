<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Console\CloudWatchTestCommand;
use Aporat\CloudWatchLogger\Support\CloudWatchFlusher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
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
     * Queue events after which buffered CloudWatch events are sent.
     *
     * @var list<class-string>
     */
    public const array QUEUE_FLUSH_EVENTS = [
        JobProcessed::class,
        JobFailed::class,
        Looping::class,
        WorkerStopping::class,
    ];

    /**
     * Octane events after which buffered CloudWatch events are sent; only
     * listened to when Octane is installed.
     *
     * @var list<string>
     */
    public const array OCTANE_FLUSH_EVENTS = [
        'Laravel\\Octane\\Events\\RequestTerminated',
        'Laravel\\Octane\\Events\\TaskTerminated',
        'Laravel\\Octane\\Events\\TickTerminated',
    ];

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
            $this->commands([CloudWatchTestCommand::class]);
        }

        $this->registerFlushListeners();
    }

    /**
     * Flush CloudWatch handlers at the end of each queue job and Octane
     * operation. Those processes outlive the unit of work, so without this a
     * partially filled buffer would wait for the next batch to fill.
     */
    protected function registerFlushListeners(): void
    {
        if (! $this->app->bound('events')) {
            return;
        }

        $events = $this->app->make('events');

        if (! $events instanceof Dispatcher) {
            return;
        }

        $app = $this->app;

        $events->listen(self::QUEUE_FLUSH_EVENTS, static function () use ($app): void {
            CloudWatchFlusher::flush($app);
        });

        $octaneEvents = array_values(array_filter(self::OCTANE_FLUSH_EVENTS, 'class_exists'));

        if ($octaneEvents !== []) {
            $events->listen($octaneEvents, static function (object $event) use ($app): void {
                // Octane resolves services in a per-request sandbox.
                $sandbox = property_exists($event, 'sandbox') ? $event->sandbox : null;

                CloudWatchFlusher::flush($sandbox instanceof Container ? $sandbox : $app);
            });
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
