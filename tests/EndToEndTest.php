<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider;
use Aporat\CloudWatchLogger\Handler\CloudWatchHandler;
use Aporat\CloudWatchLogger\Support\CloudWatchFlusher;
use Aporat\CloudWatchLogger\Tests\Support\FakeCloudWatchLogs;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use LogicException;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once __DIR__.'/Fixtures/octane-events.php';

/**
 * Writes go through Laravel's Log manager and exception handler, with the AWS
 * SDK answered by an in-memory CloudWatch.
 */
#[CoversClass(CloudWatchLoggerServiceProvider::class)]
#[CoversClass(CloudWatchFlusher::class)]
#[CoversClass(CloudWatchHandler::class)]
final class EndToEndTest extends TestCase
{
    private FakeCloudWatchLogs $aws;

    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->aws = new FakeCloudWatchLogs;

        parent::setUp();

    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== '') {
            @unlink($this->errorLog);
        }

        parent::tearDown();
        Mockery::close();
    }

    /**
     * PHPUnit points error_log at its own capture file for each test, so the
     * redirect has to happen inside the test body.
     */
    private function redirectErrorLog(): void
    {
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'cwl');
        ini_set('error_log', $this->errorLog);
    }

    protected function getPackageProviders($app): array
    {
        return [CloudWatchLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.default', 'cloudwatch');
        $app['config']->set('logging.channels.cloudwatch.aws.handler', $this->aws);
        $app['config']->set('logging.channels.cloudwatch.aws.retries', 0);
        $app['config']->set('logging.channels.cloudwatch.aws.credentials', ['key' => 'AKIDTEST', 'secret' => 'secret']);
        $app['config']->set('logging.channels.cloudwatch.level', 'debug');
        $app['config']->set('logging.channels.cloudwatch.formatter', '%level_name%: %message% %context%');
        $app['config']->set('logging.channels.cloudwatch.batch_size', 100);
    }

    #[Test]
    public function a_log_call_through_the_log_manager_reaches_cloudwatch(): void
    {
        Log::channel('cloudwatch')->error('order failed', ['order' => 42]);
        $this->assertSame([], $this->aws->delivered(), 'Buffered until a flush.');

        Log::channel('cloudwatch')->getLogger()->close();

        $this->assertSame(['ERROR: order failed {"order":42}'], $this->aws->delivered());
    }

    #[Test]
    public function a_stack_channel_shares_the_handler_and_is_flushed_once(): void
    {
        $this->app['config']->set('logging.channels.app', ['driver' => 'stack', 'channels' => ['cloudwatch']]);

        Log::channel('app')->warning('via stack');
        CloudWatchFlusher::flush($this->app);

        $this->assertSame(['WARNING: via stack '], $this->aws->delivered());
        $this->assertCount(1, CloudWatchFlusher::handlers($this->app['log']));
    }

    /**
     * Regression: with a broken CloudWatch, ExceptionHandler::report() threw
     * the CloudWatch exception, so the original exception was lost (and a
     * queue worker would die).
     */
    #[Test]
    public function reporting_an_exception_with_cloudwatch_down_does_not_throw_or_lose_it(): void
    {
        $this->redirectErrorLog();
        $this->app['config']->set('logging.channels.cloudwatch.batch_size', 1);
        // No back-off, so the recovery below can be shown without waiting.
        $this->app['config']->set('logging.channels.cloudwatch.circuit_breaker', 0);
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;

        $this->app->make(ExceptionHandler::class)->report(new LogicException('original business error'));

        $handler = Log::channel('cloudwatch')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(CloudWatchHandler::class, $handler);
        $this->assertSame(1, $handler->getBufferedEventCount(), 'Kept for delivery once CloudWatch is back.');

        $errorLog = (string) file_get_contents($this->errorLog);
        $this->assertStringContainsString('[cloudwatch-logger]', $errorLog);
        $this->assertStringContainsString('Failed to connect', $errorLog);

        // Once CloudWatch is back, the original exception is delivered.
        $this->aws->failWith = null;
        $handler->flush();

        $this->assertStringContainsString('original business error', implode("\n", $this->aws->delivered()));
    }

    #[Test]
    public function reporting_still_throws_when_failures_are_explicitly_not_suppressed(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.batch_size', 1);
        $this->app['config']->set('logging.channels.cloudwatch.suppress_failures', false);
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;

        $this->expectException(RuntimeException::class);

        $this->app->make(ExceptionHandler::class)->report(new LogicException('original'));
    }

    /**
     * @return iterable<string, array{callable(): object}>
     */
    public static function queueEvents(): iterable
    {
        $job = fn () => Mockery::mock(Job::class);

        yield 'JobProcessed' => [fn () => new JobProcessed('redis', $job())];
        yield 'JobFailed' => [fn () => new JobFailed('redis', $job(), new RuntimeException('x'))];
        yield 'Looping' => [fn () => new Looping('redis', 'default')];
        yield 'WorkerStopping' => [fn () => new WorkerStopping(0)];
    }

    /**
     * Regression: queue workers never flushed between jobs, so up to
     * batch_size events sat in memory until the worker exited.
     *
     * @param  callable(): object  $event
     */
    #[Test]
    #[DataProvider('queueEvents')]
    public function queue_worker_events_flush_the_buffer(callable $event): void
    {
        Log::error('from a job');
        $this->assertSame([], $this->aws->delivered());

        Event::dispatch($event());

        $this->assertSame(['ERROR: from a job '], $this->aws->delivered());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function octaneEvents(): iterable
    {
        yield 'RequestTerminated' => [RequestTerminated::class];
        yield 'TaskTerminated' => [TaskTerminated::class];
        yield 'TickTerminated' => [TickTerminated::class];
    }

    /**
     * @param  class-string  $event
     */
    #[Test]
    #[DataProvider('octaneEvents')]
    public function octane_operation_events_flush_the_sandbox_buffer(string $event): void
    {
        Log::error('from octane');

        Event::dispatch(new $event($this->app, $this->app));

        $this->assertSame(['ERROR: from octane '], $this->aws->delivered());
    }

    /**
     * Octane's FlushMonologState resets every resolved logger before each
     * request; reset() must send what is buffered.
     */
    #[Test]
    public function monolog_reset_flushes_the_buffer(): void
    {
        Log::error('before reset');

        Log::channel('cloudwatch')->getLogger()->reset();

        $this->assertSame(['ERROR: before reset '], $this->aws->delivered());
    }

    #[Test]
    public function flushing_never_resolves_an_unused_log_manager(): void
    {
        $this->app->forgetInstance('log');
        $this->assertFalse($this->app->resolved('log') && $this->app['log']->getChannels() !== []);

        Event::dispatch(new JobProcessed('redis', Mockery::mock(Job::class)));

        $this->assertSame([], $this->aws->calls);
    }

    #[Test]
    public function a_flush_listener_never_throws_even_when_failures_are_not_suppressed(): void
    {
        $this->redirectErrorLog();
        $this->app['config']->set('logging.channels.cloudwatch.suppress_failures', false);
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::connectionError() : null;
        Log::error('queued');

        Event::dispatch(new JobProcessed('redis', Mockery::mock(Job::class)));

        $this->assertStringContainsString('[cloudwatch-logger] flush failed', (string) file_get_contents($this->errorLog));
    }
}
