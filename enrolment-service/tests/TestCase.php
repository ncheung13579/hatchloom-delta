<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PostgreSQL sequences don't reset on transaction rollback (used by
        // RefreshDatabase). Reset them so auto-increment IDs start from 1
        // each test, matching the mock auth TOKEN_MAP expectations.
        DB::statement("SELECT setval('schools_id_seq', 1, false)");
        DB::statement("SELECT setval('users_id_seq', 1, false)");
    }
}
