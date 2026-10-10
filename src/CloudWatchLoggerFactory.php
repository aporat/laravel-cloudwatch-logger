<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Cache\LaravelCacheItemPool;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Logger as IlluminateLogger;
use LogicException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * Factory for creating CloudWatch-integrated Monolog logger instances.
 *
 * Laravel resolves this out of the container for any logging channel declared
 * with `'driver' => 'custom'` and `'via' => CloudWatchLoggerFactory::class`,
 * then invokes it with that channel's configuration array.
 */
final class CloudWatchLoggerFactory
{
    /**
     * Default log retention period in days.
     */
    public const int DEFAULT_RETENTION_DAYS = CloudWatchConfig::DEFAULT_RETENTION_DAYS;

    /**
     * Default number of log entries to batch before sending.
     */
    public const int DEFAULT_BATCH_SIZE = CloudWatchConfig::DEFAULT_BATCH_SIZE;

    /**
     * Create a new CloudWatch logger factory instance.
     *
     * @param  Container|null  $container  The Laravel container, used to resolve
     *                                     formatter classes and cache stores.
     */
    public function __construct(private readonly ?Container $container = null) {}

    /**
     * Create a configured CloudWatch logger instance.
     *
     * @param  array<string, mixed>  $config  Configuration array for CloudWatch logging.
     * @return Logger Configured Monolog logger instance.
     *
     * @throws IncompleteCloudWatchConfig If the required config is missing or invalid.
     */
    public function __invoke(array $config): Logger
    {
        $resolved = $this->resolveConfig($config);

        $handler = $this->createHandler($resolved);
        $handler->setFormatter($this->resolveFormatter($resolved));

        $logger = new Logger($resolved->name);
        $logger->pushHandler($handler);

        if ($resolved->replacePlaceholders) {
            $logger->pushProcessor(new PsrLogMessageProcessor);
        }

        return $logger;
    }

    /**
     * Validate a raw channel configuration, using the application's
     * environment for the {env} stream placeholder.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteCloudWatchConfig
     */
    public function resolveConfig(array $config): CloudWatchConfig
    {
        $resolved = CloudWatchConfig::fromArray($config, $this->environment());

        if ($resolved->fallbackChannel !== null) {
            $this->assertFallbackIsNotCloudWatch($resolved->fallbackChannel);
        }

        return $resolved;
    }

    /**
     * Creates the AWS CloudWatch Logs client for a resolved configuration.
     */
    public function createClient(CloudWatchConfig $config): CloudWatchLogsClient
    {
        return new CloudWatchLogsClient($config->aws);
    }

    private function environment(): ?string
    {
        if ($this->container === null || ! $this->container->bound('config')) {
            return null;
        }

        $env = $this->container->make('config')->get('app.env');

        return is_string($env) && $env !== '' ? $env : null;
    }

    /**
     * Refuse a fallback channel that is itself a CloudWatch channel, or a
     * stack containing one: that would send the records straight back here.
     * (The handler also guards against this at runtime.)
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function assertFallbackIsNotCloudWatch(string $channel, int $depth = 0): void
    {
        if ($depth > 5 || $this->container === null || ! $this->container->bound('config')) {
            return;
        }

        $definition = $this->container->make('config')->get("logging.channels.$channel");

        if (! is_array($definition)) {
            return;
        }

        if (($definition['via'] ?? null) === self::class) {
            throw new IncompleteCloudWatchConfig("CloudWatch 'fallback_channel' '$channel' is itself a CloudWatch channel.");
        }

        if (($definition['driver'] ?? null) === 'stack') {
            foreach ((array) ($definition['channels'] ?? []) as $member) {
                if (is_string($member)) {
                    $this->assertFallbackIsNotCloudWatch($member, $depth + 1);
                }
            }
        }
    }

    /**
     * Build the closure the handler calls with records it could not deliver.
     *
     * The channel is resolved lazily, on first use, through Laravel's log
     * manager. Records keep their original level, message, context and time;
     * the reason is added to the context under `cloudwatch_fallback`.
     *
     * @return (Closure(list<LogRecord>, string): void)|null
     */
    private function fallback(CloudWatchConfig $config): ?Closure
    {
        $channel = $config->fallbackChannel;
        $container = $this->container;

        if ($channel === null || $container === null) {
            return null;
        }

        return static function (array $records, string $reason) use ($channel, $container): void {
            $logger = $container->make('log')->channel($channel);
            $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;

            if ($monolog instanceof Logger) {
                foreach ($monolog->getHandlers() as $handler) {
                    if ($handler instanceof CloudWatchHandler) {
                        throw new LogicException("Fallback channel '$channel' writes to CloudWatch; refusing to forward.");
                    }
                }
            }

            foreach ($records as $record) {
                $context = $record->context + ['cloudwatch_fallback' => $reason];

                if ($monolog instanceof Logger) {
                    $monolog->addRecord($record->level, $record->message, $context, $record->datetime);
                } else {
                    $logger->log($record->level->toPsrLogLevel(), $record->message, $context);
                }
            }
        };
    }

    /**
     * Creates the Monolog CloudWatch handler.
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function createHandler(CloudWatchConfig $config): CloudWatchHandler
    {
        $arguments = [
            'client' => $this->createClient($config),
            'group' => $config->group,
            'stream' => $config->stream,
            'retention' => $config->retention,
            'batchSize' => $config->batchSize,
            'tags' => $config->tags,
            'level' => $config->level,
            'bubble' => $config->bubble,
            'createGroup' => $config->createGroup,
            'createStream' => $config->createStream,
            'rpsLimit' => $config->rpsLimit,
            'cacheItemPool' => $this->resolveCachePool($config),
            'cacheItemTtl' => $config->cacheTtl,
            'maxBufferSize' => $config->maxBufferSize,
            'flushInterval' => $config->flushInterval,
            'circuitBreakerSeconds' => $config->circuitBreaker,
            'suppressFailures' => $config->suppressFailures,
            'enforceGroupSettings' => $config->enforceGroupSettings,
            'streamDateFormat' => $config->streamDateFormat,
            'streamPlaceholders' => ['env' => $config->environment],
            'fallback' => $this->fallback($config),
        ];

        // Laravel's `handler_with`: named constructor arguments that override
        // (or, for a `handler` subclass, extend) the computed ones.
        $arguments = array_replace($arguments, $config->handlerWith);
        $class = $config->handlerClass ?? CloudWatchHandler::class;

        try {
            // A custom handler class is resolved through the container (as
            // Laravel does for `handler`), so it can declare extra dependencies.
            $handler = $this->container !== null && $class !== CloudWatchHandler::class
                ? $this->container->make($class, $arguments)
                : new $class(...$arguments);
        } catch (Throwable $e) {
            throw new IncompleteCloudWatchConfig('Unable to build the CloudWatch log handler: '.$e->getMessage(), previous: $e);
        }

        return $handler;
    }

    /**
     * Resolve the PSR-6 pool the handler uses to remember that the log group and
     * stream already exist.
     *
     * Accepts a pool instance, `true` for the application's default cache store,
     * a store name, a container binding or class name, or `false`/`null` to
     * disable caching (the default).
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function resolveCachePool(CloudWatchConfig $config): ?CacheItemPoolInterface
    {
        $cache = $config->cache;

        if ($cache === null || $cache === false || $cache === '') {
            return null;
        }

        // Nothing would ever be written to the pool.
        if (! $config->createGroup && ! $config->createStream && ! $config->enforceGroupSettings) {
            return null;
        }

        if ($cache instanceof CacheItemPoolInterface) {
            return $cache;
        }

        // A class name is resolved as a pool; anything else names a cache store.
        if (is_string($cache) && class_exists($cache)) {
            return $this->assertPool($this->container?->make($cache) ?? new $cache, $cache);
        }

        if ($cache === true || is_string($cache)) {
            return $this->laravelCachePool($cache === true ? null : $cache, $config);
        }

        throw new IncompleteCloudWatchConfig("Invalid 'cache' value in CloudWatch configuration.");
    }

    /**
     * Build a pool backed by one of the application's cache stores.
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function laravelCachePool(?string $store, CloudWatchConfig $config): CacheItemPoolInterface
    {
        if ($this->container === null || ! $this->container->bound(CacheFactory::class)) {
            throw new IncompleteCloudWatchConfig(
                "CloudWatch 'cache' requires the application cache; pass a PSR-6 CacheItemPoolInterface instead."
            );
        }

        $factory = $this->container->make(CacheFactory::class);

        return new LaravelCacheItemPool($factory->store($store), $config->group);
    }

    /**
     * @throws IncompleteCloudWatchConfig
     */
    private function assertPool(mixed $pool, string $reference): CacheItemPoolInterface
    {
        if (! $pool instanceof CacheItemPoolInterface) {
            throw new IncompleteCloudWatchConfig("CloudWatch 'cache' value '$reference' is not a PSR-6 ".CacheItemPoolInterface::class.'.');
        }

        return $pool;
    }

    /**
     * Resolve the formatter for CloudWatch logs based on configuration.
     *
     * @throws IncompleteCloudWatchConfig If formatter configuration is invalid.
     */
    private function resolveFormatter(CloudWatchConfig $config): FormatterInterface
    {
        $formatterConfig = $config->formatter;

        if ($config->formatterWith !== [] && ! (is_string($formatterConfig) && is_subclass_of($formatterConfig, FormatterInterface::class))) {
            throw new IncompleteCloudWatchConfig("CloudWatch 'formatter_with' requires 'formatter' to be a ".FormatterInterface::class.' class name.');
        }

        return match (true) {
            is_null($formatterConfig), $formatterConfig === 'default' => $this->defaultFormatter(),
            $formatterConfig instanceof FormatterInterface => $formatterConfig,
            is_string($formatterConfig) && is_subclass_of($formatterConfig, FormatterInterface::class) => $this->resolveFormatterClass($formatterConfig, $config->formatterWith),
            is_string($formatterConfig) && $this->looksLikeClassName($formatterConfig) => throw new IncompleteCloudWatchConfig(
                "Formatter class '$formatterConfig' does not exist or does not implement ".FormatterInterface::class.'.'
            ),
            is_string($formatterConfig) && $formatterConfig !== '' => new LineFormatter($formatterConfig, null, false, true),
            is_callable($formatterConfig) => $this->assertFormatter($formatterConfig($config->originalConfig)),
            default => throw new IncompleteCloudWatchConfig('Invalid formatter configuration for CloudWatch logs.'),
        };
    }

    /**
     * The handler's own default, restated here so the formatter is always
     * explicit and the `%extra%`/`%context%` behaviour is stable.
     */
    private function defaultFormatter(): LineFormatter
    {
        return new LineFormatter('%channel%: %level_name%: %message% %context% %extra%', null, false, true);
    }

    /**
     * Detect strings that look like a fully-qualified class name (rather than
     * a LineFormatter template) so a typo or missing class surfaces as an
     * explicit error instead of silently becoming a format string.
     */
    private function looksLikeClassName(string $value): bool
    {
        return (bool) preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value);
    }

    /**
     * Instantiates a formatter from a class string.
     *
     * @param  class-string<FormatterInterface>  $formatterClass
     * @param  array<array-key, mixed>  $with
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function resolveFormatterClass(string $formatterClass, array $with = []): FormatterInterface
    {
        try {
            // Like Laravel's `formatter_with`: named arguments go through the
            // container; a positional list is passed to the constructor as is.
            $formatter = $this->container !== null && ! array_is_list($with)
                ? $this->container->make($formatterClass, $with)
                : ($this->container !== null && $with === []
                    ? $this->container->make($formatterClass)
                    : new $formatterClass(...$with));
        } catch (Throwable $e) {
            throw new IncompleteCloudWatchConfig("Unable to build the CloudWatch formatter '$formatterClass': ".$e->getMessage(), previous: $e);
        }

        return $this->assertFormatter($formatter);
    }

    /**
     * @throws IncompleteCloudWatchConfig
     */
    private function assertFormatter(mixed $formatter): FormatterInterface
    {
        if (! $formatter instanceof FormatterInterface) {
            throw new IncompleteCloudWatchConfig('The configured CloudWatch formatter did not resolve to a '.FormatterInterface::class.'.');
        }

        return $formatter;
    }
}
