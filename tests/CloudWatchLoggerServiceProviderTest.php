<?php

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\Laravel\CloudWatchLoggerServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Mockery;
use PHPUnit\Framework\TestCase;

class CloudWatchLoggerServiceProviderTest extends TestCase
{
    public function testServiceProvider(): void
    {
        $app = Mockery::mock(Application::class);

        $provider = new CloudWatchLoggerServiceProvider($app);
        $this->assertInstanceOf(CloudWatchLoggerServiceProvider::class, $provider);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
