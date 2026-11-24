<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

enum RevokeReason: string
{
    case Manual = 'manual';
    case RotationCompleted = 'rotation_completed';
    case EnvironmentArchived = 'environment_archived';
    case ProjectDeleted = 'project_deleted';
    case MemberRemoved = 'member_removed';
    case AccountDeleted = 'account_deleted';
}
