<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * A freshly made secret with the parts that are safe to store and show.
 */
final readonly class GeneratedToken
{
    public function __construct(
        public string $secret,
        public CredentialType $type,
        /** Everything before the random part, for example `bf_srv_prod_`. */
        public string $displayPrefix,
        public string $last4,
    ) {}
}
