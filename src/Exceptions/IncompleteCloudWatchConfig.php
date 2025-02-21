<?php

namespace Aporat\CloudWatchLogger\Exceptions;

use Exception;

class IncompleteCloudWatchConfig extends Exception
{
    /**
     * IncompleteCloudWatchConfig constructor.
     *
     * @param string         $message  The exception message (default: 'Invalid CloudWatch configuration')
     * @param int            $code     The exception code (default: 0)
     * @param Exception|null $previous The previous exception used for chaining (default: null)
     */
    public function __construct(string $message = 'Invalid CloudWatch configuration', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
