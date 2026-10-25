<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

/**
 * The reset link is expired, was already used, or is not for this account. The message is the same for all three.
 */
final class InvalidResetToken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This link has expired or was already used. Request a new one.');
    }
}
