<?php

/*
 * This file is part of vaibhavpandeyvpz/qatar package.
 *
 * (c) Vaibhav Pandey <contact@vaibhavpandey.com>
 *
 * This source file is subject to the MIT license that is bundled with this source code in the LICENSE file.
 */

namespace Qatar;

/**
 * Queue interface.
 *
 * Defines the contract for queue implementations that store and
 * retrieve jobs. Implementations can use different backends like
 * Redis, ElasticMQ/SQS, or databases.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
interface Queue
{
    /**
     * Push a job onto the queue.
     *
     * @param  string  $job  Fully qualified job handler class name.
     * @param  array<string, mixed>  $payload  Job data to pass to the handler.
     * @param  int|null  $delay  Optional delay in seconds before the job becomes available.
     *                           Null or 0 means the job is immediately available.
     * @return string Unique job identifier.
     */
    public function push(string $job, array $payload, ?int $delay = null): string;

    /**
     * Pop the next available job from the queue.
     *
     * This method should mark the job as processing and make it
     * invisible to other workers until acknowledged or timed out.
     *
     * @param  int|null  $timeout  Optional timeout in seconds to wait for a job.
     *                             Null means return immediately if no jobs available.
     *                             0 means wait indefinitely.
     * @return JobPayload|null The next job or null if no jobs available.
     */
    public function pop(?int $timeout = null): ?JobPayload;

    /**
     * Acknowledge successful job completion.
     *
     * Removes the job from the queue permanently.
     *
     * @param  string  $id  Job identifier returned from pop().
     * @return bool True if acknowledged, false if job not found.
     */
    public function ack(string $id): bool;

    /**
     * Negative acknowledge - return job to queue for retry.
     *
     * Makes the job available again, optionally after a delay.
     * Should increment the attempt counter.
     *
     * @param  string  $id  Job identifier returned from pop().
     * @param  int|null  $delay  Optional delay in seconds before retry.
     *                           Null uses default retry delay.
     * @return bool True if requeued, false if job not found.
     */
    public function nack(string $id, ?int $delay = null): bool;

    /**
     * Get the number of jobs in the queue.
     *
     * This should include pending jobs but not processing jobs.
     *
     * @return int Number of jobs waiting in the queue.
     */
    public function size(): int;

    /**
     * Remove all jobs from the queue.
     *
     * This is a destructive operation that clears all pending,
     * processing, and delayed jobs.
     */
    public function purge(): void;
}
