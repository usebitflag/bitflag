<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\RememberLastScope;
use App\Modules\Identity\Data\RememberLastScopeData;
use App\Shared\Actors\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Called by the portal after navigation to remember where the person was.
 */
final class RememberScopeController
{
    public function store(Request $request, Actor $actor, RememberLastScope $action): Response
    {
        $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'project_id' => ['required', 'string', 'size:26'],
            'environment_id' => ['required', 'string', 'size:26'],
        ]);

        $action->handle($actor, new RememberLastScopeData(
            $request->string('organization_id')->value(),
            $request->string('project_id')->value(),
            $request->string('environment_id')->value(),
        ));

        return response()->noContent();
    }
}
