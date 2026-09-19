# Laravel CloudWatch Logger

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Codecov](https://img.shields.io/codecov/c/github/aporat/laravel-cloudwatch-logger?style=flat-square)](https://codecov.io/github/aporat/laravel-cloudwatch-logger)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20|%2013.x-orange.svg?style=flat-square)](https://laravel.com)
![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/aporat/laravel-cloudwatch-logger/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-cloudwatch-logger.svg?style=flat-square)](LICENSE)

A Laravel logging driver for AWS CloudWatch Logs.

## Features

- A custom Monolog channel that writes to CloudWatch Logs.
- Configurable AWS client, log group, stream, retention, batching and throttling.
- String, class, instance and callable formatters.
- Optional caching of group/stream existence, so a busy app isn't paying two
  extra AWS calls per process.
- Optional failure suppression, so an unreachable CloudWatch can't turn a
  handled error into a 500.
- Configuration is validated up front: a bad value fails with a message naming
  the key, not with an opaque AWS error on the first log line.

## Requirements

- **PHP**: `^8.4`
- **Laravel**: `^12.0` || `^13.0`
- **phpnexus/cwh**: `^3.2` — the handler options this package exposes
  (`create_stream`, `rps_limit`, a PSR-6 cache pool and a null retention)
  only exist from 3.2 onwards.

## Installation

```bash
composer require aporat/laravel-cloudwatch-logger
```

The service provider is auto-discovered. It registers a `cloudwatch` logging
channel from the package defaults, so this already works:

```php
Log::channel('cloudwatch')->error('Something broke');
```

To customise it, publish the config:

```bash
php artisan vendor:publish --provider="Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider" --tag="config"
```

The published `config/cloudwatch-logger.php` is a complete channel definition.
If you define a `cloudwatch` channel yourself in `config/logging.php`, yours
wins and the package leaves it alone.

## Defining channels

A channel is a normal Laravel `custom` driver entry:

```php
// config/logging.php
use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;

'channels' => [
    'cloudwatch' => [
        'driver' => 'custom',
        'via' => CloudWatchLoggerFactory::class,

        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => 'latest',
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID', ''),
                'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            ],
        ],

        'group' => env('CLOUDWATCH_LOG_GROUP_NAME', 'my-app-production'),
        'stream' => 'errors',
        'name' => 'my-app',
        'level' => 'error',
        'retention' => 14,
        'formatter' => '%channel%: %level_name%: %message% %context% %extra%',
    ],
],
```

Leave both credential values empty to use the AWS default credential chain
(IAM task/instance role, environment variables, `~/.aws/credentials`). The
empty block is removed before the client is built, so the SDK doesn't reject it.

## Options

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `aws` | array | — | Passed to the `CloudWatchLogsClient` constructor. `region` and `version` are required. |
| `group` | string | — | Log group. Letters, digits, `_`, `-`, `/`, `.`, `#`; max 512 chars. |
| `stream` | string | — | Log stream within the group. No `:` or `*`; max 512 chars. |
| `name` | string | — | Monolog channel name, used by `%channel%`. |
| `level` | string\|int\|`Level` | `debug` | Minimum level. Accepts a `Monolog\Level`, a severity number, or a level name. |
| `formatter` | string\|class\|instance\|callable | `LineFormatter` | See [Formatters](#formatters). |
| `replace_placeholders` | bool | `false` | Interpolate `{placeholders}` from the context, as Laravel's other channels do. |
| `retention` | int\|null | `14` | Days to retain events, or `null` to never expire. Must be one of CloudWatch's accepted periods. Applied only when this package creates the group. |
| `batch_size` | int | `10000` | Events buffered before a `PutLogEvents` call. 1–10000. |
| `rps_limit` | int | `0` | Self-throttle to this many requests per second; `0` disables. |
| `tags` | array<string,string> | `[]` | Tags applied when the group is created. |
| `bubble` | bool | `true` | Whether records continue to other handlers. |
| `create_group` | bool | `true` | Create the log group if missing. |
| `create_stream` | bool | `true` | Create the log stream if missing. |
| `cache` | bool\|string\|class\|pool | `false` | See [Avoiding the per-process describe calls](#avoiding-the-per-process-describe-calls). |
| `cache_ttl` | int | `300` | Lifetime, in seconds, of a cached "group/stream exists" marker. |
| `suppress_failures` | bool | `false` | Drop the log line instead of throwing when CloudWatch is unreachable. |

Every one of these also accepts the string form `env()` produces, so
`CLOUDWATCH_LOG_BATCH_SIZE=25` and `'batch_size' => 25` behave identically.

## Choosing a batch size

`batch_size` buffers events in memory and sends them in one `PutLogEvents`
call. The right value depends on how long the process lives:

- **Queue workers, Horizon, long-running CLI** — keep it low (often `1`).
  A logger is cached for the life of the process, so a half-full buffer sits in
  memory until the next log line arrives, which may be minutes later.
- **HTTP requests** — a larger batch is fine; the handler flushes when the
  request ends.

CloudWatch throttles `PutLogEvents` per stream. When it does, the handler
sleeps for a second and retries, which blocks the process. If a stream is busy
enough to hit that, set `rps_limit` so the handler paces itself instead.

## Avoiding the per-process describe calls

By default the handler issues a `DescribeLogGroups` and a `DescribeLogStreams`
call on the first write of every process, for every channel, to find out
whether it needs to create them. Set `cache` to remember the answer:

```php
'cache' => true,        // the application's default cache store
'cache' => 'redis',     // a specific store from config/cache.php
'cache' => App\MyPool::class,   // any PSR-6 CacheItemPoolInterface
'cache' => $poolInstance,
'cache_ttl' => 300,
```

Entries are namespaced per log group, so two channels writing to a same-named
stream in different groups don't collide. Cache failures degrade to a miss —
the handler just asks CloudWatch again.

If the group and stream are provisioned outside the application (Terraform,
CloudFormation), set `create_group` and `create_stream` to `false` instead and
skip the lookups entirely.

## Failure handling

A log write that can't reach CloudWatch throws. In a request path that logs its
own errors, that turns a handled failure into a 500. Set
`'suppress_failures' => true` to wrap the handler in Monolog's
`WhatFailureGroupHandler`, which drops the record instead.

For a local fallback rather than a silent drop, use a Laravel `stack` channel:

```php
'app' => [
    'driver' => 'stack',
    'channels' => ['cloudwatch', 'single'],
    'ignore_exceptions' => true,
],
```

## Formatters

`formatter` accepts four shapes:

```php
// 1. A LineFormatter template.
'formatter' => '%channel%: %level_name%: %message% %context% %extra%',

// 2. A FormatterInterface class name, resolved through the container.
'formatter' => Monolog\Formatter\JsonFormatter::class,

// 3. An instance.
'formatter' => new Monolog\Formatter\JsonFormatter,

// 4. A callable receiving the channel config.
'formatter' => fn (array $config) => new Monolog\Formatter\JsonFormatter,
```

A string that looks like a class name but doesn't resolve to a
`FormatterInterface` is rejected, rather than being silently used as a format
template.

## Configuration errors

Invalid configuration throws
`Aporat\CloudWatchLogger\Exceptions\IncompleteCloudWatchConfig` while the
channel is being built.

Note that Laravel's `LogManager` catches anything a channel factory throws and
substitutes its emergency file logger (`storage/logs/laravel.log`). So a
misconfigured channel does not raise at the call site — it stops reaching
CloudWatch, and the reason is written to the emergency log. Worth checking
there if a channel goes quiet after a config change.

## Testing

```bash
composer test      # phpunit
composer lint      # pint + phpstan (level 8)
composer ci        # both, with coverage
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
