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
 * Worker configuration options.
 *
 * Defines configuration parameters for worker behavior including
 * sleep intervals, limits, and stop conditions.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
final readonly class WorkerOptions
{
    /**
     * Create worker options.
     *
     * @param  int  $sleep  Seconds to sleep when queue is empty (default: 3).
     * @param  int|null  $maxJobs  Maximum jobs to process before stopping.
     *                             Null means no limit.
     * @param  int|null  $maxTime  Maximum execution time in seconds before stopping.
     *                             Null means no limit.
     * @param  int  $memory  Memory limit in megabytes. Worker stops when exceeded (default: 128).
     * @param  bool  $stopOnEmpty  Stop worker when queue becomes empty (default: false).
     */
    public function __construct(
        public int $sleep = 3,
        public ?int $maxJobs = null,
        public ?int $maxTime = null,
        public int $memory = 128,
        public bool $stopOnEmpty = false,
    ) {}
}
