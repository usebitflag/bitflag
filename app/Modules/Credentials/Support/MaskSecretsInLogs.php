<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Log channel tap: adds the processor that masks credential-shaped text to a channel. Laravel's single, daily, syslog and
 * similar drivers ignore a `processors` setting, so every channel in config/logging.php uses this tap instead (the monolog
 * channels list the processor directly).
 */
final class MaskSecretsInLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSecrets);
        }
    }
}
