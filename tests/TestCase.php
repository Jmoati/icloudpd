<?php

namespace Tests;

use Foundation\Patch;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        Patch::clear();
        parent::tearDown();
    }
}
