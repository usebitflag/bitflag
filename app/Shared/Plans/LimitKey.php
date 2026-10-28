<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * The plan limits an action can check. The list grows as features that are limited are built.
 */
enum LimitKey: string
{
    case Projects = 'projects';
    case EnvironmentsPerProject = 'environments_per_project';
}
