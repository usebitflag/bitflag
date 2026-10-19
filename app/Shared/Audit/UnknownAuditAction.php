<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use LogicException;

/**
 * An audit action name that no module registered, usually a typo.
 */
final class UnknownAuditAction extends LogicException
{
    public static function named(string $action): self
    {
        return new self("Audit action \"{$action}\" is not registered. Register it in the module's service provider.");
    }
}
