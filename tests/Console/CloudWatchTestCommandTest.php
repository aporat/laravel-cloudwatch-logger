<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests\Console;

use Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider;
use Aporat\CloudWatchLogger\Console\CloudWatchTestCommand;
use Aporat\CloudWatchLogger\Tests\Support\FakeCloudWatchLogs;
use Aws\Credentials\Credentials;
use Aws\Exception\CredentialsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CloudWatchTestCommand::class)]
final class CloudWatchTestCommandTest extends TestCase
{
    private const string SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private FakeCloudWatchLogs $aws;

    protected function setUp(): void
    {
        $this->aws = new FakeCloudWatchLogs;

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [CloudWatchLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'production');
        $app['config']->set('logging.default', 'stack');
        $app['config']->set('logging.channels.cloudwatch.aws.handler', $this->aws);
        $app['config']->set('logging.channels.cloudwatch.aws.retries', 0);
        $app['config']->set('logging.channels.cloudwatch.aws.credentials', ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => self::SECRET]);
        $app['config']->set('logging.channels.cloudwatch.group', 'my-app');
        $app['config']->set('logging.channels.cloudwatch.stream', '{env}-web');
        $app['config']->set('logging.channels.cloudwatch.tags', ['team' => 'core']);
    }

    #[Test]
    public function a_full_run_creates_the_group_and_stream_and_delivers_an_event(): void
    {
        [$code, $output] = $this->run_();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Testing log channel cloudwatch', $output);
        $this->assertMatchesRegularExpression('/Configuration\s+\|\s+PASS\s+\|\s+[\d.]+ ms\s+\|\s+region us-east-1, group my-app, stream production-web/', $output);
        $this->assertMatchesRegularExpression('/Credentials\s+\|\s+PASS .*access key ID \*\*\*\*MPLE, from the channel config/', $output);
        $this->assertMatchesRegularExpression('/Log group\s+\|\s+PASS .*created with 14-day retention/', $output);
        $this->assertMatchesRegularExpression('/Log stream\s+\|\s+PASS .*production-web created/', $output);
        $this->assertMatchesRegularExpression('/Send test event\s+\|\s+PASS .*event accepted by my-app\/production-web/', $output);
        $this->assertStringContainsString('CloudWatch test passed', $output);
        $this->assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'DescribeLogStreams', 'CreateLogStream', 'PutLogEvents'], $this->aws->names());
        $this->assertSame(['team' => 'core'], $this->aws->tags['my-app']);
        $this->assertStringContainsString('cloudwatch:test event', $this->aws->delivered()[0]);
        $this->assertNoSecrets($output);
    }

    #[Test]
    public function repeated_runs_in_one_process_do_not_share_results(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'DescribeLogGroups' ? FakeCloudWatchLogs::error('AccessDeniedException', $name) : null;
        $this->assertSame(1, $this->run_()[0]);

        $this->aws->failWith = null;
        [$code, $output] = $this->run_();

        $this->assertSame(0, $code, $output);
        $this->assertSame(1, substr_count($output, 'Configuration'));
        $this->assertStringNotContainsString('FAIL', $output);
    }

    #[Test]
    public function an_existing_group_and_stream_are_reported(): void
    {
        $this->aws->groups['my-app'] = true;
        $this->aws->retention['my-app'] = 30;
        $this->aws->streams['my-app|production-web'] = true;

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/Log group\s+\|\s+PASS .*exists, retention 30 days/', $output);
        $this->assertMatchesRegularExpression('/Log stream\s+\|\s+PASS .*production-web exists/', $output);
        $this->assertStringNotContainsString('Group settings', $output, 'Only shown with enforce_group_settings.');
    }

    #[Test]
    public function a_dry_run_only_reads(): void
    {
        [$code, $output] = $this->run_(['--dry-run' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['DescribeLogGroups'], $this->aws->names());
        $this->assertMatchesRegularExpression('/Log group\s+\|\s+DRY .*does not exist; would be created with 14-day retention/', $output);
        $this->assertMatchesRegularExpression('/Log stream\s+\|\s+DRY .*production-web would be created with the group/', $output);
        $this->assertMatchesRegularExpression('/Send test event\s+\|\s+SKIP\s+\|\s+-\s+\|\s+--dry-run: no event sent/', $output);
        $this->assertStringContainsString('CloudWatch dry run passed.', $output);
    }

    #[Test]
    public function a_dry_run_reports_a_missing_stream_and_pending_group_settings(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.enforce_group_settings', true);
        $this->aws->groups['my-app'] = true;

        [$code, $output] = $this->run_(['--dry-run' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['DescribeLogGroups', 'DescribeLogStreams'], $this->aws->names());
        $this->assertMatchesRegularExpression('/Group settings\s+\|\s+DRY .*would apply retention 14 days and 1 tag\(s\)/', $output);
        $this->assertMatchesRegularExpression('/Log stream\s+\|\s+DRY .*production-web does not exist; would be created/', $output);
    }

    #[Test]
    public function group_settings_are_applied_when_enforced(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.enforce_group_settings', true);
        $this->aws->groups['my-app'] = true;

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/Group settings\s+\|\s+PASS .*applied retention 14 days and 1 tag\(s\)/', $output);
        $this->assertSame(14, $this->aws->retention['my-app']);
        $this->assertSame(['team' => 'core'], $this->aws->tags['my-app']);
    }

    #[Test]
    public function a_group_settings_failure_is_a_warning_not_a_failure(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.enforce_group_settings', true);
        $this->aws->groups['my-app'] = true;
        $this->aws->failWith = fn (string $name) => $name === 'TagResource' ? FakeCloudWatchLogs::error('AccessDeniedException', $name) : null;

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/Group settings\s+\|\s+WARN .*access denied: grant logs:TagResource/', $output);
    }

    #[Test]
    public function missing_permissions_fail_with_the_action_to_grant(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'DescribeLogGroups' ? FakeCloudWatchLogs::error('AccessDeniedException', $name) : null;

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/Log group\s+\|\s+FAIL .*access denied: grant logs:DescribeLogGroups/', $output);
        $this->assertMatchesRegularExpression('/Log stream\s+\|\s+SKIP\s+\|\s+-\s+\|\s+skipped: a previous step failed/', $output);
        $this->assertMatchesRegularExpression('/Send test event\s+\|\s+SKIP/', $output);
        $this->assertStringContainsString('CloudWatch test failed.', $output);
        $this->assertNotContains('PutLogEvents', $this->aws->names());
    }

    #[Test]
    public function a_rejected_put_fails_the_run(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? FakeCloudWatchLogs::error('AccessDeniedException', $name) : null;

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/Send test event\s+\|\s+FAIL .*access denied: grant logs:PutLogEvents/', $output);
    }

    #[Test]
    public function rejected_log_events_fail_the_run(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'PutLogEvents' ? new Result(['rejectedLogEventsInfo' => ['tooOldLogEventEndIndex' => 0]]) : null;

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('event rejected: {"tooOldLogEventEndIndex":0}', $output);
    }

    #[Test]
    public function an_unreachable_endpoint_is_explained(): void
    {
        $this->aws->failWith = fn (string $name) => FakeCloudWatchLogs::connectionError($name);

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not reach CloudWatch Logs (DescribeLogGroups): check the region, network and endpoint', $output);
    }

    #[Test]
    public function rejected_credentials_are_explained(): void
    {
        $this->aws->failWith = fn (string $name) => FakeCloudWatchLogs::error('UnrecognizedClientException', $name);

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('UnrecognizedClientException: the credentials were rejected', $output);
        $this->assertNoSecrets($output);
    }

    #[Test]
    public function other_aws_errors_name_the_operation_and_code(): void
    {
        $this->aws->failWith = fn (string $name) => $name === 'CreateLogStream' ? FakeCloudWatchLogs::error('LimitExceededException', $name) : null;

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('CreateLogStream failed: LimitExceededException', $output);
    }

    #[Test]
    public function unresolvable_credentials_fail_the_credentials_step(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.aws.credentials', fn () => Create::rejectionFor(new CredentialsException('No credentials found')));

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/Credentials\s+\|\s+FAIL .*CredentialsException: No credentials found/', $output);
        $this->assertSame([], $this->aws->calls);
    }

    #[Test]
    public function temporary_credentials_from_the_provider_chain_are_labelled(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.aws.credentials', fn () => Create::promiseFor(new Credentials('ASIAEXAMPLEKEYID1234', self::SECRET, 'session-token-value')));

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('access key ID ****1234 (temporary), from the channel config', $output);
        $this->assertStringNotContainsString('session-token-value', $output);
    }

    #[Test]
    public function a_missing_group_without_create_group_fails(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.create_group', false);

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("does not exist and 'create_group' is false", $output);
    }

    #[Test]
    public function a_missing_stream_without_create_stream_fails(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.create_stream', false);
        $this->aws->groups['my-app'] = true;

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("production-web does not exist and 'create_stream' is false", $output);
    }

    #[Test]
    public function an_invalid_configuration_fails_and_skips_everything_else(): void
    {
        $this->app['config']->set('logging.channels.cloudwatch.batch_size', 0);

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/Configuration\s+\|\s+FAIL .*IncompleteCloudWatchConfig/', $output);
        $this->assertSame(4, substr_count($output, 'skipped: a previous step failed'));
        $this->assertSame([], $this->aws->calls);
    }

    #[Test]
    public function a_channel_that_is_not_cloudwatch_is_refused(): void
    {
        [$code, $output] = $this->run_(['channel' => 'single']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Log channel 'single' does not use", $output);

        [$code, $output] = $this->run_(['channel' => 'nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("Log channel 'nope' is not defined", $output);
    }

    #[Test]
    public function the_default_channel_is_used_when_it_is_a_cloudwatch_channel(): void
    {
        $this->app['config']->set('logging.channels.audit', $this->app['config']->get('logging.channels.cloudwatch'));
        $this->app['config']->set('logging.default', 'audit');

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Testing log channel audit', $output);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: string}
     */
    private function run_(array $arguments = []): array
    {
        $code = Artisan::call('cloudwatch:test', $arguments);

        return [$code, Artisan::output()];
    }

    private function assertNoSecrets(string $output): void
    {
        $this->assertStringNotContainsString(self::SECRET, $output);
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $output);
    }
}
