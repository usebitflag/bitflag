<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

/**
 * A project found from an address: enough to authorize against and to describe.
 */
final readonly class ProjectReference
{
    public function __construct(public string $id, public string $organizationId, public string $status) {}
}
