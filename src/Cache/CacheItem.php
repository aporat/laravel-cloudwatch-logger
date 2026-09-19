<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Cache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/**
 * Minimal PSR-6 cache item used by {@see LaravelCacheItemPool}.
 *
 * @internal
 */
final class CacheItem implements CacheItemInterface
{
    private ?int $expiresAt = null;

    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private readonly bool $hit = false,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        $this->expiresAt = match (true) {
            $time === null => null,
            is_int($time) => time() + $time,
            default => (new \DateTimeImmutable)->add($time)->getTimestamp(),
        };

        return $this;
    }

    /**
     * Seconds until this item expires, or null if it never expires.
     */
    public function ttlInSeconds(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return max(0, $this->expiresAt - time());
    }
}
