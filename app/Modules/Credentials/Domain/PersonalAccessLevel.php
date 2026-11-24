<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * What a personal token may do, chosen when it is made. The token can never do more than its owner's role allows.
 */
enum PersonalAccessLevel: string
{
    case Read = 'read';
    case Draft = 'draft';
    case Publish = 'publish';

    /**
     * @return list<CredentialScope>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Read => [CredentialScope::FlagsRead],
            self::Draft => [CredentialScope::FlagsRead, CredentialScope::FlagsWrite],
            self::Publish => [CredentialScope::FlagsRead, CredentialScope::FlagsWrite, CredentialScope::Publish],
        };
    }
}
