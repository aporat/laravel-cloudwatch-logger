<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Support;

use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Monolog\Logger as MonologLogger;
use Throwable;

/**
 * Flushes every CloudWatch handler of the channels a log manager has already
 * resolved. Used between queue jobs and Octane requests/tasks/ticks, where the
 * process (and therefore the buffer) outlives the unit of work.
 *
 * Never resolves the log manager or a channel itself, and never throws: a
 * flush failure is reported through error_log(), not through a logger.
 */
final class CloudWatchFlusher
{
    public static function flush(Container $container): void
    {
        if (! $container->resolved('log')) {
            return;
        }

        try {
            $manager = $container->make('log');
        } catch (Throwable) {
            return;
        }

        if (! $manager instanceof LogManager) {
            return;
        }

        foreach (self::handlers($manager) as $handler) {
            try {
                $handler->flush();
            } catch (Throwable $e) {
                error_log('[cloudwatch-logger] flush failed: '.$e::class.': '.$e->getMessage());
            }
        }
    }

    /**
     * @return array<int, CloudWatchHandler>
     */
    public static function handlers(LogManager $manager): array
    {
        $handlers = [];

        foreach ($manager->getChannels() as $channel) {
            $logger = $channel instanceof IlluminateLogger ? $channel->getLogger() : $channel;

            if (! $logger instanceof MonologLogger) {
                continue;
            }

            foreach ($logger->getHandlers() as $handler) {
                if ($handler instanceof CloudWatchHandler) {
                    // Stack channels share handler instances with their members.
                    $handlers[spl_object_id($handler)] = $handler;
                }
            }
        }

        return $handlers;
    }
}
