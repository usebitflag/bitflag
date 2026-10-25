<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\RevokeOtherSessions;
use App\Modules\Identity\Actions\RevokeSession;
use App\Modules\Identity\Actions\RevokeTrustedBrowser;
use App\Modules\Identity\Data\RevokeOtherSessionsData;
use App\Modules\Identity\Data\RevokeSessionData;
use App\Modules\Identity\Queries\CurrentPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ending sessions and trusted browsers from account security. Every action works on the signed-in person's own only.
 */
final class SessionsController
{
    public function destroy(Request $request, RevokeSession $revoke, CurrentPerson $current, string $ref): RedirectResponse
    {
        $revoke->handle(new RevokeSessionData($this->personId($current), $ref, $request->session()->getId(), $request->ip(), $request->userAgent()));

        return back()->with('toast', ['message' => 'Session signed out.', 'tone' => 'neutral']);
    }

    public function destroyOthers(Request $request, RevokeOtherSessions $revoke, CurrentPerson $current): RedirectResponse
    {
        $revoke->handle(new RevokeOtherSessionsData($this->personId($current), $request->session()->getId(), $request->ip(), $request->userAgent()));

        return back()->with('toast', ['message' => 'Signed out everywhere else.', 'tone' => 'neutral']);
    }

    public function destroyTrustedBrowser(RevokeTrustedBrowser $revoke, CurrentPerson $current, string $id): RedirectResponse
    {
        $revoke->handle($this->personId($current), $id);

        return back()->with('toast', ['message' => 'Browser no longer trusted.', 'tone' => 'neutral']);
    }

    private function personId(CurrentPerson $current): string
    {
        $person = $current->find();
        abort_if($person === null, 403);

        return $person->id;
    }
}
