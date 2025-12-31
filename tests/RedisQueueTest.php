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

class RedisQueueTest extends TestCase
{
    private RedisQueue $queue;

    protected function setUp(): void
    {
        $redis = new \Predis\Client('tcp://127.0.0.1:6379');
        $this->queue = new RedisQueue($redis, 'test_queue_'.uniqid());
        $this->queue->purge();
    }

    protected function tearDown(): void
    {
        $this->queue->purge();
    }

    public function test_push_and_pop(): void
    {
        $id = $this->queue->push(TestJob::class, ['foo' => 'bar']);

        $this->assertNotEmpty($id);
        $this->assertStringStartsWith('job_', $id);

        $job = $this->queue->pop();

        $this->assertInstanceOf(JobPayload::class, $job);
        $this->assertEquals($id, $job->id);
        $this->assertEquals(TestJob::class, $job->job);
        $this->assertEquals(['foo' => 'bar'], $job->payload);
        $this->assertEquals(1, $job->attempts);
    }

    public function test_pop_returns_null_when_empty(): void
    {
        $job = $this->queue->pop(1);

        $this->assertNull($job);
    }

    public function test_pop_with_zero_timeout(): void
    {
        $this->queue->push(TestJob::class, ['test' => 'data']);

        $job = $this->queue->pop(0);

        $this->assertNotNull($job);
        $this->assertEquals(['test' => 'data'], $job->payload);
    }

    public function test_delayed_job(): void
    {
        $this->queue->push(TestJob::class, ['delayed' => true], 2);

        // Should not be available immediately
        $job = $this->queue->pop(1);
        $this->assertNull($job);

        // Wait for delay
        sleep(3);

        // Should be available now
        $job = $this->queue->pop();
        $this->assertNotNull($job);
        $this->assertEquals(['delayed' => true], $job->payload);
    }

    public function test_delayed_job_with_zero_delay(): void
    {
        $id = $this->queue->push(TestJob::class, ['immediate' => true], 0);

        // Should be available immediately
        $job = $this->queue->pop();
        $this->assertNotNull($job);
        $this->assertEquals($id, $job->id);
    }

    public function test_ack_removes_job(): void
    {
        $id = $this->queue->push(TestJob::class, ['test' => 'data']);
        $job = $this->queue->pop();

        $this->assertTrue($this->queue->ack($job->id));

        // Job should not be available again
        $job2 = $this->queue->pop(1);
        $this->assertNull($job2);
    }

    public function test_ack_nonexistent_job(): void
    {
        $result = $this->queue->ack('nonexistent_job_id');

        $this->assertFalse($result);
    }

    public function test_nack_requeues_job(): void
    {
        $id = $this->queue->push(TestJob::class, ['retry' => true]);
        $job = $this->queue->pop();

        $this->assertTrue($this->queue->nack($job->id));

        // Job should be available again
        $job2 = $this->queue->pop();
        $this->assertNotNull($job2);
        $this->assertEquals($job->id, $job2->id);
        $this->assertEquals(2, $job2->attempts);
    }

    public function test_nack_with_delay(): void
    {
        $id = $this->queue->push(TestJob::class, ['retry' => true]);
        $job = $this->queue->pop();

        $this->assertTrue($this->queue->nack($job->id, 2));

        // Should not be available immediately
        $job2 = $this->queue->pop(1);
        $this->assertNull($job2);

        // Wait for delay
        sleep(3);

        // Should be available now
        $job3 = $this->queue->pop();
        $this->assertNotNull($job3);
        $this->assertEquals($job->id, $job3->id);
    }

    public function test_nack_with_zero_delay(): void
    {
        $id = $this->queue->push(TestJob::class, ['retry' => true]);
        $job = $this->queue->pop();

        $this->assertTrue($this->queue->nack($job->id, 0));

        // Should be available immediately
        $job2 = $this->queue->pop();
        $this->assertNotNull($job2);
        $this->assertEquals($job->id, $job2->id);
    }

    public function test_nack_nonexistent_job(): void
    {
        $result = $this->queue->nack('nonexistent_job_id');

        $this->assertFalse($result);
    }

    public function test_size(): void
    {
        $this->assertEquals(0, $this->queue->size());

        $this->queue->push(TestJob::class, ['one' => 1]);
        $this->assertEquals(1, $this->queue->size());

        $this->queue->push(TestJob::class, ['two' => 2]);
        $this->assertEquals(2, $this->queue->size());

        $this->queue->pop();
        $this->assertEquals(1, $this->queue->size());
    }

    public function test_size_includes_delayed_jobs(): void
    {
        $this->queue->push(TestJob::class, ['immediate' => true]);
        $this->queue->push(TestJob::class, ['delayed' => true], 10);

        $this->assertEquals(2, $this->queue->size());
    }

    public function test_purge(): void
    {
        $this->queue->push(TestJob::class, ['one' => 1]);
        $this->queue->push(TestJob::class, ['two' => 2]);
        $this->queue->push(TestJob::class, ['three' => 3], 10);

        $this->assertEquals(3, $this->queue->size());

        $this->queue->purge();

        $this->assertEquals(0, $this->queue->size());
    }

    public function test_purge_with_processing_jobs(): void
    {
        $this->queue->push(TestJob::class, ['one' => 1]);
        $this->queue->push(TestJob::class, ['two' => 2]);

        // Pop one job (now processing)
        $job = $this->queue->pop();

        $this->queue->purge();

        $this->assertEquals(0, $this->queue->size());
    }

    public function test_multiple_jobs_fifo_order(): void
    {
        $this->queue->push(TestJob::class, ['order' => 1]);
        $this->queue->push(TestJob::class, ['order' => 2]);
        $this->queue->push(TestJob::class, ['order' => 3]);

        $job1 = $this->queue->pop();
        $job2 = $this->queue->pop();
        $job3 = $this->queue->pop();

        $this->assertEquals(1, $job1->payload['order']);
        $this->assertEquals(2, $job2->payload['order']);
        $this->assertEquals(3, $job3->payload['order']);
    }

    public function test_complex_payload_data(): void
    {
        $complexPayload = [
            'string' => 'test',
            'number' => 123,
            'float' => 45.67,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => [
                'key' => 'value',
                'deep' => [
                    'data' => 'here',
                ],
            ],
        ];

        $this->queue->push(TestJob::class, $complexPayload);
        $job = $this->queue->pop();

        $this->assertEquals($complexPayload, $job->payload);
    }

    public function test_job_attempts_increment(): void
    {
        $id = $this->queue->push(TestJob::class, ['test' => 'retry']);

        $job1 = $this->queue->pop();
        $this->assertEquals(1, $job1->attempts);

        $this->queue->nack($job1->id);

        $job2 = $this->queue->pop();
        $this->assertEquals(2, $job2->attempts);

        $this->queue->nack($job2->id);

        $job3 = $this->queue->pop();
        $this->assertEquals(3, $job3->attempts);
    }

    public function test_concurrent_queue_operations(): void
    {
        // Simulate multiple publishers
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->queue->push(TestJob::class, ['index' => $i]);
        }

        $this->assertEquals(10, $this->queue->size());

        // All IDs should be unique
        $this->assertCount(10, array_unique($ids));
    }

    public function test_empty_payload(): void
    {
        $id = $this->queue->push(TestJob::class, []);
        $job = $this->queue->pop();

        $this->assertEquals([], $job->payload);
    }

    public function test_different_queue_names_are_isolated(): void
    {
        $redis = new \Predis\Client('tcp://127.0.0.1:6379');
        $queue1 = new RedisQueue($redis, 'queue1');
        $queue2 = new RedisQueue($redis, 'queue2');

        $queue1->purge();
        $queue2->purge();

        $queue1->push(TestJob::class, ['queue' => 1]);
        $queue2->push(TestJob::class, ['queue' => 2]);

        $job1 = $queue1->pop();
        $job2 = $queue2->pop();

        $this->assertEquals(1, $job1->payload['queue']);
        $this->assertEquals(2, $job2->payload['queue']);

        $queue1->purge();
        $queue2->purge();
    }

    public function test_available_at_timestamp(): void
    {
        $beforePush = time();
        $this->queue->push(TestJob::class, ['test' => 'timestamp']);
        $afterPush = time();

        $job = $this->queue->pop();

        $this->assertGreaterThanOrEqual($beforePush, $job->availableAt);
        $this->assertLessThanOrEqual($afterPush, $job->availableAt);
    }

    public function test_delayed_job_migration(): void
    {
        // Push delayed job
        $this->queue->push(TestJob::class, ['delayed' => true], 1);

        // Verify it's not immediately available
        $this->assertNull($this->queue->pop(0));

        // Wait for migration
        sleep(2);

        // Should be migrated to ready queue
        $job = $this->queue->pop();
        $this->assertNotNull($job);
        $this->assertEquals(['delayed' => true], $job->payload);
    }

    public function test_automatic_driver_detection_with_dsn(): void
    {
        $queue = new RedisQueue('tcp://127.0.0.1:6379', 'dsn_test');
        $queue->push(TestJob::class, ['dsn' => 'test']);

        $job = $queue->pop();
        $this->assertNotNull($job);
        $this->assertEquals(['dsn' => 'test'], $job->payload);

        $queue->purge();
    }
}

/**
 * Test job class for testing purposes.
 */
class TestJob extends Job
{
    public function handle(array $payload): void
    {
        // Test implementation
    }
}
