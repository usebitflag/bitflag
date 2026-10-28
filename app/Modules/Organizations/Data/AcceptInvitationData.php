<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class AcceptInvitationData
{
    /**
     * @param  string|null  $signedInUserId  the person accepting while signed in, or null to create an account
     */
    public function __construct(
        public string $token,
        public ?string $signedInUserId = null,
        public ?string $name = null,
        public ?string $password = null,
    ) {}
}
