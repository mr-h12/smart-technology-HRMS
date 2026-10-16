<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // Runs after the app boots and before RefreshDatabase: never wipe anything but the test database.
    protected function setUpTraits()
    {
        $name = DB::connection()->getDatabaseName();

        if ($name !== 'hrms_test') {
            throw new RuntimeException("Refusing to run tests on database '{$name}': only hrms_test is allowed.");
        }

        return parent::setUpTraits();
    }
}
