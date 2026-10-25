<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Domain\AccountEmailTemplate;

/**
 * Details are short, non-secret facts the email states (a device name, how many recovery codes are left). Links and
 * tokens are never passed in: they are made when the email is sent.
 */
final readonly class RequestAccountEmailData
{
    /**
     * @param  array<string, string|int|bool>  $details
     */
    public function __construct(
        public AccountEmailTemplate $template,
        public string $userId,
        public array $details = [],
    ) {}
}
