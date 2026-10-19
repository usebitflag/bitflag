<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tables created only for tests, classified here so the tenant table test still covers every table.
     */
    private const TEST_TABLES = ['macro_probe', 'public_id_probes'];

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app->make('migrator')->path(__DIR__.'/Support/migrations');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.global' => [...config('tenancy.global'), ...self::TEST_TABLES]]);
    }
}
