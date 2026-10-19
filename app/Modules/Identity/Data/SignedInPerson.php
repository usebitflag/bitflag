<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * The person behind the current portal session.
 */
final readonly class SignedInPerson
{
    public function __construct(public string $id, public string $name) {}
}
