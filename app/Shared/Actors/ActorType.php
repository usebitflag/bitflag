<?php

declare(strict_types=1);

namespace App\Shared\Actors;

/**
 * Who performed an action. A personal access token is still a `user`: the audit record notes the token it came through.
 */
enum ActorType: string
{
    case User = 'user';
    case ManagementToken = 'management_token';
    case System = 'system';
    case Support = 'support';
}
