<?php

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;

/**
 * Configuration for the Laravel CloudWatch Logger package.
 *
 * This array is a complete Laravel logging channel definition. The package
 * registers it as the `cloudwatch` channel automatically unless you define a
 * channel by that name yourself in config/logging.php.
 *
 * @see https://github.com/aporat/laravel-cloudwatch-logger
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Laravel resolves the factory below out of the container and calls it with
    | this array to build the channel's Monolog logger.
    |
    */
    'driver' => 'custom',
    'via' => CloudWatchLoggerFactory::class,

    /*
    |--------------------------------------------------------------------------
    | AWS Client
    |--------------------------------------------------------------------------
    |
    | Passed straight to the AWS CloudWatchLogsClient constructor. Leave both
    | credential values empty to fall back to the AWS default credential chain
    | (IAM role, environment, ~/.aws/credentials) — the empty block is dropped
    | before the client is built.
    |
    */
    'aws' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'version' => env('AWS_VERSION', 'latest'),
        'credentials' => [
            'key' => env('AWS_ACCESS_KEY_ID', ''),
            'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Destination
    |--------------------------------------------------------------------------
    |
    | 'group'  — the CloudWatch log group. Letters, digits, '_', '-', '/', '.', '#'.
    | 'stream' — the stream within that group. May not contain ':' or '*'.
    |            Placeholders: {hostname}, {pid}, {env} (resolved when the
    |            channel is built) and {date} (the UTC date of each event, in
    |            'stream_date_format'), e.g. 'app-{env}-{hostname}-{date}'.
    |            With {date}, a long-running worker moves to the next day's
    |            stream at midnight UTC.
    | 'name'   — the Monolog channel name, used by the %channel% placeholder.
    |
    */
    'group' => env('CLOUDWATCH_LOG_GROUP_NAME', env('APP_NAME', 'laravel').'-'.env('APP_ENV', 'production')),
    'stream' => env('CLOUDWATCH_LOG_STREAM', 'default'),
    'name' => env('CLOUDWATCH_LOG_NAME', env('APP_NAME', 'laravel')),
    'stream_date_format' => env('CLOUDWATCH_LOG_STREAM_DATE_FORMAT', 'Y-m-d'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Applied when this package creates the log group, and to an existing group
    | when 'enforce_group_settings' is true. Must be null (never
    | expire) or one of the periods CloudWatch accepts: 1, 3, 5, 7, 14, 30, 60,
    | 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922,
    | 3288, 3653.
    |
    */
    'retention' => env('CLOUDWATCH_LOG_RETENTION', 14),

    /*
    |--------------------------------------------------------------------------
    | Level & Formatting
    |--------------------------------------------------------------------------
    |
    | 'formatter' accepts a LineFormatter template string, a FormatterInterface
    | class name, 'default', an instance, or a callable receiving this config
    | array. 'formatter_with' holds constructor arguments for a formatter class,
    | as in Laravel's own channels, e.g.
    |     'formatter' => Monolog\Formatter\JsonFormatter::class,
    |     'formatter_with' => ['includeStacktraces' => true],
    | 'handler_with' likewise overrides CloudWatchHandler constructor
    | arguments, and 'handler' may name a CloudWatchHandler subclass.
    |
    */
    'level' => env('CLOUDWATCH_LOG_LEVEL', 'error'),
    'formatter' => env('CLOUDWATCH_LOG_FORMAT', '%channel%: %level_name%: %message% %context% %extra%'),
    'replace_placeholders' => env('CLOUDWATCH_LOG_REPLACE_PLACEHOLDERS', false),
    'formatter_with' => [],

    /*
    |--------------------------------------------------------------------------
    | Batching & Throttling
    |--------------------------------------------------------------------------
    |
    | 'batch_size'      — buffered events that trigger a PutLogEvents call
    |                     (1–10000). Requests are split to respect the API
    |                     limits whatever this is set to.
    | 'flush_interval'  — seconds; a write flushes the buffer once its oldest
    |                     event is this old. 0 disables.
    | 'max_buffer_size' — hard cap on buffered events. While CloudWatch is
    |                     unavailable the oldest events beyond it are dropped
    |                     (and counted). Must be >= batch_size.
    | 'rps_limit'       — max PutLogEvents calls per second from one process;
    |                     0 disables.
    |
    | The buffer is also flushed when the process ends, after every queue job
    | and after every Octane request/task/tick.
    |
    */
    'batch_size' => env('CLOUDWATCH_LOG_BATCH_SIZE', 10000),
    'flush_interval' => env('CLOUDWATCH_LOG_FLUSH_INTERVAL', 10),
    'max_buffer_size' => env('CLOUDWATCH_LOG_MAX_BUFFER_SIZE', 10000),
    'rps_limit' => env('CLOUDWATCH_LOG_RPS_LIMIT', 0),

    /*
    |--------------------------------------------------------------------------
    | Group & Stream Creation
    |--------------------------------------------------------------------------
    |
    | By default the handler checks for (and creates) the group and stream on
    | the first write of every process, costing a DescribeLogGroups and a
    | DescribeLogStreams call each time. Set 'cache' to true to remember the
    | result in the application's default cache store, to a store name to pick
    | another, or to a PSR-6 CacheItemPoolInterface class name or instance.
    | Turn the creation flags off entirely if the group and stream are
    | provisioned outside the application.
    |
    */
    'create_group' => env('CLOUDWATCH_LOG_CREATE_GROUP', true),
    'create_stream' => env('CLOUDWATCH_LOG_CREATE_STREAM', true),
    'cache' => env('CLOUDWATCH_LOG_CACHE', false),
    'cache_ttl' => env('CLOUDWATCH_LOG_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Existing Groups
    |--------------------------------------------------------------------------
    |
    | When true, 'retention' and 'tags' are also applied to a log group that
    | already exists (PutRetentionPolicy and TagResource; needs those IAM
    | permissions). It runs once per process, or once per 'cache_ttl' with a
    | cache; failures are reported and never stop logging.
    |
    */
    'enforce_group_settings' => env('CLOUDWATCH_LOG_ENFORCE_GROUP_SETTINGS', false),

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | Applied when this package creates the log group, and to an existing group
    | when 'enforce_group_settings' is true.
    |
    */
    'tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Failure Handling
    |--------------------------------------------------------------------------
    |
    | 'suppress_failures' — when true (the default), a CloudWatch failure never
    |     throws out of a Log call; it is reported with PHP's error_log()
    |     instead. Set to false to have failed sends throw.
    | 'circuit_breaker'   — seconds to stop sending after a failure. Records
    |     logged meanwhile stay buffered (up to max_buffer_size). 0 disables.
    | 'fallback_channel'  — another log channel (e.g. 'single' or 'stderr')
    |     that receives the records CloudWatch could not take: those dropped
    |     when the buffer overflows, rejected by CloudWatch, or still
    |     undelivered when the process ends. It must not be a CloudWatch
    |     channel.
    |
    */
    'suppress_failures' => env('CLOUDWATCH_LOG_SUPPRESS_FAILURES', true),
    'circuit_breaker' => env('CLOUDWATCH_LOG_CIRCUIT_BREAKER', 30),
    'fallback_channel' => env('CLOUDWATCH_LOG_FALLBACK_CHANNEL'),
];
