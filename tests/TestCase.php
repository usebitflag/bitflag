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

        config([
            'tenancy.global' => [...config('tenancy.global'), ...self::TEST_TABLES],
            'tenancy.tenant' => [...config('tenancy.tenant'), ...self::TEST_TENANT_TABLES],
        ]);
    }
}
