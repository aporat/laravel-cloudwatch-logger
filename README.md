# Laravel CloudWatch Logger

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Latest Dev Version](https://img.shields.io/packagist/vpre/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger#dev-develop)
[![Monthly Downloads](https://img.shields.io/packagist/dm/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Codecov](https://codecov.io/gh/aporat/laravel-cloudwatch-logger/graph/badge.svg?token=0WHTTGMINF)](https://codecov.io/gh/aporat/laravel-cloudwatch-logger)
[![Laravel Version](https://img.shields.io/badge/Laravel-10.x-orange.svg)](https://laravel.com/docs/10.x)
[![License](https://img.shields.io/packagist/l/aporat/laravel-cloudwatch-logger.svg?style=flat-square)](https://github.com/aporat/laravel-cloudwatch-logger/blob/master/LICENSE)

A Laravel logging driver for seamless integration with AWS CloudWatch Logs.

## Features
- Custom Monolog channel for sending logs to CloudWatch.
- Configurable AWS credentials, log group, stream, and retention period.
- Support for custom log formatters (e.g., JSON, line format).
- Compatible with Laravel’s native logging system via the `Log` facade.
- Built-in configuration publishing for easy setup.

## Requirements
- **PHP**: 8.2 or higher
- **Laravel**: 10.x or 11.x
- **AWS SDK**: Provided via `phpnexus/cwh` dependency

## Installation
Install the package via [Composer](https://getcomposer.org/):

```bash
composer require aporat/laravel-cloudwatch-logger
```

The service provider (`CloudWatchLoggerServiceProvider`) is automatically registered via Laravel’s package discovery. If auto-discovery is disabled, add it to `config/app.php`:

```php
'providers' => [
    // ...
    Aporat\CloudWatchLogger\Laravel\CloudWatchLoggerServiceProvider::class,
],
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Aporat\CloudWatchLogger\Laravel\CloudWatchLoggerServiceProvider" --tag="config"
```

This copies `cloudwatch-logger.php` to your `config/` directory.

## Configuration
### Step 1: Add the CloudWatch Channel
Merge the CloudWatch configuration into `config/logging.php` under the `channels` key:

```php
use Aporat\CloudWatchLogger\CloudWatchLoggerFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;

'channels' => [
    // Other channels...
    'cloudwatch' => [
        'driver' => 'custom',
        'via' => Aporat\CloudWatchLogger\CloudWatchLoggerFactory::class,
        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => env('AWS_VERSION', 'latest'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID', ''),
                'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            ],
        ],
        'group' => env('CLOUDWATCH_LOG_GROUP_NAME', env('APP_NAME', 'laravel') . '-' . env('APP_ENV', 'production')),
        'stream' => env('CLOUDWATCH_LOG_STREAM', 'default'),
        'name' => env('CLOUDWATCH_LOG_NAME', env('APP_NAME', 'laravel')),
        'retention' => env('CLOUDWATCH_LOG_RETENTION', 14),
        'level' => env('CLOUDWATCH_LOG_LEVEL', Level::Error->value),
        'batch_size' => env('CLOUDWATCH_LOG_BATCH_SIZE', 10000),
        'formatter' => function (array $config) {
            return new LineFormatter(
                format: '%channel%: %level_name%: %message% %context% %extra%',
                dateFormat: null,
                allowInlineLineBreaks: false,
                ignoreEmptyContextAndExtra: true
            );
        },
    ],
],
```

### Step 2: Set the Log Channel
Update your `.env` file to use the `cloudwatch` channel:

```
LOG_CHANNEL=cloudwatch
```

### Step 3: Configure AWS Credentials
Add your AWS credentials and optional CloudWatch settings to `.env`:

```
AWS_ACCESS_KEY_ID=your-aws-key
AWS_SECRET_ACCESS_KEY=your-aws-secret
AWS_DEFAULT_REGION=us-east-1
CLOUDWATCH_LOG_GROUP_NAME=myapp-prod
CLOUDWATCH_LOG_STREAM=app-logs
CLOUDWATCH_LOG_NAME=myapp
CLOUDWATCH_LOG_RETENTION=14
CLOUDWATCH_LOG_LEVEL=error
CLOUDWATCH_LOG_BATCH_SIZE=10000
```

## Usage
Log messages using Laravel’s `Log` facade, and they’ll be sent to CloudWatch:

```php
use Illuminate\Support\Facades\Log;

Log::info('User logged in successfully', [
    'id' => 1,
    'username' => 'JohnDoe',
    'ip' => '127.0.0.1',
]);
```

### Custom Formatter
Override the default formatter in `config/logging.php`:

```php
'formatter' => Monolog\Formatter\JsonFormatter::class,
```

Or use a custom callable:

```php
'formatter' => function (array $config) {
    return new Monolog\Formatter\JsonFormatter();
},
```

## Testing
Run the test suite:

```bash
composer test
```

Generate coverage reports:

```bash
composer test-coverage
```

## Contributing
Contributions are welcome! Please:
1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/new-feature`).
3. Commit your changes (`git commit -m "Add new feature"`).
4. Push to the branch (`git push origin feature/new-feature`).
5. Open a pull request.

Report issues at [GitHub Issues](https://github.com/aporat/laravel-cloudwatch-logger/issues).

## License
This package is licensed under the [MIT License](LICENSE). See the [License File](LICENSE) for details.

## Support
- **Issues**: [GitHub Issues](https://github.com/aporat/laravel-cloudwatch-logger/issues)
- **Source**: [GitHub Repository](https://github.com/aporat/laravel-cloudwatch-logger)
