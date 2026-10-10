<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Support;

/**
 * A clock tests can move by hand, injected into the handler.
 */
final class ManualClock
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __construct(public float $now = 1_000_000.0) {}

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(int $microseconds): void
    {
        $this->sleeps[] = $microseconds;
        $this->now += $microseconds / 1_000_000;
    }
}
