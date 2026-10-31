<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** RefreshDatabase drops every table: refuse to boot tests against anything but a *_test database. */
    public function createApplication()
    {
        $app = parent::createApplication();
        $database = (string) $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests must run against a *_test database, not [{$database}].");
        }

        return $app;
    }
}
