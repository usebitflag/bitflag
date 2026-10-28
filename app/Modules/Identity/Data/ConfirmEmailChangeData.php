<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class ConfirmEmailChangeData
{
    /**
     * @param  string  $userId  the person the link was made for
     * @param  string  $actingUserId  the person who opened it
     */
    public function __construct(
        public string $userId,
        public string $hash,
        public string $actingUserId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
