<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.host', env('DB_HOST', 'localhost'));
        $app['config']->set('database.connections.pgsql.port', env('DB_PORT', '5432'));
        $app['config']->set('database.connections.pgsql.database', env('DB_DATABASE', 'hatchloom_test'));
        $app['config']->set('database.connections.pgsql.username', env('DB_USERNAME', 'hatchloom'));
        $app['config']->set('database.connections.pgsql.password', env('DB_PASSWORD', 'secret'));
    }
}
