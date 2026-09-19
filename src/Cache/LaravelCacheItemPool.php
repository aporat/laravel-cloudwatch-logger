<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Cache;

use Illuminate\Contracts\Cache\Repository;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * A small PSR-6 pool over a Laravel cache repository, used to remember that a
 * CloudWatch log group and stream already exist.
 *
 * Without it the handler issues a DescribeLogGroups and a DescribeLogStreams
 * call on the first write of every PHP process, for every channel.
 *
 * Two details matter and are the reason this does not simply wrap a generic
 * PSR-16 adapter:
 *
 *  - The handler keys its cache entries on the bare group and stream names, so
 *    two channels writing to a same-named stream in *different* groups would
 *    share an entry and the second group's stream would never be created. Every
 *    key here is therefore namespaced with the group it belongs to.
 *  - Group names legitimately contain '/' and '.', which PSR-6 reserves, and
 *    cache stores cap key length. Keys are hashed to sidestep both.
 *
 * Cache failures are swallowed: a log write must never fail because the cache
 * is unreachable, it should just fall back to asking CloudWatch.
 */
final class LaravelCacheItemPool implements CacheItemPoolInterface
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $namespace,
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        try {
            if ($this->cache->get($this->prefix($key)) !== null) {
                return new CacheItem($key, true, true);
            }
        } catch (Throwable) {
            // Treat an unreachable cache as a miss.
        }

        return new CacheItem($key);
    }

    /**
     * @param  array<int, string>  $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        // Scoped clearing isn't supported by every store; entries are short
        // lived, so expiry does the job instead.
        return false;
    }

    public function deleteItem(string $key): bool
    {
        try {
            return $this->cache->forget($this->prefix($key));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $deleted = $this->deleteItem($key) && $deleted;
        }

        return $deleted;
    }

    public function save(CacheItemInterface $item): bool
    {
        $ttl = $item instanceof CacheItem ? $item->ttlInSeconds() : null;

        try {
            if ($ttl === null) {
                return $this->cache->forever($this->prefix($item->getKey()), true);
            }

            return $ttl > 0 && $this->cache->put($this->prefix($item->getKey()), true, $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }

    /**
     * Namespace and hash a key so it is unique per log group and safe for any store.
     */
    private function prefix(string $key): string
    {
        return 'cloudwatch-logger:'.sha1($this->namespace.'|'.$key);
    }
}
