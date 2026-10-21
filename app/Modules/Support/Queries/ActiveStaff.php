<?php

declare(strict_types=1);

namespace App\Modules\Support\Queries;

use App\Modules\Support\Models\StaffUser;
use Illuminate\Support\Facades\Auth;

/**
 * Whether the current admin-host session belongs to an active staff member.
 */
final class ActiveStaff
{
    public function isSignedIn(): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof StaffUser && $staff->status === 'active';
    }
}
