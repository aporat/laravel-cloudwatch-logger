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
- String, class, instance and callable formatters, with Laravel's
  `formatter_with`; `handler` / `handler_with` like Laravel's own channels.
- Stream name placeholders: `{hostname}`, `{pid}`, `{env}` and `{date}`
  (daily streams that roll over at midnight UTC, even in long-running
  workers).
- Optional enforcement of retention and tags on log groups that already exist.
- A `fallback_channel` that receives the records CloudWatch could not take.
- `php artisan cloudwatch:test` to check configuration, credentials,
  permissions and delivery in one go.
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

- **PHP**: `^8.3`
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
| `stream` | string | — | Log stream within the group. No `:` or `*`; max 512 chars. Supports [placeholders](#stream-name-placeholders). |
| `stream_date_format` | string | `Y-m-d` | PHP `date()` format for `{date}`, evaluated in UTC. |
| `name` | string | — | Monolog channel name, used by `%channel%`. |
| `level` | string\|int\|`Level` | `debug` | Minimum level. Accepts a `Monolog\Level`, a severity number, or a level name. |
| `formatter` | string\|class\|instance\|callable | `LineFormatter` | See [Formatters](#formatters). |
| `formatter_with` | array | `[]` | Constructor arguments for a `formatter` class, as in Laravel. |
| `handler` | class | `CloudWatchHandler` | A `CloudWatchHandler` subclass to build instead. See [Customising the handler](#customising-the-handler). |
| `handler_with` | array | `[]` | Named `CloudWatchHandler` constructor arguments that override the computed ones. |
| `replace_placeholders` | bool | `false` | Interpolate `{placeholders}` from the context, as Laravel's other channels do. |
| `retention` | int\|null | `14` | Days to retain events, or `null` to never expire. Must be one of CloudWatch's accepted periods. Applied when this package creates the group, or to an existing group with `enforce_group_settings`. |
| `batch_size` | int | `10000` | Buffered events that trigger a send. 1–10000. |
| `flush_interval` | int | `10` | Seconds; a write flushes the buffer once its oldest event is this old. `0` disables. |
| `max_buffer_size` | int | `10000` (or `batch_size` if larger) | Hard cap on buffered events. Beyond it the oldest are dropped and counted. Must be ≥ `batch_size`. |
| `rps_limit` | int | `0` | Max `PutLogEvents` calls per second from one process; `0` disables. |
| `tags` | array<string,string> | `[]` | Tags applied when the group is created, or to an existing group with `enforce_group_settings`. |
| `enforce_group_settings` | bool | `false` | Also apply `retention` and `tags` to a group that already exists. See [Existing log groups](#existing-log-groups). |
| `bubble` | bool | `true` | Whether records continue to other handlers. |
| `create_group` | bool | `true` | Create the log group if missing. |
| `create_stream` | bool | `true` | Create the log stream if missing. |
| `cache` | bool\|string\|class\|pool | `false` | See [Avoiding the per-process describe calls](#avoiding-the-per-process-describe-calls). |
| `cache_ttl` | int | `300` | Lifetime, in seconds, of a cached "group/stream exists" (or "settings applied") marker. |
| `suppress_failures` | bool | `true` | Report CloudWatch failures with `error_log()` instead of throwing out of the log call. |
| `circuit_breaker` | int | `30` | Seconds to stop sending after a failed request; `0` disables. |
| `fallback_channel` | string\|null | `null` | A log channel that receives records CloudWatch could not take. See [Fallback channel](#fallback-channel). |

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
order, spanning less than 24 hours. A single event can be up to 1 MB
(1,048,576 bytes including the 26-byte overhead; CloudWatch raised this from
256 KB in 2025), so a message larger than 1,048,550 bytes is split into several
events on UTF-8 character boundaries; invalid UTF-8 is scrubbed. Timestamps
are sent as integer milliseconds. No sequence tokens are used: CloudWatch has
ignored them since January 2023.

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

`rps_limit` paces one process only. CloudWatch's `PutLogEvents` quota is per
account and region (5,000 requests/second by default, adjustable); the old
5-requests-per-second-per-stream limit was removed in January 2023, so many
processes can write to the same stream.

### Fallback channel

`fallback_channel` names another Laravel log channel (for example `single`,
`daily` or `stderr`) that receives the records CloudWatch could not take:

- the oldest records dropped when the buffer overflows `max_buffer_size`
  during an outage;
- records CloudWatch rejected with a non-retryable error;
- records still undelivered when the handler closes (end of request or
  process, Octane worker stop) — typically because the circuit breaker is
  open or the last attempt failed.

```php
'cloudwatch' => [
    // ...
    'fallback_channel' => 'daily',
],
```

While the circuit is open records stay buffered and are retried, so nothing is
written twice: a record goes to the fallback only once CloudWatch has
definitely not received it. Records keep their level, message, context and
time; the reason is added to the context as `cloudwatch_fallback` (`buffer
full`, `rejected by CloudWatch` or `undelivered when the handler closed`).

The fallback must not write to CloudWatch. A channel using this package's
factory (or a stack containing one) is refused when the channel is built, and
at runtime a fallback logger that holds a `CloudWatchHandler` is refused and
reported. While records are being forwarded the CloudWatch handler ignores
anything written to it, so even a misconfigured fallback cannot loop.

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

## Stream name placeholders

`stream` may contain:

| Placeholder | Value | Resolved |
| --- | --- | --- |
| `{hostname}` | `gethostname()` | when the channel is built |
| `{pid}` | `getmypid()` | when the channel is built |
| `{env}` | the application environment (`app.env`) | when the channel is built |
| `{date}` | the event's date in UTC, formatted with `stream_date_format` (default `Y-m-d`) | per event |

```php
'stream' => '{env}-{hostname}-{date}',   // production-web-1-2026-10-10
'stream_date_format' => 'Y-m-d',
```

`{date}` is taken from each record's own timestamp, in UTC, not from when the
channel was built. A queue worker or Octane server that runs past midnight UTC
therefore starts writing to the next day's stream with the first record of the
new day, and records buffered across midnight go to their own day's stream.
The new stream is created (or found) on the first flush that needs it. Other
`{…}` text is left as is. The expanded name is validated like any stream name,
so a format that produces `:` (such as `H:i`) is rejected up front.

`{pid}` gives every PHP-FPM worker its own stream; combine it with care, since
each new stream costs a `CreateLogStream` call.

## Existing log groups

By default `retention` and `tags` are only applied when the package creates
the log group. Set `enforce_group_settings` to `true` to apply them to a group
that already exists:

- retention with `PutRetentionPolicy`, only when it differs (`null` retention
  is never enforced: the package does not remove an existing policy);
- tags with `TagResource` on the group ARN (existing tags with other keys are
  kept).

This runs on the first flush of each process — or once per `cache_ttl` when
`cache` is set; the cache entry includes the desired retention and tags, so
changing them re-applies them. A failure (for example a missing
`logs:PutRetentionPolicy` or `logs:TagResource` permission) is reported with
`error_log()` and never blocks logging; it is retried by the next process.

## Testing the connection

```bash
php artisan cloudwatch:test             # the default channel if it uses CloudWatch, else "cloudwatch"
php artisan cloudwatch:test audit       # a specific channel
php artisan cloudwatch:test --dry-run   # read-only: nothing is created or sent
```

The command validates the channel configuration, resolves AWS credentials,
checks (and, unless `--dry-run`, creates) the log group and stream, applies
group settings when `enforce_group_settings` is on, and sends one test event.
It reports each step with a result and timing and exits non-zero if any step
fails. Secrets are never printed; only the last four characters of the access
key ID are shown.

```
Testing log channel cloudwatch
+-----------------+--------+---------+-------------------------------------------------------+
| Step            | Result | Time    | Details                                               |
+-----------------+--------+---------+-------------------------------------------------------+
| Configuration   | PASS   | 1.4 ms  | region us-east-1, group my-app, stream production-web |
| Credentials     | PASS   | 40.3 ms | access key ID ****MPLE, from the channel config       |
| Log group       | PASS   | 4.6 ms  | created with 14-day retention                         |
| Log stream      | PASS   | 0.3 ms  | production-web created                                |
| Send test event | PASS   | 0.2 ms  | event accepted by my-app/production-web               |
+-----------------+--------+---------+-------------------------------------------------------+
CloudWatch test passed: a test event was delivered.
```

A missing permission is named in the failing step (for example
`access denied: grant logs:DescribeLogGroups`) and the steps that depend on
it are skipped.

## IAM permissions

What the package calls depends on the options:

| Action | Needed for |
| --- | --- |
| `logs:PutLogEvents` | always |
| `logs:DescribeLogStreams`, `logs:CreateLogStream` | `create_stream` (default) |
| `logs:DescribeLogGroups`, `logs:CreateLogGroup` | `create_group` (default) |
| `logs:PutRetentionPolicy` | `retention` on a group the package creates, or `enforce_group_settings` |
| `logs:TagResource` | `tags` on a group the package creates (tagging at creation needs it), or `enforce_group_settings` |
| `logs:DescribeLogGroups` | `enforce_group_settings`, and `cloudwatch:test` |

`DescribeLogGroups` does not support resource-level permissions, so it needs
`"Resource": "*"`. Group-level actions use the log group ARN
(`arn:aws:logs:REGION:ACCOUNT:log-group:NAME`); stream-level actions
(`CreateLogStream`, `PutLogEvents`) use the stream ARN
(`…:log-group:NAME:log-stream:STREAM`). `DescribeLogGroups` and many existing
policies still use the older log group form with a trailing `:*`, so the
examples list both.

Sending only, with the group and stream provisioned elsewhere
(`create_group` and `create_stream` set to `false`):

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "SendLogEvents",
            "Effect": "Allow",
            "Action": "logs:PutLogEvents",
            "Resource": "arn:aws:logs:us-east-1:123456789012:log-group:my-app-production:log-stream:*"
        }
    ]
}
```

Everything: creating the group and streams, retention, tags and
`enforce_group_settings`:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "FindLogGroups",
            "Effect": "Allow",
            "Action": "logs:DescribeLogGroups",
            "Resource": "*"
        },
        {
            "Sid": "ManageLogGroup",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogStreams",
                "logs:PutRetentionPolicy",
                "logs:TagResource"
            ],
            "Resource": [
                "arn:aws:logs:us-east-1:123456789012:log-group:my-app-production",
                "arn:aws:logs:us-east-1:123456789012:log-group:my-app-production:*"
            ]
        },
        {
            "Sid": "WriteLogStreams",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogStream",
                "logs:PutLogEvents"
            ],
            "Resource": "arn:aws:logs:us-east-1:123456789012:log-group:my-app-production:log-stream:*"
        }
    ]
}
```

Drop `logs:PutRetentionPolicy` / `logs:TagResource` if you use neither
`retention` nor `tags`, and `logs:CreateLogGroup` with `create_group` off.

Scoping examples:

- every environment of one app: `arn:aws:logs:us-east-1:123456789012:log-group:my-app-*`
  (and `…:log-group:my-app-*:log-stream:*` for the stream statement);
- any region: `arn:aws:logs:*:123456789012:log-group:my-app-production`;
- one stream only: `…:log-group:my-app-production:log-stream:web-*`;
- only allow the tags you expect when creating or tagging a group: add
  `"Condition": {"ForAllValues:StringEquals": {"aws:TagKeys": ["team", "service"]}}`
  to the statement with `logs:CreateLogGroup` / `logs:TagResource`.

Action names and resource types are as listed for CloudWatch Logs in the AWS
[Service Authorization Reference](https://docs.aws.amazon.com/service-authorization/latest/reference/list_amazoncloudwatchlogs.html).

## Failure handling

By default a log write never throws because of CloudWatch (see
[When CloudWatch fails](#when-cloudwatch-fails)). Set
`'suppress_failures' => false` to have a failed send throw out of the log
call instead — note that in a request path that logs its own errors, or in
Laravel's exception handler, that exception replaces the one being reported.

To keep the records CloudWatch could not take, set a
[`fallback_channel`](#fallback-channel). For a local copy of everything, use a
Laravel `stack` channel:

```php
'app' => [
    'driver' => 'stack',
    'channels' => ['cloudwatch', 'single'],
],
```

## Formatters

`formatter` accepts these shapes:

```php
// 1. A LineFormatter template.
'formatter' => '%channel%: %level_name%: %message% %context% %extra%',

// 2. A FormatterInterface class name, resolved through the container, with
//    optional constructor arguments (Laravel's `formatter_with`).
'formatter' => Monolog\Formatter\JsonFormatter::class,
'formatter_with' => ['includeStacktraces' => true],

// 'default' (or no formatter): the package's LineFormatter.
'formatter' => 'default',

// 3. An instance.
'formatter' => new Monolog\Formatter\JsonFormatter,

// 4. A callable receiving the channel config.
'formatter' => fn (array $config) => new Monolog\Formatter\JsonFormatter,
```

Forms 3 and 4 (and a cache pool instance) cannot be serialized by
`php artisan config:cache`; use a class name (with `formatter_with`) if you
cache your configuration.

`formatter_with` follows Laravel: named arguments are passed to the container
(`app()->make($class, $with)`); a positional list is passed to the
constructor in order. It is an error to set it without a formatter class.

## Customising the handler

Like Laravel's `monolog` driver, `handler_with` passes named constructor
arguments to the handler. They override the values computed from the rest of
the config, which makes the less common `CloudWatchHandler` arguments
reachable while keeping the config cacheable:

```php
'handler_with' => ['bubble' => false],
```

`handler` may name a `CloudWatchHandler` subclass. It is resolved through the
container with the computed arguments (plus `handler_with`), so it can add its
own dependencies:

```php
'handler' => App\Logging\AuditCloudWatchHandler::class,
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
there if a channel goes quiet after a config change, or run
`php artisan cloudwatch:test`, which reports the error directly.

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
- **Requirements.** PHP `^8.3` (was `^8.4`) and `psr/cache` `^3.0`
  (`^2.0` dropped); `ext-json` is no longer listed (it is always available).
- **More new options:** `formatter_with`, `handler`, `handler_with`,
  `enforce_group_settings`, `stream_date_format`, `fallback_channel`, and
  `{hostname}`/`{pid}`/`{env}`/`{date}` placeholders in `stream`. A stream
  name that already contains one of these placeholders literally will now be
  expanded.

## Testing

```bash
composer test      # phpunit
composer lint      # pint --test + phpstan (level 8)
composer format    # pint (fixes formatting)
composer ci        # lint, then tests with coverage and a JUnit report
```

CI runs the suite on PHP 8.3, 8.4 and 8.5 against Laravel 12 and 13, plus the
lowest dependency versions `composer.json` allows.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
