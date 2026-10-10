<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Handler;

/**
 * Turns formatted log messages into PutLogEvents-ready events and batches.
 *
 * Encodes the PutLogEvents constraints in one place:
 *
 *  - at most 10,000 events per call;
 *  - at most 1,048,576 bytes per call, counted as the UTF-8 message bytes plus
 *    26 bytes of overhead per event (so one event's message is at most
 *    1,048,550 bytes);
 *  - events in chronological order;
 *  - a single call may not span more than 24 hours.
 *
 * @see https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html
 *
 * @internal
 */
final class EventBatcher
{
    public const int MAX_EVENTS_PER_BATCH = 10000;

    public const int MAX_BATCH_BYTES = 1048576;

    public const int EVENT_OVERHEAD_BYTES = 26;

    public const int MAX_MESSAGE_BYTES = self::MAX_BATCH_BYTES - self::EVENT_OVERHEAD_BYTES;

    /**
     * Strictly less than 24 hours, so a batch never sits on the boundary.
     */
    public const int MAX_BATCH_SPAN_MS = 86_400_000 - 1;

    /**
     * Split one formatted message into CloudWatch events.
     *
     * Invalid UTF-8 is scrubbed (the SDK cannot JSON-encode it, which would
     * fail the whole batch) and oversized messages are cut on character
     * boundaries, never in the middle of a multibyte sequence.
     *
     * @return list<array{timestamp: int, message: string}>
     */
    public static function toEvents(string $message, int $timestampMs, int $maxMessageBytes = self::MAX_MESSAGE_BYTES): array
    {
        if (! mb_check_encoding($message, 'UTF-8')) {
            $message = mb_scrub($message, 'UTF-8');
        }

        if ($message === '') {
            return [];
        }

        $events = [];
        $length = strlen($message);
        $offset = 0;

        while ($offset < $length) {
            $chunk = mb_strcut($message, $offset, $maxMessageBytes, 'UTF-8');

            if ($chunk === '') {
                // Defensive: a single character wider than the limit (impossible
                // for UTF-8 with the real limit) would otherwise loop forever.
                $chunk = substr($message, $offset, $maxMessageBytes);
            }

            $events[] = ['timestamp' => $timestampMs, 'message' => $chunk];
            $offset += strlen($chunk);
        }

        return $events;
    }

    /**
     * Size an event counts against the per-call byte limit.
     *
     * @param  array{timestamp: int, message: string}  $event
     */
    public static function size(array $event): int
    {
        return strlen($event['message']) + self::EVENT_OVERHEAD_BYTES;
    }

    /**
     * Sort events chronologically (stable, so same-millisecond events keep
     * their logging order) and split them into batches that each satisfy
     * every PutLogEvents limit.
     *
     * @template T of array{timestamp: int, message: string}
     *
     * @param  list<T>  $events
     * @return list<list<T>>
     */
    public static function batches(array $events): array
    {
        usort($events, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        $batches = [];
        $current = [];
        $bytes = 0;
        $first = null;

        foreach ($events as $event) {
            $size = self::size($event);

            if ($current !== [] && (
                count($current) >= self::MAX_EVENTS_PER_BATCH
                || $bytes + $size > self::MAX_BATCH_BYTES
                || $event['timestamp'] - $first > self::MAX_BATCH_SPAN_MS
            )) {
                $batches[] = $current;
                $current = [];
                $bytes = 0;
            }

            if ($current === []) {
                $first = $event['timestamp'];
            }

            $current[] = $event;
            $bytes += $size;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }
}
