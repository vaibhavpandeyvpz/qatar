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

class JobPayloadTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $payload = new JobPayload(
            id: 'job_123',
            job: 'TestJob',
            payload: ['foo' => 'bar'],
            attempts: 2,
            availableAt: 1234567890,
        );

        $this->assertEquals('job_123', $payload->id);
        $this->assertEquals('TestJob', $payload->job);
        $this->assertEquals(['foo' => 'bar'], $payload->payload);
        $this->assertEquals(2, $payload->attempts);
        $this->assertEquals(1234567890, $payload->availableAt);
    }

    public function test_default_values(): void
    {
        $payload = new JobPayload(
            id: 'job_456',
            job: 'AnotherJob',
            payload: ['test' => 'data'],
        );

        $this->assertEquals(0, $payload->attempts);
        $this->assertEquals(0, $payload->availableAt);
    }

    public function test_readonly_properties(): void
    {
        $payload = new JobPayload(
            id: 'job_789',
            job: 'ReadonlyTest',
            payload: [],
        );

        // Verify properties are readonly by checking reflection
        $reflection = new \ReflectionClass($payload);
        $this->assertTrue($reflection->isReadOnly());
    }
}
