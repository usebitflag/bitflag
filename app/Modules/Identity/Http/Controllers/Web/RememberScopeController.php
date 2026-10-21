<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\RememberLastScope;
use App\Modules\Identity\Data\RememberLastScopeData;
use App\Shared\Actors\Actor;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
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
            'organization_id' => ['required', 'string', 'max:64'],
            'project_id' => ['required', 'string', 'max:64'],
            'environment_id' => ['required', 'string', 'max:64'],
        ]);

        // The portal only knows public identifiers. A wrong prefix or a malformed value is a 404.
        $action->handle($actor, new RememberLastScopeData(
            PublicId::decode(IdPrefix::Organization, $request->string('organization_id')->value()),
            PublicId::decode(IdPrefix::Project, $request->string('project_id')->value()),
            PublicId::decode(IdPrefix::Environment, $request->string('environment_id')->value()),
        ));

        return response()->noContent();
    }
}
