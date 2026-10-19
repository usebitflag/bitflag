<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Web;

use App\Http\Portal\PortalScope;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project Overview. It is a blank page with a header for now; its content arrives with the features it summarizes.
 */
final class OverviewController
{
    public function show(PortalScope $scope): Response
    {
        return Inertia::render('overview/Show', [
            'heading' => 'Overview · '.($scope->environment->name ?? ''),
        ]);
    }
}
