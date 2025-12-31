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

class WorkerOptionsTest extends TestCase
{
    public function test_default_values(): void
    {
        $options = new WorkerOptions;

        $this->assertEquals(3, $options->sleep);
        $this->assertNull($options->maxJobs);
        $this->assertNull($options->maxTime);
        $this->assertEquals(128, $options->memory);
        $this->assertFalse($options->stopOnEmpty);
    }

    public function test_custom_values(): void
    {
        $options = new WorkerOptions(
            sleep: 5,
            maxJobs: 100,
            maxTime: 3600,
            memory: 256,
            stopOnEmpty: true,
        );

        $this->assertEquals(5, $options->sleep);
        $this->assertEquals(100, $options->maxJobs);
        $this->assertEquals(3600, $options->maxTime);
        $this->assertEquals(256, $options->memory);
        $this->assertTrue($options->stopOnEmpty);
    }

    public function test_partial_custom_values(): void
    {
        $options = new WorkerOptions(
            maxJobs: 50,
            stopOnEmpty: true,
        );

        $this->assertEquals(3, $options->sleep);
        $this->assertEquals(50, $options->maxJobs);
        $this->assertNull($options->maxTime);
        $this->assertEquals(128, $options->memory);
        $this->assertTrue($options->stopOnEmpty);
    }

    public function test_readonly_properties(): void
    {
        $options = new WorkerOptions;

        $reflection = new \ReflectionClass($options);
        $this->assertTrue($reflection->isReadOnly());
    }
}
