<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Data\ResolvedCredential;
use App\Modules\Credentials\Models\Credential;

/**
 * Builds the non-secret summary the cache holds and the resolver returns.
 */
final class CredentialSummaries
{
    public static function of(Credential $credential): ResolvedCredential
    {
        return new ResolvedCredential(
            $credential->id,
            $credential->type,
            $credential->organization_id,
            $credential->project_id,
            $credential->environment_id,
            $credential->user_id,
            $credential->scopes,
            $credential->status,
            $credential->expires_at === null ? null : (int) $credential->expires_at->format('Uv'),
            $credential->overlap_ends_at === null ? null : (int) $credential->overlap_ends_at->format('Uv'),
            $credential->audience,
            $credential->label,
            $credential->display_prefix,
            $credential->last4,
            $credential->created_by,
        );
    }
}
