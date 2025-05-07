# Laravel CloudWatch Logger

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Codecov](https://img.shields.io/codecov/c/github/aporat/laravel-cloudwatch-logger?style=flat-square)](https://codecov.io/github/aporat/laravel-cloudwatch-logger)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-orange.svg?style=flat-square)](https://laravel.com/docs/12.x)
![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/aporat/laravel-cloudwatch-logger/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-cloudwatch-logger.svg?style=flat-square)](https://github.com/aporat/laravel-cloudwatch-logger/blob/master/LICENSE)

A Laravel logging driver for seamless integration with AWS CloudWatch Logs.

## Features

- Custom Monolog channel for sending logs to CloudWatch
- Configurable AWS credentials, log group, stream, retention, and batch size
- Supports string-based, class-based, and callable custom log formatters
- Compatible with Laravel's `Log` facade and channel system
- Laravel config publishing and environment-based setup

## Requirements

- **PHP**: 8.4 or higher
- **Laravel**: 10.x, 11.x, 12.x
- **AWS SDK**: Provided via `phpnexus/cwh`

## Installation

```bash
composer require aporat/laravel-cloudwatch-logger
```

If auto-discovery is disabled, add the provider manually to `config/app.php`:

```php
'providers' => [
    Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider::class,
],
```

Then publish the config:

```bash
php artisan vendor:publish --provider="Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider" --tag="config"
```

## Configuration

### Step 1: Add the CloudWatch Channel

Add this to your `config/logging.php`:

```php
'channels' => [
    'cloudwatch' => require config_path('cloudwatch-logger.php'),
],
```

### Step 2: Configure `.env`

```env
LOG_CHANNEL=cloudwatch

AWS_ACCESS_KEY_ID=your-aws-key
AWS_SECRET_ACCESS_KEY=your-aws-secret
AWS_DEFAULT_REGION=us-east-1

CLOUDWATCH_LOG_GROUP_NAME=myapp-prod
CLOUDWATCH_LOG_STREAM=app-logs
CLOUDWATCH_LOG_NAME=myapp
CLOUDWATCH_LOG_RETENTION=14
CLOUDWATCH_LOG_LEVEL=error
CLOUDWATCH_LOG_BATCH_SIZE=10000

# Optional: formatter as a string
CLOUDWATCH_LOG_FORMAT="%channel%: %level_name%: %message% %context% %extra%"
```

### Step 3: Custom Formatters

You can customize the formatter in `cloudwatch-logger.php`:

```php
// LineFormatter as format string
'formatter' => '%channel%: %level_name%: %message% %context% %extra%',

// Or as a class
'formatter' => Monolog\Formatter\JsonFormatter::class,

// Or as a callable
'formatter' => function (array $config) {
    return new Monolog\Formatter\LineFormatter(
        format: '%channel%: %level_name%: %message% %context% %extra%',
        dateFormat: null,
        allowInlineLineBreaks: false,
        ignoreEmptyContextAndExtra: true
    );
},
```

## Usage

```php
use Illuminate\Support\Facades\Log;

Log::info('User login', ['user_id' => 123]);
```

## Testing

```bash
composer test
composer test-coverage
```

## License

This package is licensed under the [MIT License](LICENSE).
