<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * A person's role in one project.
 */
enum ProjectRole: string
{
    case Owner = 'owner';
    case Publisher = 'publisher';
    case Developer = 'developer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
