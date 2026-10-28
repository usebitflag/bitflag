<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * What the format of a presented secret says about it, without a lookup.
 */
final readonly class ParsedToken
{
    public function __construct(public CredentialType $type, public ?string $tag) {}
}
