<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeVite;
use Tests\Support\TenantQueryGuard;
use Tests\TestCase;

require_once __DIR__.'/Support/tenancy.php';
require_once __DIR__.'/Support/people.php';
require_once __DIR__.'/Support/two_factor.php';
require_once __DIR__.'/Support/invitations.php';
require_once __DIR__.'/Support/portal.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // The root view calls @vite; tests check responses, not assets, so no build manifest is needed.
        FakeVite::install();
        TenantQueryGuard::start();
    })
    ->afterEach(fn () => TenantQueryGuard::assertClean())
    ->in('Feature');

/**
 * The application modules, in dependency-neutral order.
 *
 * @return list<string>
 */
function bitflag_modules(): array
{
    return [
        'Identity', 'Organizations', 'Projects', 'Environments', 'Flags', 'Targeting', 'Publishing', 'Credentials',
        'Experiments', 'Analytics', 'Integrations', 'Audit', 'Billing', 'Notifications', 'Support',
    ];
}

// Tests that need committed data, such as concurrency between two connections, cannot run inside the transaction
// RefreshDatabase wraps around a test, so they truncate the tables afterwards instead.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () {
        FakeVite::install();
        TenantQueryGuard::start();
    })
    ->afterEach(fn () => TenantQueryGuard::assertClean())
    ->in('Concurrency');
