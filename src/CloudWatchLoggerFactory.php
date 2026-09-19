<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger;

use Aporat\CloudWatchLogger\Cache\LaravelCacheItemPool;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PhpNexus\Cwh\Handler\CloudWatch;
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
        $resolved = CloudWatchConfig::fromArray($config);

        $handler = $this->createHandler($resolved);
        $handler->setFormatter($this->resolveFormatter($resolved));

        $logger = new Logger($resolved->name);
        $logger->pushHandler($this->wrapHandler($handler, $resolved));

        if ($resolved->replacePlaceholders) {
            $logger->pushProcessor(new PsrLogMessageProcessor);
        }

        return $logger;
    }

    /**
     * Creates the AWS CloudWatch Logs client.
     */
    private function createClient(CloudWatchConfig $config): CloudWatchLogsClient
    {
        return new CloudWatchLogsClient($config->aws);
    }

    /**
     * Creates the Monolog CloudWatch handler.
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function createHandler(CloudWatchConfig $config): CloudWatch
    {
        try {
            return new CloudWatch(
                client: $this->createClient($config),
                group: $config->group,
                stream: $config->stream,
                retention: $config->retention,
                batchSize: $config->batchSize,
                tags: $config->tags,
                level: $config->level,
                bubble: $config->bubble,
                createGroup: $config->createGroup,
                createStream: $config->createStream,
                rpsLimit: $config->rpsLimit,
                cacheItemPool: $this->resolveCachePool($config),
                cacheItemTtl: $config->cacheTtl,
            );
        } catch (IncompleteCloudWatchConfig $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new IncompleteCloudWatchConfig('Unable to build the CloudWatch log handler: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Optionally wrap the handler so that a CloudWatch outage degrades into a
     * dropped log line rather than an exception thrown from the caller's
     * `Log::info()` — which, in a request path that logs its own failures,
     * turns a handled error into a 500.
     */
    private function wrapHandler(HandlerInterface $handler, CloudWatchConfig $config): HandlerInterface
    {
        return $config->suppressFailures ? new WhatFailureGroupHandler([$handler]) : $handler;
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

        // The handler refuses a pool it can never populate.
        if (! $config->createGroup && ! $config->createStream) {
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

        return match (true) {
            is_null($formatterConfig) => $this->defaultFormatter(),
            $formatterConfig instanceof FormatterInterface => $formatterConfig,
            is_string($formatterConfig) && is_subclass_of($formatterConfig, FormatterInterface::class) => $this->resolveFormatterClass($formatterConfig),
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
     *
     * @throws IncompleteCloudWatchConfig
     */
    private function resolveFormatterClass(string $formatterClass): FormatterInterface
    {
        if ($this->container !== null) {
            return $this->assertFormatter($this->container->make($formatterClass));
        }

        return $this->assertFormatter(new $formatterClass);
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
