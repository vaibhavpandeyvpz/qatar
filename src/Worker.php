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
 * Job worker implementation.
 *
 * Processes jobs from a queue with support for retries, error handling,
 * and graceful shutdown. Workers can be configured with various limits
 * and behaviors via WorkerOptions.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
class Worker
{
    /**
     * Whether the worker should stop processing.
     */
    private bool $shouldQuit = false;

    /**
     * Number of jobs processed in this worker session.
     */
    private int $jobsProcessed = 0;

    /**
     * Unix timestamp when the worker started.
     */
    private int $startTime;

    /**
     * Create a new worker instance.
     *
     * @param  Queue  $queue  The queue to process jobs from.
     * @param  WorkerOptions  $options  Worker configuration options.
     */
    public function __construct(
        private readonly Queue $queue,
        private readonly WorkerOptions $options = new WorkerOptions,
    ) {
        $this->startTime = time();
    }

    /**
     * Start processing jobs from the queue.
     *
     * This method runs in a loop until stopped by calling stop(),
     * reaching a configured limit, or receiving a termination signal.
     */
    public function work(): void
    {
        // Register signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop());
            pcntl_signal(SIGINT, fn () => $this->stop());
        }

        while (! $this->shouldQuit) {
            // Check if we should stop based on limits
            if ($this->shouldStop()) {
                break;
            }

            // Get next job from queue
            $jobPayload = $this->queue->pop($this->options->sleep);

            if ($jobPayload === null) {
                // No job available
                if ($this->options->stopOnEmpty) {
                    break;
                }

                continue;
            }

            // Process the job
            $this->process($jobPayload);
            $this->jobsProcessed++;
        }
    }

    /**
     * Stop the worker gracefully.
     *
     * The worker will finish processing the current job before stopping.
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
    }

    /**
     * Process a single job.
     *
     * @param  JobPayload  $jobPayload  The job to process.
     */
    private function process(JobPayload $jobPayload): void
    {
        try {
            // Instantiate the job handler
            $job = $this->resolveJob($jobPayload->job, $jobPayload->payload);

            // Execute the job
            $job->handle($jobPayload->payload);

            // Acknowledge successful completion
            $this->queue->ack($jobPayload->id);
        } catch (\Throwable $exception) {
            // Job failed
            $this->handleFailure($jobPayload, $exception);
        }
    }

    /**
     * Handle a failed job.
     *
     * Determines whether to retry the job or mark it as permanently failed.
     *
     * @param  JobPayload  $jobPayload  The failed job.
     * @param  \Throwable  $exception  The exception that caused the failure.
     */
    private function handleFailure(JobPayload $jobPayload, \Throwable $exception): void
    {
        try {
            $job = $this->resolveJob($jobPayload->job, $jobPayload->payload);
            $maxRetries = $job->retries();
            $retryDelay = $job->delay();

            // Check if we should retry
            if ($jobPayload->attempts < $maxRetries) {
                // Retry the job
                $this->queue->nack($jobPayload->id, $retryDelay);
            } else {
                // Job has exhausted retries, mark as failed
                $this->queue->ack($jobPayload->id);

                // Call the failed handler
                $job->failed($exception, $jobPayload->payload);
            }
        } catch (\Throwable $e) {
            // If we can't even handle the failure, just acknowledge to prevent infinite loop
            $this->queue->ack($jobPayload->id);
        }
    }

    /**
     * Resolve a job handler instance from its class name.
     *
     * This method can be overridden in subclasses to provide custom
     * job instantiation logic, such as dependency injection.
     *
     * @param  string  $jobClass  Fully qualified job class name.
     * @param  array<string, mixed>  $payload  Job payload data.
     * @return Job The job handler instance.
     *
     * @throws \RuntimeException If the job class doesn't exist or doesn't implement Job.
     */
    protected function resolveJob(string $jobClass, array $payload): Job
    {
        if (! class_exists($jobClass)) {
            throw new \RuntimeException("Job class '{$jobClass}' does not exist.");
        }

        $job = new $jobClass;

        if (! $job instanceof Job) {
            throw new \RuntimeException("Job class '{$jobClass}' must implement Qatar\\Job interface.");
        }

        return $job;
    }

    /**
     * Check if the worker should stop based on configured limits.
     *
     * @return bool True if worker should stop, false otherwise.
     */
    private function shouldStop(): bool
    {
        // Check job limit
        if ($this->options->maxJobs !== null && $this->jobsProcessed >= $this->options->maxJobs) {
            return true;
        }

        // Check time limit
        if ($this->options->maxTime !== null && (time() - $this->startTime) >= $this->options->maxTime) {
            return true;
        }

        // Check memory limit
        $memoryUsageMB = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsageMB >= $this->options->memory) {
            return true;
        }

        return false;
    }
}
