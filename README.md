# Laravel CloudWatch Logger

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-cloudwatch-logger.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-cloudwatch-logger)
[![Codecov](https://img.shields.io/codecov/c/github/aporat/laravel-cloudwatch-logger?style=flat-square)](https://codecov.io/github/aporat/laravel-cloudwatch-logger)
[![Laravel Version](https://img.shields.io/badge/Laravel-10.x%20|%2011.x%20|%2012.x-orange.svg?style=flat-square)](https://laravel.com)
![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/aporat/laravel-cloudwatch-logger/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-cloudwatch-logger.svg?style=flat-square)](LICENSE)

A Laravel logging driver for seamless integration with AWS CloudWatch Logs.

## Features

- Custom Monolog channel for sending logs to CloudWatch.
- Configurable AWS credentials, log group, stream, retention, and batch size.
- Supports string-based, class-based, and callable custom log formatters.
- Fully compatible with Laravel's `Log` facade and channel system.
- Simple, environment-based setup with a dedicated configuration file.

## Requirements

- **PHP**: `^8.4`
- **Laravel**: `^10.0` || `^11.0` || `^12.0`
- **AWS SDK**: Provided via `phpnexus/cwh`

## Installation

1.  Require the package via Composer:
    ```bash
    composer require aporat/laravel-cloudwatch-logger
    ```

2.  Publish the configuration file:
    ```bash
    php artisan vendor:publish --provider="Aporat\CloudWatchLogger\CloudWatchLoggerServiceProvider" --tag="config"
    ```
    This will create a `config/cloudwatch-logger.php` file in your application.

## Configuration

### Step 1: Add the CloudWatch Channel

Add the following channel definition to your `config/logging.php` file's `channels` array. This will use the configuration file you published in the previous step.

```php
'channels' => [
    // ... other channels

    'cloudwatch' => require config_path('cloudwatch-logger.php'),
],
