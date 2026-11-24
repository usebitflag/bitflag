<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * What a credential may do. SDK keys use the delivery scopes; management keys and personal tokens use the API scopes.
 */
enum CredentialScope: string
{
    case SnapshotRead = 'snapshot:read';
    case Evaluate = 'evaluate';
    case EventsWrite = 'events:write';
    case FlagsRead = 'flags:read';
    case SensitiveRead = 'sensitive:read';
    case FlagsWrite = 'flags:write';
    case Publish = 'publish';

    /**
     * The scopes a credential type may hold.
     *
     * @return list<self>
     */
    public static function catalog(CredentialType $type): array
    {
        return match ($type) {
            CredentialType::Server => [self::SnapshotRead, self::Evaluate, self::EventsWrite],
            CredentialType::Browser => [self::SnapshotRead, self::EventsWrite],
            CredentialType::Management, CredentialType::Personal => [self::FlagsRead, self::SensitiveRead, self::FlagsWrite, self::Publish],
        };
    }

    /**
     * What a new credential of the type gets when the person changes nothing.
     *
     * @return list<self>
     */
    public static function defaults(CredentialType $type): array
    {
        return match ($type) {
            CredentialType::Server, CredentialType::Browser => [self::SnapshotRead],
            CredentialType::Management => [self::FlagsRead],
            CredentialType::Personal => [self::FlagsRead, self::FlagsWrite],
        };
    }
}
