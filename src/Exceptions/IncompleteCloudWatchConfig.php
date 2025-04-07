<?php

declare(strict_types=1);

namespace Aporat\CloudWatchLogger\Exceptions;

use Exception;

/**
 * Exception thrown when CloudWatch configuration is incomplete or invalid.
 *
 * This exception is raised when the required configuration settings for CloudWatch logging
 * (e.g., credentials, log group, or stream) are missing or malformed.
 */
class IncompleteCloudWatchConfig extends Exception
{
    /**
     * Create a new incomplete CloudWatch configuration exception.
     *
     * @param  string  $message  The exception message (default: 'Incomplete CloudWatch configuration')
     * @param  int  $code  The exception code (default: 0)
     * @param  Exception|null  $previous  The previous exception for chaining (default: null)
     */
    public function __construct(string $message = 'Incomplete CloudWatch configuration', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
