<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Identity\Queries\TwoFactorSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account security: password, two-factor, recovery codes.
 */
final class SecurityController
{
    public function show(Request $request, CurrentPerson $current, TwoFactorSummary $summary): Response
    {
        $person = $current->find();
        abort_if($person === null, 403);

        $requiredBy = $request->query('required_by');

        return Inertia::render('account/Security', [
            'twoFactor' => $summary->for($person->id),
            'requiredBy' => is_string($requiredBy) && mb_strlen($requiredBy) <= 120 ? $requiredBy : null,
        ]);
    }
}
