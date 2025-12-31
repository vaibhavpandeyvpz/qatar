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
 * Job status enumeration.
 *
 * Represents the possible states of a job in the queue system.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
enum JobStatus: string
{
    /**
     * Job is waiting in the queue to be processed.
     */
    case PENDING = 'pending';

    /**
     * Job is currently being processed by a worker.
     */
    case PROCESSING = 'processing';

    /**
     * Job has been completed successfully.
     */
    case COMPLETED = 'completed';

    /**
     * Job has failed permanently after exhausting all retries.
     */
    case FAILED = 'failed';

    /**
     * Job has failed and is scheduled for retry.
     */
    case RETRYING = 'retrying';
}
