<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Models\TestWidget;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tables created only for tests, classified here so the tenant table test still covers every table.
     */
    private const TEST_TABLES = ['macro_probe', 'public_id_probes'];

    /** Tenant tables that exist only for tests, with their models. */
    private const TEST_TENANT_TABLES = ['test_widgets' => TestWidget::class];

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app->make('migrator')->path(__DIR__.'/Support/migrations');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tests that flush Redis must never touch the development stack's database 0, and parallel workers must not share one.
        // Each worker gets its own database from 5 to 14.
        config(['database.redis.default.database' => 5 + ((int) (getenv('TEST_TOKEN') ?: 0) % 10)]);
        $this->app->make('redis')->purge();

        config([
            'tenancy.global' => [...config('tenancy.global'), ...self::TEST_TABLES],
            'tenancy.tenant' => [...config('tenancy.tenant'), ...self::TEST_TENANT_TABLES],
        ]);
    }
}
