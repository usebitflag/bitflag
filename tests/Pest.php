<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

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
