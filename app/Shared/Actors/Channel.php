<?php

declare(strict_types=1);

namespace App\Shared\Actors;

/**
 * Where a request came from. Recorded on every audit entry.
 */
enum Channel: string
{
    case Dashboard = 'dashboard';
    case Api = 'api';
    case Cli = 'cli';
    case Mcp = 'mcp';
    case System = 'system';
    case Support = 'support';
}
