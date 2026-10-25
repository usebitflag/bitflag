<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\MarkEmailVerified;
use App\Modules\Identity\Data\MarkEmailVerifiedData;
use App\Modules\Identity\Queries\CurrentPerson;
use Illuminate\Http\RedirectResponse;

/**
 * Opens a confirmation link. The route is signed and expires, and the person must be signed in as the account it is for.
 */
final class VerifyEmailController
{
    public function __invoke(MarkEmailVerified $verify, CurrentPerson $current, string $id, string $hash): RedirectResponse
    {
        $person = $current->find();
        abort_if($person === null, 403);

        $confirmed = $verify->handle(new MarkEmailVerifiedData($id, $hash, $person->id));

        return redirect('/')->with('toast', [
            'message' => $confirmed ? 'Your email is confirmed.' : 'Your email is already confirmed.',
            'tone' => 'neutral',
        ]);
    }
}
