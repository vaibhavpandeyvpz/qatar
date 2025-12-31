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
 * Redis-backed queue implementation.
 *
 * Uses Redis data structures for reliable job queuing:
 * - Sorted sets for delayed jobs (by availability timestamp)
 * - Lists for ready jobs (FIFO)
 * - Hashes for job data storage
 * - Sets for tracking processing jobs
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
class RedisQueue implements Queue
{
    /**
     * Default visibility timeout in seconds.
     * Jobs not acknowledged within this time are made available again.
     */
    private const VISIBILITY_TIMEOUT = 60;

    /**
     * Redis client instance (Redis or Predis\Client).
     */
    protected readonly mixed $redis;

    /**
     * Create a new Redis queue instance.
     *
     * @param  mixed  $redis  Redis client instance or connection string (default: 'tcp://127.0.0.1:6379').
     * @param  string  $queue  Queue name (default: 'default').
     *
     * @throws \RuntimeException If no Redis driver is found.
     */
    public function __construct(
        mixed $redis = 'tcp://127.0.0.1:6379',
        private readonly string $queue = 'default',
    ) {
        if (is_string($redis)) {
            $redis = $this->createClient($redis);
        }
        $this->redis = $redis;
    }

    /**
     * Create a Redis client instance from a connection string.
     *
     * @param  string  $connection  Redis connection string.
     * @return mixed Redis client instance.
     *
     * @throws \RuntimeException If no Redis driver is found.
     */
    private function createClient(string $connection): mixed
    {
        if (class_exists(\Predis\Client::class)) {
            return new \Predis\Client($connection);
        }

        if (extension_loaded('redis')) {
            return $this->createPhpRedisClient($connection);
        }

        throw new \RuntimeException('No Redis driver found. Please install predis/predis or ext-redis.');
    }

    /**
     * Create a PhpRedis client instance.
     *
     * @param  string  $connection  Redis connection string.
     * @return \Redis PhpRedis client instance.
     */
    private function createPhpRedisClient(string $connection): \Redis
    {
        $parsed = parse_url($connection);
        $scheme = $parsed['scheme'] ?? 'tcp';
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = (int) ($parsed['port'] ?? 6379);
        $pass = $parsed['pass'] ?? null;
        $db = (int) (ltrim($parsed['path'] ?? '', '/') ?: 0);

        if (in_array($scheme, ['rediss', 'tls'])) {
            $host = "tls://{$host}";
        }

        $redis = new \Redis;
        $redis->connect($host, $port);

        if ($pass) {
            $redis->auth($pass);
        }

        if ($db > 0) {
            $redis->select($db);
        }

        return $redis;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function push(string $job, array $payload, ?int $delay = null): string
    {
        $id = $this->generateId();
        $availableAt = $delay ? time() + $delay : time();

        $jobData = [
            'id' => $id,
            'job' => $job,
            'payload' => json_encode($payload),
            'attempts' => 0,
            'availableAt' => $availableAt,
        ];

        // Store job data
        $this->hmset($this->getJobKey($id), $jobData);

        // Add to delayed or ready queue
        if ($delay && $delay > 0) {
            $this->zadd($this->getDelayedKey(), $availableAt, $id);
        } else {
            $this->rpush($this->getReadyKey(), $id);
        }

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function pop(?int $timeout = null): ?JobPayload
    {
        // Move delayed jobs to ready queue
        $this->migrateDelayedJobs();

        // Get next job from ready queue
        $id = null;
        if ($timeout === null || $timeout === 0) {
            $id = $this->redis->lpop($this->getReadyKey());
        } else {
            $result = $this->redis->blpop([$this->getReadyKey()], $timeout);
            $id = $result[1] ?? null;
        }

        if (! $id) {
            return null;
        }

        // Get job data
        $jobData = $this->redis->hgetall($this->getJobKey($id));

        if (empty($jobData)) {
            return null;
        }

        // Mark as processing
        $this->sadd($this->getProcessingKey(), (string) $id);
        $this->zadd($this->getTimeoutKey(), time() + self::VISIBILITY_TIMEOUT, (string) $id);

        // Increment attempts
        $attempts = (int) $jobData['attempts'] + 1;
        $this->redis->hset($this->getJobKey((string) $id), 'attempts', $attempts);

        return new JobPayload(
            id: $id,
            job: $jobData['job'],
            payload: json_decode($jobData['payload'], true),
            attempts: $attempts,
            availableAt: (int) $jobData['availableAt'],
        );
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function ack(string $id): bool
    {
        // Remove from processing
        $this->redis->srem($this->getProcessingKey(), $id);
        $this->redis->zrem($this->getTimeoutKey(), $id);

        // Delete job data
        $result = $this->del($this->getJobKey($id));

        return $result > 0;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function nack(string $id, ?int $delay = null): bool
    {
        // Remove from processing
        $this->redis->srem($this->getProcessingKey(), $id);
        $this->redis->zrem($this->getTimeoutKey(), $id);

        // Check if job still exists
        if (! $this->redis->exists($this->getJobKey($id))) {
            return false;
        }

        $availableAt = $delay ? time() + $delay : time();

        // Update availability time
        $this->redis->hset($this->getJobKey($id), 'availableAt', $availableAt);

        // Add back to delayed or ready queue
        if ($delay && $delay > 0) {
            $this->zadd($this->getDelayedKey(), $availableAt, $id);
        } else {
            $this->rpush($this->getReadyKey(), $id);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function size(): int
    {
        $ready = $this->redis->llen($this->getReadyKey());
        $delayed = $this->redis->zcard($this->getDelayedKey());

        return $ready + $delayed;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function purge(): void
    {
        // Get all job IDs
        $readyIds = $this->redis->lrange($this->getReadyKey(), 0, -1);
        $delayedIds = $this->redis->zrange($this->getDelayedKey(), 0, -1);
        $processingIds = $this->redis->smembers($this->getProcessingKey());

        $allIds = array_merge($readyIds, $delayedIds, $processingIds);

        // Delete all job data
        foreach ($allIds as $id) {
            $this->del($this->getJobKey($id));
        }

        // Delete queue structures
        $this->del(
            $this->getReadyKey(),
            $this->getDelayedKey(),
            $this->getProcessingKey(),
            $this->getTimeoutKey(),
        );
    }

    /**
     * Move delayed jobs that are now ready to the ready queue.
     */
    private function migrateDelayedJobs(): void
    {
        $now = time();

        // Get jobs that are ready
        $jobs = $this->redis->zrangebyscore($this->getDelayedKey(), '-inf', (string) $now);

        foreach ($jobs as $id) {
            // Move to ready queue
            $this->rpush($this->getReadyKey(), $id);
            $this->redis->zrem($this->getDelayedKey(), $id);
        }
    }

    private function del(string ...$keys): int
    {
        if ($this->isPredis()) {
            return (int) $this->redis->del($keys);
        }

        return (int) $this->redis->del(...$keys);
    }

    private function hmset(string $key, array $data): void
    {
        if ($this->isPredis()) {
            $this->redis->hmset($key, $data);
        } else {
            $this->redis->hMSet($key, $data);
        }
    }

    private function isPredis(): bool
    {
        return $this->redis instanceof \Predis\ClientInterface;
    }

    private function rpush(string $key, string $value): void
    {
        if ($this->isPredis()) {
            $this->redis->rpush($key, [$value]);
        } else {
            $this->redis->rpush($key, $value);
        }
    }

    private function sadd(string $key, string $value): void
    {
        if ($this->isPredis()) {
            $this->redis->sadd($key, [$value]);
        } else {
            $this->redis->sadd($key, $value);
        }
    }

    private function zadd(string $key, float|int $score, string $member): void
    {
        if ($this->isPredis()) {
            $this->redis->zadd($key, [$member => $score]);
        } else {
            $this->redis->zadd($key, $score, $member);
        }
    }

    /**
     * Generate a unique job ID.
     *
     * @return string Unique identifier.
     */
    private function generateId(): string
    {
        return uniqid('job_', true);
    }

    /**
     * Get Redis key for job data.
     *
     * @param  string  $id  Job ID.
     * @return string Redis key.
     */
    private function getJobKey(string $id): string
    {
        return "qatar:{$this->queue}:job:{$id}";
    }

    /**
     * Get Redis key for ready jobs list.
     *
     * @return string Redis key.
     */
    private function getReadyKey(): string
    {
        return "qatar:{$this->queue}:ready";
    }

    /**
     * Get Redis key for delayed jobs sorted set.
     *
     * @return string Redis key.
     */
    private function getDelayedKey(): string
    {
        return "qatar:{$this->queue}:delayed";
    }

    /**
     * Get Redis key for processing jobs set.
     *
     * @return string Redis key.
     */
    private function getProcessingKey(): string
    {
        return "qatar:{$this->queue}:processing";
    }

    /**
     * Get Redis key for job timeout tracking.
     *
     * @return string Redis key.
     */
    private function getTimeoutKey(): string
    {
        return "qatar:{$this->queue}:timeout";
    }
}
