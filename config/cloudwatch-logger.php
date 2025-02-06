<?php

use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;

return [
    'cloudwatch' => [
        'driver' => 'custom',
        'via'    => CloudWatchLoggerFactory::class,
        'aws'    => [
            'region'      => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version'     => 'latest',
            'credentials' => [
                'key'    => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ],
        'group'     => env('CLOUDWATCH_LOG_GROUP_NAME', env('APP_NAME').'-'.env('APP_ENV')),
        'stream'    => env('CLOUDWATCH_LOG_STREAM', 'default'),
        'name'      => env('CLOUDWATCH_LOG_NAME', ''),
        'retention' => env('CLOUDWATCH_LOG_RETENTION', 7),
        'level'     => Level::Error,
        'formatter' => function ($configs) {
            return new LineFormatter(
                '%channel%: %level_name%: %message% %context% %extra%',
                null,
                false,
                true
            );
        },
    ],
];
