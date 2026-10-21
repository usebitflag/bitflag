<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * A person's role in an organization. An Org Owner manages billing, members, security and the organization itself; a
 * Member sees only the projects they belong to.
 */
enum OrgRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Org Owner',
            self::Member => 'Member',
        };
    }
}
