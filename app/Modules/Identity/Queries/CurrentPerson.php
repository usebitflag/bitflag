<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Data\SignedInPerson;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Reads the person signed in to the portal, or null when nobody is.
 */
final class CurrentPerson
{
    public function find(): ?SignedInPerson
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User
            ? new SignedInPerson($user->id, $user->name, $user->email, $user->last_organization_id, $user->last_project_id, $user->last_environment_id)
            : null;
    }
}
