<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Cache;

use Aporat\CloudWatchLogger\Cache\CacheItem;
use Aporat\CloudWatchLogger\Cache\LaravelCacheItemPool;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LaravelCacheItemPool::class)]
#[CoversClass(CacheItem::class)]
final class LaravelCacheItemPoolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_round_trips_an_item(): void
    {
        $pool = $this->pool();

        $this->assertFalse($pool->getItem('errors')->isHit());

        $item = $pool->getItem('errors');
        $item->set(true);
        $item->expiresAfter(300);

        $this->assertTrue($pool->save($item));
        $this->assertTrue($pool->getItem('errors')->isHit());
        $this->assertTrue($pool->hasItem('errors'));
    }

    /**
     * The handler keys its cache entries on the bare stream name. Two channels
     * writing to a same-named stream in different groups must not share an
     * entry, or the second group's stream is never created and every write to
     * it fails with ResourceNotFound.
     */
    #[Test]
    public function entries_are_scoped_to_their_log_group(): void
    {
        $store = new Repository(new ArrayStore);

        $first = new LaravelCacheItemPool($store, 'group-one');
        $second = new LaravelCacheItemPool($store, 'group-two');

        $item = $first->getItem('requests');
        $item->set(true);
        $item->expiresAfter(300);
        $first->save($item);

        $this->assertTrue($first->getItem('requests')->isHit());
        $this->assertFalse($second->getItem('requests')->isHit());
    }

    /**
     * Group names legitimately contain '/' and '.', which PSR-6 reserves.
     */
    #[Test]
    public function it_handles_keys_containing_psr6_reserved_characters(): void
    {
        $pool = $this->pool('/aws/lambda/my.app');

        $item = $pool->getItem('/aws/lambda/my.app');
        $item->set(true);
        $item->expiresAfter(300);

        $this->assertTrue($pool->save($item));
        $this->assertTrue($pool->getItem('/aws/lambda/my.app')->isHit());
    }

    #[Test]
    public function it_deletes_items(): void
    {
        $pool = $this->pool();

        $item = $pool->getItem('errors');
        $item->expiresAfter(300);
        $pool->save($item);

        $this->assertTrue($pool->deleteItem('errors'));
        $this->assertFalse($pool->getItem('errors')->isHit());
        $this->assertTrue($pool->deleteItems([]));
    }

    #[Test]
    public function it_saves_forever_when_no_expiry_is_set(): void
    {
        $pool = $this->pool();

        $item = $pool->getItem('errors');
        $item->set(true);

        $this->assertTrue($pool->save($item));
        $this->assertTrue($pool->getItem('errors')->isHit());
    }

    #[Test]
    public function it_returns_items_for_every_requested_key(): void
    {
        $pool = $this->pool();

        $items = iterator_to_array($pool->getItems(['a', 'b']));

        $this->assertSame(['a', 'b'], array_keys($items));
        $this->assertFalse($items['a']->isHit());
    }

    /**
     * A cache outage must degrade into "ask CloudWatch again", never into a
     * failed log write.
     */
    #[Test]
    public function a_broken_cache_store_degrades_to_a_miss(): void
    {
        $store = Mockery::mock(RepositoryContract::class);
        $store->shouldReceive('get')->andThrow(new RuntimeException('redis is down'));
        $store->shouldReceive('put')->andThrow(new RuntimeException('redis is down'));
        $store->shouldReceive('forget')->andThrow(new RuntimeException('redis is down'));

        $pool = new LaravelCacheItemPool($store, 'group');

        $item = $pool->getItem('errors');
        $item->expiresAfter(300);

        $this->assertFalse($item->isHit());
        $this->assertFalse($pool->save($item));
        $this->assertFalse($pool->deleteItem('errors'));
    }

    #[Test]
    public function commit_and_clear_report_honestly(): void
    {
        $pool = $this->pool();

        $item = $pool->getItem('errors');
        $item->expiresAfter(300);

        $this->assertTrue($pool->saveDeferred($item));
        $this->assertTrue($pool->commit());
        $this->assertFalse($pool->clear());
    }

    #[Test]
    public function an_item_reports_its_key_and_value(): void
    {
        $miss = new CacheItem('errors');
        $this->assertSame('errors', $miss->getKey());
        $this->assertNull($miss->get());
        $this->assertNull($miss->ttlInSeconds());

        $hit = new CacheItem('errors', true, true);
        $this->assertTrue($hit->get());

        $hit->expiresAt(new \DateTimeImmutable('+60 seconds'));
        $this->assertEqualsWithDelta(60, $hit->ttlInSeconds(), 2);

        $hit->expiresAfter(new \DateInterval('PT30S'));
        $this->assertEqualsWithDelta(30, $hit->ttlInSeconds(), 2);

        $hit->expiresAfter(null);
        $this->assertNull($hit->ttlInSeconds());
    }

    private function pool(string $namespace = 'group'): LaravelCacheItemPool
    {
        return new LaravelCacheItemPool(new Repository(new ArrayStore), $namespace);
    }
}
