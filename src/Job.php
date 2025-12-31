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
 * Job handler interface.
 *
 * Defines the contract for job handlers that process queued jobs.
 * Implementations should be stateless and handle their own dependencies.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
abstract class Job
{
    /**
     * Handle the job.
     *
     * This method is called by the worker to process the job.
     * Throw an exception to indicate job failure.
     *
     * @param  array<string, mixed>  $payload  Job data passed when the job was queued.
     */
    abstract public function handle(array $payload): void;

    /**
     * Handle a job failure.
     *
     * Called when the job fails after exhausting all retry attempts.
     * Use this to log errors, send notifications, or perform cleanup.
     *
     * @param  \Throwable  $exception  The exception that caused the failure.
     * @param  array<string, mixed>  $payload  Job data that was being processed.
     */
    public function failed(\Throwable $exception, array $payload): void
    {
        // Default implementation does nothing
    }

    /**
     * Get the number of retry attempts.
     *
     * Return 0 to disable retries. The job will be retried
     * this many times after the initial failure.
     *
     * @return int Retry attempts (default: 3).
     */
    public function retries(): int
    {
        return 3;
    }

    /**
     * Get the delay between retry attempts in seconds.
     *
     * This delay is applied before each retry attempt.
     * Can be used to implement exponential backoff by calculating
     * delay based on the attempt number in the handle method.
     *
     * @return int Delay in seconds (default: 60).
     */
    public function delay(): int
    {
        return 60;
    }
}
