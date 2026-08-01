<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.mongodb.database');

        if ($database !== 'wprint3d_testing') {
            throw new \RuntimeException('Tests must run against the isolated wprint3d_testing MongoDB database.');
        }
    }
}
