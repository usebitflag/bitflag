<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\ChangeUnverifiedEmail;
use App\Modules\Identity\Actions\ResendVerificationEmail;
use App\Modules\Identity\Data\ChangeUnverifiedEmailData;
use App\Modules\Identity\Http\Requests\ChangeEmailRequest;
use App\Modules\Identity\Queries\CurrentPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Check your email" after sign-up: the page itself, fixing a typo and sending the email again. It is the same page for a
 * new and an existing address.
 */
final class CheckEmailController
{
    public function show(Request $request, CurrentPerson $current): Response|RedirectResponse
    {
        $person = $current->find();
        $email = $request->session()->get(SignUpController::SESSION_EMAIL, $person?->email);

        if (! is_string($email)) {
            return redirect('/signup');
        }

        return Inertia::render('auth/CheckEmail', [
            'maskedEmail' => self::mask($email),
            'canContinue' => $person !== null,
        ]);
    }

    public function change(ChangeEmailRequest $request, ChangeUnverifiedEmail $change, CurrentPerson $current): RedirectResponse
    {
        $person = $current->find();
        $email = $request->string('email')->toString();

        if ($person !== null) {
            $change->handle(new ChangeUnverifiedEmailData($person->id, $email));
        }

        $request->session()->put(SignUpController::SESSION_EMAIL, mb_strtolower(trim($email)));

        return redirect('/signup/check-email');
    }

    public function resend(ResendVerificationEmail $resend, CurrentPerson $current): RedirectResponse
    {
        $person = $current->find();

        if ($person !== null) {
            $resend->handle($person->id);
        }

        return back()->with('toast', ['message' => 'We sent the email again.', 'tone' => 'neutral']);
    }

    /**
     * "ma••@acme.dev": enough to recognize the address, not enough to read it from over a shoulder.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1))).'••@'.$domain;
    }
}
