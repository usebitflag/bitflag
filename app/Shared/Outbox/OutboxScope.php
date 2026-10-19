<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

/**
 * Whose event a message is. `organization` messages are tenant events; `account` messages concern a person before or
 * outside any organization; `system` messages are platform maintenance.
 */
enum OutboxScope: string
{
    case Organization = 'organization';
    case Account = 'account';
    case System = 'system';
}
