<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\DeleteAccount;
use App\Modules\Identity\Data\DeleteAccountData;
use App\Modules\Identity\Http\Requests\DeleteAccountRequest;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Queries\SoleOwnerships;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deleting your own account. The page says what stands in the way, if anything.
 */
final class AccountDeletionController
{
    public function __construct(private readonly CurrentPerson $current) {}

    /** GET /account/delete */
    public function show(SoleOwnerships $sole): Response
    {
        $person = $this->current->find();
        abort_if($person === null, 403);

        return Inertia::render('account/Delete', [
            'email' => $person->email,
            'soleOwnerOf' => $sole->organizationNames($person->id),
        ]);
    }

    /** DELETE /account */
    public function destroy(DeleteAccountRequest $request, DeleteAccount $delete, StatefulGuard $guard): RedirectResponse
    {
        $person = $this->current->find();
        abort_if($person === null, 403);

        try {
            $delete->handle(new DeleteAccountData(
                $person->id,
                $request->string('password')->toString(),
                $request->string('email')->toString(),
                $request->ip(),
                $request->userAgent(),
            ));
        } catch (LastOwner $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        $this->signOut($request, $guard);

        return redirect('/signin')->with('toast', ['message' => 'Your account was deleted.', 'tone' => 'neutral']);
    }

    private function signOut(Request $request, StatefulGuard $guard): void
    {
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
