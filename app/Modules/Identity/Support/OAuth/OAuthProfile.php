<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

use App\Modules\Identity\Data\SocialProvider;

/**
 * Who the provider says the person is. `email` is set only when the provider vouches for it; an unverified address is
 * never carried, so nothing downstream can use one by mistake.
 */
final readonly class OAuthProfile
{
    public function __construct(
        public SocialProvider $provider,
        public string $id,
        public ?string $email,
        public string $name,
    ) {}
}
