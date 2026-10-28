<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\ChangePassword;
use App\Modules\Identity\Actions\RequestPasswordReset;
use App\Modules\Identity\Data\ChangePasswordData;
use App\Modules\Identity\Data\RequestPasswordResetData;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Queries\CurrentPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Changing the password from account settings.
 */
final class PasswordController
{
    public function update(ChangePasswordRequest $request, ChangePassword $change, CurrentPerson $current): RedirectResponse
    {
        $person = $current->find();
        abort_if($person === null, 403);

        $change->handle(new ChangePasswordData(
            $person->id,
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
            $request->session()->getId(),
            $request->ip(),
            $request->userAgent(),
        ));

        // The id of the session that made the change is replaced, and the old one destroyed, so an id someone saw earlier
        // is worthless.
        $request->session()->regenerate(true);

        return back()->with('toast', ['message' => 'Your password was changed. Other sessions were signed out.', 'tone' => 'neutral']);
    }

    /**
     * Emails the signed-in person the usual reset link. It is how someone who signed up with a provider, and so has no
     * password, sets one.
     */
    public function sendLink(Request $request, RequestPasswordReset $reset, CurrentPerson $current): RedirectResponse
    {
        $person = $current->find();
        abort_if($person === null, 403);

        $reset->handle(new RequestPasswordResetData($person->email, $request->ip(), $request->userAgent()));

        return back()->with('toast', ['message' => "We sent a link to {$person->email} to set a password.", 'tone' => 'neutral']);
    }
}
