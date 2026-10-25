<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\CompleteTwoFactorChallenge;
use App\Modules\Identity\Data\ChallengeOutcome;
use App\Modules\Identity\Data\CompleteTwoFactorChallengeData;
use App\Modules\Identity\Data\SignInMessages;
use App\Modules\Identity\Http\Requests\TwoFactorChallengeRequest;
use App\Modules\Identity\Http\SignIn\PendingSignIn;
use App\Modules\Identity\Http\SignIn\SignInCookies;
use App\Modules\Identity\Queries\PersonLookup;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The second step of signing in. It only works for a browser whose password was just accepted (the session holds a pending
 * sign-in); anyone else is sent to the sign-in page. Five wrong codes end the attempt.
 */
final class TwoFactorChallengeController
{
    public function show(Request $request, PendingSignIn $pending, PersonLookup $people): Response|RedirectResponse
    {
        $current = $pending->current($request->session());
        $email = $current === null ? null : $people->emailOf($current['user_id']);

        if ($email === null) {
            return redirect('/signin');
        }

        return Inertia::render('auth/TwoFactorChallenge', [
            'maskedEmail' => CheckEmailController::mask($email),
            'canUseRecovery' => true,
        ]);
    }

    public function store(
        TwoFactorChallengeRequest $request,
        PendingSignIn $pending,
        CompleteTwoFactorChallenge $complete,
        StatefulGuard $guard,
    ): RedirectResponse {
        $current = $pending->current($request->session());

        if ($current === null) {
            return redirect('/signin');
        }

        $result = $complete->handle(new CompleteTwoFactorChallengeData(
            $current['user_id'],
            $request->string('code')->toString(),
            $request->boolean('trust'),
            SignInCookies::read($request, SignInCookies::DEVICE),
            $request->ip(),
            $request->userAgent(),
        ));

        switch ($result->outcome) {
            case ChallengeOutcome::Authenticated:
                $guard->login($result->user ?? throw new LogicException('An authenticated challenge has a person.'));
                $request->session()->regenerate();
                $pending->clear($request->session());

                if ($result->trustedBrowserCookie !== null) {
                    Cookie::queue(SignInCookies::trustedBrowser($request, $result->trustedBrowserCookie));
                }
                if ($result->deviceToken !== null) {
                    Cookie::queue(SignInCookies::device($request, $result->deviceToken));
                }

                $response = redirect()->intended('/');

                return $result->recoveryCodesLeft !== null && $result->recoveryCodesLeft <= 2
                    ? $response->with('toast', ['message' => "You have {$result->recoveryCodesLeft} recovery codes left. Make new ones in Account security.", 'tone' => 'accent'])
                    : $response;

            case ChallengeOutcome::WrongCode:
                if ($pending->recordFailure($request->session())) {
                    return redirect('/signin')->withErrors(['email' => SignInMessages::TOO_MANY_CODES]);
                }

                return back()->withErrors(['code' => 'That code is not right.']);

            case ChallengeOutcome::Locked:
                $pending->clear($request->session());

                return redirect('/signin')->withErrors(['email' => SignInMessages::LOCKED]);

            case ChallengeOutcome::Unavailable:
                $pending->clear($request->session());

                return redirect('/signin')->withErrors(['email' => SignInMessages::FAILED]);
        }
    }
}
