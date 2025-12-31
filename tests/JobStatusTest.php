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

class JobStatusTest extends TestCase
{
    public function test_enum_values(): void
    {
        $this->assertEquals('pending', JobStatus::PENDING->value);
        $this->assertEquals('processing', JobStatus::PROCESSING->value);
        $this->assertEquals('completed', JobStatus::COMPLETED->value);
        $this->assertEquals('failed', JobStatus::FAILED->value);
        $this->assertEquals('retrying', JobStatus::RETRYING->value);
    }

    public function test_all_cases_exist(): void
    {
        $cases = JobStatus::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(JobStatus::PENDING, $cases);
        $this->assertContains(JobStatus::PROCESSING, $cases);
        $this->assertContains(JobStatus::COMPLETED, $cases);
        $this->assertContains(JobStatus::FAILED, $cases);
        $this->assertContains(JobStatus::RETRYING, $cases);
    }

    public function test_from_string(): void
    {
        $this->assertEquals(JobStatus::PENDING, JobStatus::from('pending'));
        $this->assertEquals(JobStatus::PROCESSING, JobStatus::from('processing'));
        $this->assertEquals(JobStatus::COMPLETED, JobStatus::from('completed'));
        $this->assertEquals(JobStatus::FAILED, JobStatus::from('failed'));
        $this->assertEquals(JobStatus::RETRYING, JobStatus::from('retrying'));
    }
}
