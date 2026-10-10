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
- Bounded, self-healing buffering: requests are split to respect every
  `PutLogEvents` limit, the buffer is capped, and an outage never leaves it
  stuck.
- Failures don't escape: by default a CloudWatch outage is reported with
  `error_log()` instead of throwing out of `Log::…()`, and a circuit breaker
  stops a dead endpoint from slowing every request.
- Buffered events are flushed at shutdown, after every queue job, and after
  every Octane request, task and tick.
- Configuration is validated up front: a bad value fails with a message naming
  the key, not with an opaque AWS error on the first log line.

## Requirements

- **PHP**: `^8.4`
- **Laravel**: `^12.0` || `^13.0`
- **ext-mbstring**

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
| `batch_size` | int | `10000` | Buffered events that trigger a send. 1–10000. |
| `flush_interval` | int | `10` | Seconds; a write flushes the buffer once its oldest event is this old. `0` disables. |
| `max_buffer_size` | int | `10000` (or `batch_size` if larger) | Hard cap on buffered events. Beyond it the oldest are dropped and counted. Must be ≥ `batch_size`. |
| `rps_limit` | int | `0` | Max `PutLogEvents` calls per second from one process; `0` disables. |
| `tags` | array<string,string> | `[]` | Tags applied when the group is created. |
| `bubble` | bool | `true` | Whether records continue to other handlers. |
| `create_group` | bool | `true` | Create the log group if missing. |
| `create_stream` | bool | `true` | Create the log stream if missing. |
| `cache` | bool\|string\|class\|pool | `false` | See [Avoiding the per-process describe calls](#avoiding-the-per-process-describe-calls). |
| `cache_ttl` | int | `300` | Lifetime, in seconds, of a cached "group/stream exists" marker. |
| `suppress_failures` | bool | `true` | Report CloudWatch failures with `error_log()` instead of throwing out of the log call. |
| `circuit_breaker` | int | `30` | Seconds to stop sending after a failed request; `0` disables. |

Every scalar option also accepts the string form `env()` produces, so
`CLOUDWATCH_LOG_BATCH_SIZE=25` and `'batch_size' => 25` behave identically.

### AWS client defaults

Unless you set them in `aws`, the package adds:

```php
'http' => ['connect_timeout' => 1, 'timeout' => 3],   // merged under your own 'http' keys
'retries' => 1,                                        // one retry, two attempts in total
```

so an unreachable or black-holed endpoint costs a few seconds once (then the
circuit breaker takes over) instead of minutes per log call. Any value you set
wins. Prefer an **integer** `retries`: the SDK passes the client's `retries`
to its default credential provider, and off EC2 with no other credentials an
array value (`['mode' => 'standard', …]`) makes it retry the instance metadata
service indefinitely.

## Buffering and delivery

Records are buffered in memory and sent with `PutLogEvents` when any of these
happens:

- the buffer reaches `batch_size` events;
- a record is written and the oldest buffered event is older than
  `flush_interval` seconds;
- a queue job finishes (`JobProcessed`, `JobFailed`), the worker loops
  (`Looping`) or stops (`WorkerStopping`);
- an Octane request, task or tick ends (`RequestTerminated`,
  `TaskTerminated`, `TickTerminated`), when Octane is installed; Octane's own
  `FlushMonologState` reset also flushes;
- the logger is closed or reset (`Log::channel('cloudwatch')->getLogger()->close()`)
  or the process ends.

`flush_interval` is checked on write: a long-running process that does not use
the queue worker or Octane (a custom daemon loop, for example) should call
`close()` or `reset()` on the logger at the end of each unit of work.

Every request respects the API limits whatever `batch_size` is: at most 10,000
events and 1,048,576 bytes (message bytes + 26 per event), in chronological
order, spanning less than 24 hours. A message larger than 1,048,550 bytes is
split into several events on UTF-8 character boundaries; invalid UTF-8 is
scrubbed. Timestamps are sent as integer milliseconds.

### When CloudWatch fails

- **Transient errors** (connection failures, timeouts, throttling, 5xx,
  credential lookup failures): the unsent events stay buffered for the next
  attempt.
- **Permanent errors** (for example `InvalidParameterException`,
  `AccessDeniedException`, `ResourceNotFoundException`): the rejected batch is
  dropped and counted, never retried.
- Either way the **circuit breaker** opens for `circuit_breaker` seconds:
  nothing is sent (including at shutdown) and new records are only buffered.
- The buffer never holds more than `max_buffer_size` events; when full, the
  **oldest** events are dropped and counted, so the most recent ones survive
  an outage.
- With `suppress_failures` (the default), failures and drop counts are written
  with PHP's `error_log()` as `[cloudwatch-logger] group/stream: …` lines. They
  are never sent through a Laravel logger, so a broken CloudWatch channel
  cannot recurse into itself through the exception handler.

`CloudWatchHandler::getDroppedEventCount()`, `getBufferedEventCount()` and
`isCircuitOpen()` expose this state.

`rps_limit` paces one process only; CloudWatch's `PutLogEvents` quota is per
account and region (5,000 requests/second by default) — there is no longer a
per-stream limit.

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

Cache keys identify the group and the group/stream pair separately, so a
stream named like its group, or two groups sharing a stream name, never share
an entry, and any PSR-6 pool accepts them. Cache failures degrade to a miss —
the handler just asks CloudWatch again.

If the group and stream are provisioned outside the application (Terraform,
CloudFormation), set `create_group` and `create_stream` to `false` instead and
skip the lookups entirely.

## Failure handling

By default a log write never throws because of CloudWatch (see
[When CloudWatch fails](#when-cloudwatch-fails)). Set
`'suppress_failures' => false` to have a failed send throw out of the log
call instead — note that in a request path that logs its own errors, or in
Laravel's exception handler, that exception replaces the one being reported.

For a local copy as well, use a Laravel `stack` channel:

```php
'app' => [
    'driver' => 'stack',
    'channels' => ['cloudwatch', 'single'],
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

Forms 3 and 4 (and a cache pool instance) cannot be serialized by
`php artisan config:cache`; use a class name if you cache your configuration.

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

## Upgrading from 3.x

4.0 replaces the `phpnexus/cwh` handler with the package's own
`Aporat\CloudWatchLogger\Handler\CloudWatchHandler`.

- **`suppress_failures` now defaults to `true`.** CloudWatch failures are
  written with `error_log()` instead of being thrown. Set it to `false` to
  keep the 3.x behaviour.
- **The handler class changed.** Code that inspects
  `PhpNexus\Cwh\Handler\CloudWatch` (for example in a `tap`) must use
  `CloudWatchHandler`. With `suppress_failures` the handler is no longer
  wrapped in Monolog's `WhatFailureGroupHandler`, so `bubble` is now honoured.
- **`phpnexus/cwh` is no longer a dependency.** Require it yourself if you use
  it directly. `ext-mbstring` is now required.
- **AWS client defaults.** `http.connect_timeout` 1s, `http.timeout` 3s and
  `retries` 1 are applied unless you set them. Raise `timeout` if you send
  very large batches over a slow link.
- **No in-handler sleep-and-retry.** cwh slept a second and retried any failed
  request once; failures now go through the SDK's own retries and the circuit
  breaker.
- **New options:** `flush_interval` (10s), `max_buffer_size` (10000),
  `circuit_breaker` (30s). If you publish the config, copy them from the new
  `config/cloudwatch-logger.php`.
- **Delivery timing.** Buffers are now flushed after every queue job and
  Octane operation and after `flush_interval`, so expect more, smaller
  `PutLogEvents` calls from workers.
- **Cache keys changed.** Existing "group/stream exists" cache entries are
  ignored; the first write of each process after upgrading repeats the
  describe calls once.

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
