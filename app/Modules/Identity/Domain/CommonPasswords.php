<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * A list of passwords that are too common to accept. It is a local list: no password or hash of one is sent anywhere.
 */
interface CommonPasswords
{
    public function contains(string $password): bool;
}
