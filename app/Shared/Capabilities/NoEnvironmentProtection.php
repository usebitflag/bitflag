<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

final class NoEnvironmentProtection implements EnvironmentProtection
{
    public function isProtected(EnvironmentScope $environment): bool
    {
        return false;
    }
}
