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
 * Job payload value object.
 *
 * Encapsulates all data associated with a queued job including
 * its identifier, handler class, payload data, and metadata.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
final readonly class JobPayload
{
    /**
     * Create a new job payload instance.
     *
     * @param  string  $id  Unique job identifier.
     * @param  string  $job  Fully qualified job handler class name.
     * @param  array<string, mixed>  $payload  Job data to be passed to the handler.
     * @param  int  $attempts  Number of times this job has been attempted.
     * @param  int  $availableAt  Unix timestamp when the job becomes available for processing.
     */
    public function __construct(
        public string $id,
        public string $job,
        public array $payload,
        public int $attempts = 0,
        public int $availableAt = 0,
    ) {}
}
