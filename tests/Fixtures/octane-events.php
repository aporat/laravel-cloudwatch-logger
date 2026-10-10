<?php

/*
 * Minimal stand-ins for Laravel Octane's operation events, so the Octane
 * listeners can be exercised without installing Octane. Shapes match
 * laravel/octane's Laravel\Octane\Events\* (public $app and $sandbox).
 */

namespace Laravel\Octane\Events;

if (! class_exists(RequestTerminated::class)) {
    class RequestTerminated
    {
        public function __construct(public mixed $app, public mixed $sandbox, public mixed $request = null, public mixed $response = null) {}
    }
}

if (! class_exists(TaskTerminated::class)) {
    class TaskTerminated
    {
        public function __construct(public mixed $app, public mixed $sandbox, public mixed $data = null, public mixed $result = null) {}
    }
}

if (! class_exists(TickTerminated::class)) {
    class TickTerminated
    {
        public function __construct(public mixed $app, public mixed $sandbox) {}
    }
}
