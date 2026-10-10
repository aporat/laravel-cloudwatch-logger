# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased] — 4.0.0

See [Upgrading from 3.x](README.md#upgrading-from-3x).

### Changed (breaking)

- Replaced `phpnexus/cwh` with the package's own
  `Aporat\CloudWatchLogger\Handler\CloudWatchHandler`; `phpnexus/cwh` is no
  longer a dependency and `ext-mbstring` is required.
- `suppress_failures` defaults to `true`. Failures are reported with
  `error_log()` (never through a Laravel logger) instead of thrown.
- The handler is no longer wrapped in `WhatFailureGroupHandler` when failures
  are suppressed, so `bubble` is honoured.
- The AWS client gets `http.connect_timeout` 1s, `http.timeout` 3s and
  `retries` 1 unless configured.
- The handler no longer sleeps a second and retries a failed request.
- Group/stream existence cache keys changed format.

### Added

- `max_buffer_size` (default 10000): hard cap on buffered events; the oldest
  are dropped and counted when it is exceeded.
- `circuit_breaker` (default 30s): after a failure no request is sent for this
  long; records are only buffered.
- `flush_interval` (default 10s): a write flushes the buffer once its oldest
  event is this old.
- Buffers are flushed after queue `JobProcessed`, `JobFailed`, `Looping` and
  `WorkerStopping`, after Octane `RequestTerminated`, `TaskTerminated` and
  `TickTerminated` (when Octane is installed), and on Monolog `reset()`.
- `CloudWatchHandler::getDroppedEventCount()`, `getBufferedEventCount()`,
  `isCircuitOpen()` and a public `flush()`.

### Fixed

- A failed send no longer leaves the buffer stuck: it used to keep every
  event, grow past the 10,000-event / 1 MB request limits and fail every later
  request for the life of the process, even after CloudWatch recovered.
- An unreachable endpoint no longer blocks each log call for minutes (no HTTP
  timeouts, SDK default retries and a 1s sleep per flush).
- `ExceptionHandler::report()` no longer throws the CloudWatch exception (and
  loses the one being reported) when CloudWatch is down.
- Buffered events are no longer held until the batch fills in queue workers
  and Octane.
- Requests are split to respect the 10,000-event, 1,048,576-byte and 24-hour
  limits, in chronological order.
- Oversized messages are split on UTF-8 character boundaries (`mb_strcut`)
  instead of mid-character, which made the SDK fail to encode the batch;
  invalid UTF-8 is scrubbed.
- Timestamps are sent as integer milliseconds instead of floats.
- Non-retryable errors are not retried.
- `ResourceAlreadyExistsException` while creating the group or stream (a race
  with another process) is treated as success.
- A stream named like its group, and two groups sharing a stream name with a
  custom PSR-6 pool, no longer share a cache entry (which skipped creating the
  stream).
- `rps_limit` no longer sleeps when the previous request was a whole minute
  earlier.

## [3.0.0] — 2026-09-19

- Hardened configuration validation and exposed the remaining handler options.
- Requires Laravel 12 or 13 and PHP 8.4+.

Earlier releases: see the
[GitHub releases](https://github.com/aporat/laravel-cloudwatch-logger/releases).
