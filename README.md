# Qatar (कतार)

Framework agnostic PHP library for publishing and consuming jobs using Redis and ElasticMQ/SQS backends.

> Qatar: `कतार` (Queue)

[![Latest Version](https://img.shields.io/packagist/v/vaibhavpandeyvpz/qatar.svg?style=flat-square)](https://packagist.org/packages/vaibhavpandeyvpz/qatar)
[![Build Status](https://img.shields.io/github/actions/workflow/status/vaibhavpandeyvpz/qatar/tests.yml?branch=main&style=flat-square)](https://github.com/vaibhavpandeyvpz/qatar/actions)
[![Downloads](https://img.shields.io/packagist/dt/vaibhavpandeyvpz/qatar.svg?style=flat-square)](https://packagist.org/packages/vaibhavpandeyvpz/qatar)
[![PHP Version](https://img.shields.io/packagist/php-v/vaibhavpandeyvpz/qatar.svg?style=flat-square)](https://packagist.org/packages/vaibhavpandeyvpz/qatar)
[![License](https://img.shields.io/packagist/l/vaibhavpandeyvpz/qatar.svg?style=flat-square)](LICENSE)

## Features

- 🚀 **Fast**: Efficient job processing with Redis or ElasticMQ/SQS backends
- 🔄 **Reliable**: Automatic retries with configurable delays
- ⏰ **Delayed Jobs**: Schedule jobs to run in the future
- 👷 **Multiple Workers**: Run multiple workers concurrently
- 🎯 **Simple API**: Clean, intuitive interface for job management
- 🔧 **Flexible**: Framework agnostic - use with any PHP application
- 📝 **Type-safe**: Full PHP 8.2+ type hints and modern language features
- ⚡ **Graceful Shutdown**: Workers handle termination signals properly
- 💾 **Two Backends**: Choose between Redis (fast) or ElasticMQ/SQS (distributed).
    - _Note: Backend-specific drivers (`predis` or `aws-sdk-php`) are suggested and must be installed separately._

## Requirements

- PHP 8.2 or higher
- Redis server + [PhpRedis](https://github.com/phpredis/phpredis) OR [`predis/predis`](https://packagist.org/packages/predis/predis) (for `RedisQueue`)
- ElasticMQ or AWS SQS + [`aws/aws-sdk-php`](https://packagist.org/packages/aws/aws-sdk-php) (for `ElasticMQQueue`)
- JSON extension (`ext-json`) for payload serialization (usually enabled by default)

## Installation

Install via Composer:

```bash
composer require vaibhavpandeyvpz/qatar
```

## Local Development Setup

For local testing, use Docker Compose to run Redis and ElasticMQ:

```bash
# Start services
docker-compose up -d

# Verify services are running
docker-compose ps

# Run tests
composer test
```

This will start:

- **Redis** on `localhost:6379`
- **ElasticMQ** on `localhost:9324` (SQS-compatible API)
- **ElasticMQ UI** on `localhost:9325` (monitoring)

## Quick Start

### Creating a Job

Implementing `Qatar\Job` is simple:

```php
<?php

use Qatar\Job;

class SendEmailJob extends Job
{
    public function handle(array $payload): void
    {
        // $payload has data passed during push()
        mail($payload['to'], $payload['subject'], $payload['body']);
    }
}
```

By extending `Job`, you get default implementations for:

- `retries()`: Returns `3`
- `delay()`: Returns `60` seconds
- `failed()`: Empty handler

Override them as needed:

```php
class SendEmailJob extends Job
{
    public function handle(array $payload): void { ... }

    public function failed(\Throwable $exception, array $payload): void
    {
        // Handle permanent failure
    }

    public function retries(): int
    {
        return 5;
    }
}
```

### Publishing Jobs

```php
use Qatar\RedisQueue;

// Create a Redis client (Predis or PhpRedis)
$redis = new Predis\Client('tcp://127.0.0.1:6379');

// Create a queue instance
$queue = new RedisQueue($redis, 'emails');

// Basic push
$queue->push(SendEmailJob::class, [
    'to' => 'user@example.com',
    'subject' => 'Hello!',
    'body' => 'Welcome to Qatar.',
]);

// Delayed push (5 minutes)
$queue->push(SendEmailJob::class, [...], delay: 300);
```

### Running Workers

```php
use Qatar\Worker;
use Qatar\RedisQueue;

$redis = new Predis\Client('tcp://127.0.0.1:6379');
$queue = new RedisQueue($redis, 'emails');
$worker = new Worker($queue);

$worker->work();
```

## API Reference

### `Qatar\Queue` Interface

| Method                                                          | Description                                                |
| --------------------------------------------------------------- | ---------------------------------------------------------- |
| `push(string $job, array $payload, ?int $delay = null): string` | Add a job to the queue. Returns job ID.                    |
| `pop(?int $timeout = null): ?JobPayload`                        | Retrieve next job. Optionally wait for `$timeout` seconds. |
| `ack(string $id): bool`                                         | Acknowledge successful completion of a job.                |
| `nack(string $id, ?int $delay = null): bool`                    | Record a failure and schedule a retry.                     |
| `size(): int`                                                   | Get total number of pending and delayed jobs.              |
| `purge(): void`                                                 | Clear all jobs from the queue.                             |

### `Qatar\Worker` Class

The worker executes jobs from a queue. It is **not final**, so you can extend it to override `resolveJob()` for dependency injection.

```php
use Qatar\Worker;
use Qatar\Job;

class ContainerWorker extends Worker
{
    protected function resolveJob(string $jobClass, array $payload): Job
    {
        // Use your DI container here
        return $this->container->get($jobClass);
    }
}
```

#### `WorkerOptions`

| Option        | Default | Description                                    |
| ------------- | ------- | ---------------------------------------------- |
| `sleep`       | `3`     | Seconds to wait when the queue is empty.       |
| `maxJobs`     | `null`  | Max number of jobs to process before stopping. |
| `maxTime`     | `null`  | Max seconds to run before stopping.            |
| `memory`      | `128`   | Memory limit in MB. Worker stops if exceeded.  |
| `stopOnEmpty` | `false` | If `true`, worker quits when queue is empty.   |

## Advanced Usage

### Exponential Backoff

Implement custom backoff logic by using the `attempts` property in the payload:

```php
class BackoffJob extends Job
{
    public function handle(array $payload): void
    {
        // The attempt number is managed by the queue backend
    }

    public function retries(): int { return 5; }

    public function delay(): int
    {
        // Custom logic here
        return 60;
    }
}
```

### Signal Handling

Workers gracefully stop when they receive `SIGTERM` or `SIGINT`. They finish the current job before exiting.

## Monitoring

- **Redis**: Use `redis-cli MONITOR` or `LLEN qatar:default:ready`.
- **ElasticMQ**: Visit `http://localhost:9325` for the stats UI.

## Testing

Run tests with code coverage:

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE) for details.

## Author

**Vaibhav Pandey**

- GitHub: [@vaibhavpandeyvpz](https://github.com/vaibhavpandeyvpz)
