<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Events;

/**
 * Someone no longer belongs to a project, whether removed or by leaving. What depended on belonging, such as their personal
 * tokens for that project, is revoked in response.
 */
final readonly class MemberRemovedFromProject
{
    public function __construct(public string $organizationId, public string $projectId, public string $userId) {}
}
