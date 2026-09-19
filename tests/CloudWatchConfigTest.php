<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Tests;

use Aporat\CloudWatchLogger\CloudWatchConfig;
use Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig;
use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CloudWatchConfig::class)]
final class CloudWatchConfigTest extends TestCase
{
    #[Test]
    public function it_applies_documented_defaults(): void
    {
        $config = CloudWatchConfig::fromArray($this->baseConfig());

        $this->assertSame(CloudWatchConfig::DEFAULT_RETENTION_DAYS, $config->retention);
        $this->assertSame(CloudWatchConfig::DEFAULT_BATCH_SIZE, $config->batchSize);
        $this->assertSame(Level::Debug, $config->level);
        $this->assertTrue($config->bubble);
        $this->assertTrue($config->createGroup);
        $this->assertTrue($config->createStream);
        $this->assertSame(0, $config->rpsLimit);
        $this->assertSame([], $config->tags);
        $this->assertFalse($config->suppressFailures);
        $this->assertFalse($config->replacePlaceholders);
    }

    /**
     * Laravel's env() yields strings, so every numeric and boolean option has
     * to accept its string spelling.
     */
    #[Test]
    public function it_accepts_string_values_from_the_environment(): void
    {
        $config = CloudWatchConfig::fromArray($this->baseConfig([
            'retention' => '30',
            'batch_size' => '25',
            'rps_limit' => '5',
            'cache_ttl' => '60',
            'create_group' => 'false',
            'create_stream' => '0',
            'suppress_failures' => 'true',
            'replace_placeholders' => '1',
        ]));

        $this->assertSame(30, $config->retention);
        $this->assertSame(25, $config->batchSize);
        $this->assertSame(5, $config->rpsLimit);
        $this->assertSame(60, $config->cacheTtl);
        $this->assertFalse($config->createGroup);
        $this->assertFalse($config->createStream);
        $this->assertTrue($config->suppressFailures);
        $this->assertTrue($config->replacePlaceholders);
    }

    /**
     * @return array<string, array{mixed, Level}>
     */
    public static function levelProvider(): array
    {
        return [
            'enum' => [Level::Warning, Level::Warning],
            'name' => ['warning', Level::Warning],
            'mixed case name' => ['Warning', Level::Warning],
            'alias' => ['warn', Level::Warning],
            'int' => [400, Level::Error],
            'numeric string' => ['400', Level::Error],
        ];
    }

    #[Test]
    #[DataProvider('levelProvider')]
    public function it_resolves_every_supported_level_spelling(mixed $input, Level $expected): void
    {
        $this->assertSame($expected, CloudWatchConfig::fromArray($this->baseConfig(['level' => $input]))->level);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidLevelProvider(): array
    {
        return [
            'unknown name' => ['verbose'],
            'severity not in the enum' => [450],
            'empty string' => [''],
            'array' => [['error']],
        ];
    }

    #[Test]
    #[DataProvider('invalidLevelProvider')]
    public function it_rejects_an_invalid_level(mixed $level): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig(['level' => $level]));
    }

    #[Test]
    public function a_null_retention_means_never_expire(): void
    {
        $this->assertNull(CloudWatchConfig::fromArray($this->baseConfig(['retention' => null]))->retention);
        $this->assertNull(CloudWatchConfig::fromArray($this->baseConfig(['retention' => '']))->retention);
    }

    /**
     * 10 is not in CloudWatch's list of accepted retention periods; it would be
     * rejected by PutRetentionPolicy the first time the group is created.
     */
    #[Test]
    public function it_rejects_a_retention_cloudwatch_does_not_accept(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessageMatches('/Invalid CloudWatch log retention/');

        CloudWatchConfig::fromArray($this->baseConfig(['retention' => 10]));
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function invalidBatchSizeProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'above the api ceiling' => [10001]];
    }

    #[Test]
    #[DataProvider('invalidBatchSizeProvider')]
    public function it_rejects_an_out_of_range_batch_size(int|string $batchSize): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig(['batch_size' => $batchSize]));
    }

    #[Test]
    public function it_rejects_a_log_group_name_cloudwatch_would_refuse(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessageMatches('/Invalid CloudWatch log group name/');

        CloudWatchConfig::fromArray($this->baseConfig(['group' => 'my group!']));
    }

    #[Test]
    public function it_accepts_the_punctuation_cloudwatch_allows_in_a_group_name(): void
    {
        $config = CloudWatchConfig::fromArray($this->baseConfig(['group' => '/aws/app.name-1_2#3']));

        $this->assertSame('/aws/app.name-1_2#3', $config->group);
    }

    #[Test]
    public function it_rejects_a_log_stream_name_cloudwatch_would_refuse(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);
        $this->expectExceptionMessageMatches('/Invalid CloudWatch log stream name/');

        CloudWatchConfig::fromArray($this->baseConfig(['stream' => 'a:b']));
    }

    #[Test]
    public function it_rejects_a_name_longer_than_the_api_allows(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig(['group' => str_repeat('a', 513)]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredKeyProvider(): array
    {
        return ['group' => ['group'], 'stream' => ['stream'], 'name' => ['name'], 'aws' => ['aws']];
    }

    #[Test]
    #[DataProvider('requiredKeyProvider')]
    public function it_requires_every_mandatory_key(string $key): void
    {
        $config = $this->baseConfig();
        unset($config[$key]);

        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($config);
    }

    #[Test]
    public function it_requires_the_aws_region_and_version(): void
    {
        foreach (['region', 'version'] as $key) {
            $config = $this->baseConfig();
            unset($config['aws'][$key]);

            try {
                CloudWatchConfig::fromArray($config);
                $this->fail("Expected a missing AWS '$key' to be rejected.");
            } catch (IncompleteCloudWatchConfig $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /**
     * Empty credentials are intentional: they defer to the AWS default
     * credential chain, and the empty block has to be dropped or the SDK
     * rejects it.
     */
    #[Test]
    public function it_drops_empty_credentials_so_the_default_chain_applies(): void
    {
        $config = CloudWatchConfig::fromArray($this->baseConfig([
            'aws' => ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => '', 'secret' => '']],
        ]));

        $this->assertArrayNotHasKey('credentials', $config->aws);
    }

    #[Test]
    public function it_rejects_half_filled_credentials(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig([
            'aws' => ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'AKIA', 'secret' => '']],
        ]));
    }

    #[Test]
    public function it_rejects_tags_that_are_not_a_string_map(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig(['tags' => ['Team' => ['platform']]]));
    }

    #[Test]
    public function it_rejects_a_non_array_tags_value(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig(['tags' => 'platform']));
    }

    #[Test]
    public function it_rejects_a_negative_rps_limit(): void
    {
        $this->expectException(IncompleteCloudWatchConfig::class);

        CloudWatchConfig::fromArray($this->baseConfig(['rps_limit' => -1]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'aws' => [
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'key', 'secret' => 'secret'],
            ],
            'group' => 'test-group',
            'stream' => 'test-stream',
            'name' => 'test',
        ], $overrides);
    }
}
