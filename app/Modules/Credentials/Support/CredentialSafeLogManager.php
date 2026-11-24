<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

/** Applies credential redaction to Laravel's fallback logger as well as configured channels. */
final class CredentialSafeLogManager extends LogManager
{
    protected function createEmergencyLogger(): LoggerInterface
    {
        $logger = parent::createEmergencyLogger();

        if ($logger instanceof Logger) {
            (new MaskSecretsInLogs)($logger);
        }

        return $logger;
    }
}
