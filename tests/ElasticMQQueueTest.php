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

class ElasticMQQueueTest extends TestCase
{
    private ElasticMQQueue $queue;

    protected function setUp(): void
    {
        $config = [
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'http://localhost:9324',
            'credentials' => [
                'key' => 'x',
                'secret' => 'x',
            ],
        ];

        $this->queue = new ElasticMQQueue($config, 'test_queue_'.uniqid());
        $this->queue->purge();

        // Give ElasticMQ time to process purge
        sleep(1);
    }

    protected function tearDown(): void
    {
        $this->queue->purge();
    }

    public function test_push_and_pop(): void
    {
        $id = $this->queue->push(ElasticMQTestJob::class, ['foo' => 'bar']);

        $this->assertNotEmpty($id);
        $this->assertStringStartsWith('job_', $id);

        $job = $this->queue->pop(5);

        $this->assertInstanceOf(JobPayload::class, $job);
        $this->assertEquals(ElasticMQTestJob::class, $job->job);
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
        $this->queue->push(ElasticMQTestJob::class, ['test' => 'data']);

        sleep(1); // Give ElasticMQ time to process

        $job = $this->queue->pop(0);

        $this->assertNotNull($job);
        $this->assertEquals(['test' => 'data'], $job->payload);
    }

    public function test_delayed_job(): void
    {
        $this->queue->push(ElasticMQTestJob::class, ['delayed' => true], 3);

        // Should not be available immediately
        $job = $this->queue->pop(1);
        $this->assertNull($job);

        // Wait for delay
        sleep(4);

        // Should be available now
        $job = $this->queue->pop(2);
        $this->assertNotNull($job);
        $this->assertEquals(['delayed' => true], $job->payload);
    }

    public function test_delayed_job_respects_sqs_max_delay(): void
    {
        // SQS max delay is 900 seconds (15 minutes)
        $id = $this->queue->push(ElasticMQTestJob::class, ['long_delay' => true], 1000);

        $this->assertNotEmpty($id);

        // Job should be delayed but not fail
        $job = $this->queue->pop(1);
        $this->assertNull($job);
    }

    public function test_ack_removes_job(): void
    {
        $this->queue->push(ElasticMQTestJob::class, ['test' => 'data']);

        sleep(1);

        $job = $this->queue->pop(2);
        $this->assertNotNull($job);

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
        $this->queue->push(ElasticMQTestJob::class, ['retry' => true]);

        sleep(1);

        $job = $this->queue->pop(2);
        $this->assertNotNull($job);

        $this->assertTrue($this->queue->nack($job->id, 0));

        // Job should be available again immediately
        $job2 = $this->queue->pop(2);
        $this->assertNotNull($job2);
    }

    public function test_nack_with_delay(): void
    {
        $this->queue->push(ElasticMQTestJob::class, ['retry' => true]);

        sleep(1);

        $job = $this->queue->pop(2);
        $this->assertNotNull($job);

        $this->assertTrue($this->queue->nack($job->id, 3));

        // Should not be available immediately
        $job2 = $this->queue->pop(1);
        $this->assertNull($job2);

        // Wait for delay
        sleep(4);

        // Should be available now
        $job3 = $this->queue->pop(2);
        $this->assertNotNull($job3);
    }

    public function test_nack_nonexistent_job(): void
    {
        $result = $this->queue->nack('nonexistent_job_id');

        $this->assertFalse($result);
    }

    public function test_size(): void
    {
        $initialSize = $this->queue->size();

        $this->queue->push(ElasticMQTestJob::class, ['one' => 1]);
        sleep(1);

        $size1 = $this->queue->size();
        $this->assertGreaterThan($initialSize, $size1);

        $this->queue->push(ElasticMQTestJob::class, ['two' => 2]);
        sleep(1);

        $size2 = $this->queue->size();
        $this->assertGreaterThan($size1, $size2);
    }

    public function test_purge(): void
    {
        $this->queue->push(ElasticMQTestJob::class, ['one' => 1]);
        $this->queue->push(ElasticMQTestJob::class, ['two' => 2]);

        sleep(1);

        $this->assertGreaterThan(0, $this->queue->size());

        $this->queue->purge();

        sleep(1);

        $this->assertEquals(0, $this->queue->size());
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

        $this->queue->push(ElasticMQTestJob::class, $complexPayload);

        sleep(1);

        $job = $this->queue->pop(2);

        $this->assertNotNull($job);
        $this->assertEquals($complexPayload, $job->payload);
    }

    public function test_empty_payload(): void
    {
        $this->queue->push(ElasticMQTestJob::class, []);

        sleep(1);

        $job = $this->queue->pop(2);

        $this->assertNotNull($job);
        $this->assertEquals([], $job->payload);
    }

    public function test_message_attributes(): void
    {
        $id = $this->queue->push(ElasticMQTestJob::class, ['test' => 'attributes']);

        sleep(1);

        $job = $this->queue->pop(2);

        $this->assertNotNull($job);
        $this->assertEquals(ElasticMQTestJob::class, $job->job);
    }

    public function test_concurrent_operations(): void
    {
        // Push multiple jobs
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->queue->push(ElasticMQTestJob::class, ['index' => $i]);
        }

        // All IDs should be unique
        $this->assertCount(5, array_unique($ids));

        sleep(1);

        // Should be able to retrieve jobs
        $this->assertGreaterThan(0, $this->queue->size());
    }

    public function test_long_polling(): void
    {
        $startTime = time();

        // Pop with 5 second wait time on empty queue
        $job = $this->queue->pop(5);

        $duration = time() - $startTime;

        $this->assertNull($job);
        // Should have waited approximately 5 seconds
        $this->assertGreaterThanOrEqual(4, $duration);
    }

    public function test_queue_creation_on_construct(): void
    {
        $config = [
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'http://localhost:9324',
            'credentials' => [
                'key' => 'x',
                'secret' => 'x',
            ],
        ];

        // Create queue with new name
        $newQueue = new ElasticMQQueue($config, 'brand_new_queue_'.uniqid());

        // Should be able to use it immediately
        $id = $newQueue->push(ElasticMQTestJob::class, ['new' => 'queue']);
        $this->assertNotEmpty($id);

        $newQueue->purge();
    }
}

/**
 * Test job class for ElasticMQ testing.
 */
class ElasticMQTestJob extends Job
{
    public function handle(array $payload): void
    {
        // Test implementation
    }
}
