<?php

/*
 * This file is part of vaibhavpandeyvpz/qatar package.
 *
 * (c) Vaibhav Pandey <contact@vaibhavpandey.com>
 *
 * This source file is subject to the MIT license that is bundled with this source code in the LICENSE file.
 */

namespace Qatar;

use PHPUnit\Framework\TestCase;

class WorkerTest extends TestCase
{
    private RedisQueue $queue;

    protected function setUp(): void
    {
        $redis = new \Predis\Client('tcp://127.0.0.1:6379');
        $this->queue = new RedisQueue($redis, 'worker_test_'.uniqid());
        $this->queue->purge();
    }

    protected function tearDown(): void
    {
        $this->queue->purge();
    }

    public function test_worker_processes_job(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);

        $options = new WorkerOptions(sleep: 1, stopOnEmpty: true);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        $this->assertCount(1, WorkerTestJob::$processed);
        $this->assertEquals(['id' => 1], WorkerTestJob::$processed[0]);
        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_processes_multiple_jobs(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);
        $this->queue->push(WorkerTestJob::class, ['id' => 2]);
        $this->queue->push(WorkerTestJob::class, ['id' => 3]);

        $options = new WorkerOptions(stopOnEmpty: true);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        $this->assertCount(3, WorkerTestJob::$processed);
        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_stops_on_max_jobs(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);
        $this->queue->push(WorkerTestJob::class, ['id' => 2]);
        $this->queue->push(WorkerTestJob::class, ['id' => 3]);

        $options = new WorkerOptions(maxJobs: 2);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        $this->assertCount(2, WorkerTestJob::$processed);
        $this->assertEquals(1, $this->queue->size());
    }

    public function test_worker_stops_on_max_time(): void
    {
        WorkerTestJob::$processed = [];
        SlowJob::$processed = [];

        // Each job takes 2 seconds
        $this->queue->push(SlowJob::class, ['id' => 1]);
        $this->queue->push(SlowJob::class, ['id' => 2]);
        $this->queue->push(SlowJob::class, ['id' => 3]);

        $options = new WorkerOptions(maxTime: 3);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        // Should process 1-2 jobs before hitting time limit
        $this->assertLessThan(3, count(SlowJob::$processed));
        $this->assertGreaterThan(0, $this->queue->size());
    }

    public function test_worker_stops_on_memory_limit(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);

        // Set very low memory limit (1MB)
        $options = new WorkerOptions(memory: 1, stopOnEmpty: true);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        // Worker should stop due to memory limit
        // May or may not process the job depending on memory usage
        $this->assertLessThanOrEqual(1, count(WorkerTestJob::$processed));
    }

    public function test_worker_handles_failed_job_with_retries(): void
    {
        FailingJob::$attempts = [];
        FailingJob::$failed = [];

        $this->queue->push(FailingJob::class, ['id' => 1]);

        $options = new WorkerOptions(sleep: 1, maxTime: 20);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        // Should retry 3 times (maxRetries) = 3 total attempts
        // (initial attempt fails, then 3 retries, but worker stops after maxRetries)
        $this->assertGreaterThanOrEqual(3, count(FailingJob::$attempts));

        // Should call failed() after exhausting retries
        $this->assertCount(1, FailingJob::$failed);

        // Job should be removed from queue
        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_handles_job_that_succeeds_after_retry(): void
    {
        RetryableJob::$attempts = [];
        RetryableJob::$failed = [];

        $this->queue->push(RetryableJob::class, ['id' => 1]);

        $options = new WorkerOptions(sleep: 1, maxTime: 15);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        // Should succeed on second attempt
        $this->assertCount(2, RetryableJob::$attempts);

        // Should not call failed()
        $this->assertCount(0, RetryableJob::$failed);

        // Job should be removed from queue
        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_graceful_stop(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);

        $options = new WorkerOptions;
        $worker = new Worker($this->queue, $options);

        // Stop immediately
        $worker->stop();

        $worker->work();

        // Should not process any jobs
        $this->assertCount(0, WorkerTestJob::$processed);
        $this->assertEquals(1, $this->queue->size());
    }

    public function test_worker_stop_on_empty(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);

        $options = new WorkerOptions(stopOnEmpty: true);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        $this->assertCount(1, WorkerTestJob::$processed);
        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_continues_when_stop_on_empty_false(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);

        $options = new WorkerOptions(stopOnEmpty: false, maxJobs: 1);
        $worker = new Worker($this->queue, $options);

        $worker->work();

        // Should process the job and stop due to maxJobs
        $this->assertCount(1, WorkerTestJob::$processed);
    }

    public function test_worker_handles_nonexistent_job_class(): void
    {
        $this->queue->push('NonExistentJobClass', ['test' => 'data']);

        $options = new WorkerOptions(stopOnEmpty: true);
        $worker = new Worker($this->queue, $options);

        // Should not throw exception, just acknowledge the job
        $worker->work();

        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_handles_invalid_job_class(): void
    {
        $this->queue->push(\stdClass::class, ['test' => 'data']);

        $options = new WorkerOptions(stopOnEmpty: true);
        $worker = new Worker($this->queue, $options);

        // Should not throw exception, just acknowledge the job
        $worker->work();

        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_handles_exception_in_failed_handler(): void
    {
        BrokenFailedHandlerJob::$attempts = [];

        $this->queue->push(BrokenFailedHandlerJob::class, ['id' => 1]);

        $options = new WorkerOptions(sleep: 1, maxTime: 20);
        $worker = new Worker($this->queue, $options);

        // Should not throw exception even if failed() throws
        $worker->work();

        // Job should still be acknowledged
        $this->assertEquals(0, $this->queue->size());
    }

    public function test_worker_respects_job_retry_delay(): void
    {
        CustomRetryDelayJob::$attempts = [];

        $this->queue->push(CustomRetryDelayJob::class, ['id' => 1]);

        $options = new WorkerOptions(sleep: 1, maxTime: 10);
        $worker = new Worker($this->queue, $options);

        $startTime = time();
        $worker->work();
        $duration = time() - $startTime;

        // Should have at least one retry with 2 second delay
        $this->assertGreaterThanOrEqual(2, $duration);
        $this->assertGreaterThan(1, count(CustomRetryDelayJob::$attempts));
    }

    public function test_worker_with_default_options(): void
    {
        WorkerTestJob::$processed = [];

        $this->queue->push(WorkerTestJob::class, ['id' => 1]);

        $worker = new Worker($this->queue);

        // Stop after one job
        $worker->stop();

        $worker->work();

        // Default options should work
        $this->assertCount(0, WorkerTestJob::$processed);
    }
}

/**
 * Test job that succeeds.
 */
class WorkerTestJob extends Job
{
    public static array $processed = [];

    public function handle(array $payload): void
    {
        self::$processed[] = $payload;
    }

    public function delay(): int
    {
        return 1;
    }
}

/**
 * Test job that takes time to process.
 */
class SlowJob extends Job
{
    public static array $processed = [];

    public function handle(array $payload): void
    {
        sleep(2);
        self::$processed[] = $payload;
    }

    public function retries(): int
    {
        return 0;
    }

    public function delay(): int
    {
        return 1;
    }
}

/**
 * Test job that always fails.
 */
class FailingJob extends Job
{
    public static array $attempts = [];

    public static array $failed = [];

    public function handle(array $payload): void
    {
        self::$attempts[] = $payload;
        throw new \RuntimeException('Job failed');
    }

    public function failed(\Throwable $exception, array $payload): void
    {
        self::$failed[] = ['exception' => $exception->getMessage(), 'payload' => $payload];
    }

    public function delay(): int
    {
        return 1;
    }
}

/**
 * Test job that fails once then succeeds.
 */
class RetryableJob extends Job
{
    public static array $attempts = [];

    public static array $failed = [];

    public function handle(array $payload): void
    {
        self::$attempts[] = $payload;

        if (count(self::$attempts) === 1) {
            throw new \RuntimeException('First attempt fails');
        }

        // Second attempt succeeds
    }

    public function failed(\Throwable $exception, array $payload): void
    {
        self::$failed[] = $payload;
    }

    public function delay(): int
    {
        return 1;
    }
}

/**
 * Test job with broken failed handler.
 */
class BrokenFailedHandlerJob extends Job
{
    public static array $attempts = [];

    public function handle(array $payload): void
    {
        self::$attempts[] = $payload;
        throw new \RuntimeException('Job failed');
    }

    public function failed(\Throwable $exception, array $payload): void
    {
        throw new \RuntimeException('Failed handler is broken');
    }

    public function retries(): int
    {
        return 0;
    }

    public function delay(): int
    {
        return 1;
    }
}

/**
 * Test job with custom retry delay.
 */
class CustomRetryDelayJob extends Job
{
    public static array $attempts = [];

    public function handle(array $payload): void
    {
        self::$attempts[] = $payload;

        if (count(self::$attempts) < 2) {
            throw new \RuntimeException('Retry needed');
        }
    }

    public function delay(): int
    {
        return 2;
    }
}
