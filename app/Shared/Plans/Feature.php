<?php

declare(strict_types=1);

namespace App\Shared\Plans;

/**
 * Paid features an action can require. The list grows as features that are gated are built.
 */
enum Feature: string
{
    case RequiredApprovals = 'required_approvals';
}
