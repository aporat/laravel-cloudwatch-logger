<?php

/**
 * Configuration for the Laravel CloudWatch Logger package.
 *
 * This file defines settings for integrating CloudWatch logging with Laravel’s
 * Monolog system. It can be merged into config/logging.php under 'channels'.
 *
 * @see https://github.com/aporat/laravel-cloudwatch-logger
 */

return [
    /*
    |--------------------------------------------------------------------------
    | CloudWatch Logging Channel
    |--------------------------------------------------------------------------
    |
    | Configuration for the CloudWatch logging channel, including AWS credentials,
    | log group, stream, and formatting options.
    |
    */
    'cloudwatch' => [
        'driver' => 'custom',
        'via' => \Aporat\CloudWatchLogger\CloudWatchLoggerFactory::class,
        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => env('AWS_VERSION', 'latest'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID', ''),
                'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            ],
        ],
        'group' => env('CLOUDWATCH_LOG_GROUP_NAME', env('APP_NAME', 'laravel').'-'.env('APP_ENV', 'production')),
        'stream' => env('CLOUDWATCH_LOG_STREAM', 'default'),
        'name' => env('CLOUDWATCH_LOG_NAME', env('APP_NAME', 'laravel')),
        'retention' => env('CLOUDWATCH_LOG_RETENTION', 14),
        'level' => env('CLOUDWATCH_LOG_LEVEL', \Monolog\Level::Error->value),
        'batch_size' => env('CLOUDWATCH_LOG_BATCH_SIZE', 10000)
    ],
];
