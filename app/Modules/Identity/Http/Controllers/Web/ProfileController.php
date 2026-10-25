<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\ConfirmEmailChange;
use App\Modules\Identity\Actions\RequestEmailChange;
use App\Modules\Identity\Actions\UpdateProfile;
use App\Modules\Identity\Data\ConfirmEmailChangeData;
use App\Modules\Identity\Data\RequestEmailChangeData;
use App\Modules\Identity\Data\UpdateProfileData;
use App\Modules\Identity\Http\Requests\RequestEmailChangeRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Identity\Queries\ProfileSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account profile: name, time zone and email address. Changing the address asks for the password, and the new address must
 * confirm itself from a link before the account uses it.
 */
final class ProfileController
{
    public function __construct(private readonly CurrentPerson $current) {}

    /** GET /account/profile */
    public function show(ProfileSummary $profile): Response
    {
        return Inertia::render('account/Profile', [
            'profile' => $profile->for($this->personId()),
            'timezones' => $profile->timezones(),
        ]);
    }

    /** PUT /account/profile */
    public function update(UpdateProfileRequest $request, UpdateProfile $update): RedirectResponse
    {
        $update->handle(new UpdateProfileData($this->personId(), $request->string('name')->toString(), $request->string('timezone')->toString()));

        return back()->with('toast', ['message' => 'Profile saved.', 'tone' => 'neutral']);
    }

    /** POST /account/email */
    public function requestEmailChange(RequestEmailChangeRequest $request, RequestEmailChange $change): RedirectResponse
    {
        $change->handle(new RequestEmailChangeData($this->personId(), $request->string('password')->toString(), $request->string('email')->toString()));

        // The same answer whether or not the address is already in use.
        return back()->with('toast', ['message' => 'We sent a link to the new address. Your email stays the same until you confirm it.', 'tone' => 'neutral']);
    }

    /** GET /account/email/confirm/{id}/{hash} */
    public function confirmEmailChange(Request $request, ConfirmEmailChange $confirm, string $id, string $hash): RedirectResponse
    {
        try {
            $changed = $confirm->handle(new ConfirmEmailChangeData($id, $hash, $this->personId(), $request->ip(), $request->userAgent()));
        } catch (ValidationException $e) {
            return redirect('/account/profile')->withErrors($e->errors());
        }

        return redirect('/account/profile')->with('toast', [
            'message' => $changed ? 'Your email address was changed.' : 'This link has expired or was already used.',
            'tone' => 'neutral',
        ]);
    }

    private function personId(): string
    {
        $person = $this->current->find();
        abort_if($person === null, 403);

        return $person->id;
    }
}
