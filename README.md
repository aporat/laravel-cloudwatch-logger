# Laravel Logger for AWS CloudWatch

[![codecov](https://codecov.io/github/aporat/laravel-filter-var/graph/badge.svg?token=VPCAXPZUBP)](https://codecov.io/github/aporat/laravel-filter-var)
[![StyleCI](https://github.styleci.io/repos/288753189/shield?branch=master)](https://github.styleci.io/repos/288753189?branch=master)
[![Latest Version](http://img.shields.io/packagist/v/aporat/laravel-filter-var.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-filter-var)
[![Latest Dev Version](https://img.shields.io/packagist/vpre/aporat/laravel-filter-var.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-filter-var#dev-develop)
[![Monthly Downloads](https://img.shields.io/packagist/dm/aporat/laravel-filter-var.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-filter-var)


Laravel logger factory for AWS Cloudwatch Logs service.

## Installation

The filter-var service provider can be installed via [Composer](https://getcomposer.org/).

```
composer require aporat/laravel-cloudwatch-logger
```

## Usage

Config parameters for logging are defined at `config/logging.php`.

You need to add new channel as `cloudwatch` and copy params inside `config/config.php` into it.

```php
use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;

'channels' => [
    ...

    'cloudwatch' => [
        'driver' => 'custom',
        'via' => LaravelCloudWatchLoggerFactory::class,
        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => 'latest',
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ],
        'group' => env('CLOUDWATCH_LOG_GROUP_NAME', env('APP_NAME') . '-' . env('APP_ENV')),
        'stream' => env('CLOUDWATCH_LOG_STREAM', 'default'),
        'name' => env('CLOUDWATCH_LOG_NAME', ''),
        'retention' => env('CLOUDWATCH_LOG_RETENTION', 7),
        'level' => Level::Error,
        'formatter' => function ($configs) {
            return new LineFormatter(
                '%channel%: %level_name%: %message% %context% %extra%',
                null,
                false,
                true
            );
        },
    ],
],
```

Change the log channel inside `.env` file with `cloudwatch`.

```dotenv
LOG_CHANNEL=cloudwatch
```

You can use Laravel default `Log` class to send your logs to CloudWatch.

```php
\Illuminate\Support\Facades\Log::info('user logged in successfully', [
    'id' => 1,
    'username' => 'JohnDoe',
    'ip' => '127.0.0.1',
]);
```

### Testing

```bash
composer test
```
