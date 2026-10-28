<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

final readonly class MarkEmailVerifiedData
{
    /**
     * @param  string  $userId  The person the link was made for.
     * @param  string  $actingUserId  The person who opened it.
     */
    public function __construct(public string $userId, public string $hash, public string $actingUserId) {}
}
