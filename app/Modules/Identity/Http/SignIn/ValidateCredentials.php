<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use App\Modules\Identity\Actions\AttemptSignIn;
use App\Modules\Identity\Data\AttemptSignInData;
use App\Modules\Identity\Domain\SignInOutcome;
use App\Modules\Identity\Domain\SignInThrottle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * The first stage: checks the password without signing anyone in. A failure answers with one message whatever went wrong,
 * and a locked address with another that is the same for known and unknown addresses.
 */
final class ValidateCredentials
{
    public const FAILED_MESSAGE = "That email and password don't match. Check them and try again.";

    public function __construct(private readonly AttemptSignIn $attempt) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $email = $request->input(Fortify::username());
        $password = $request->input('password');

        $result = $this->attempt->handle(new AttemptSignInData(
            is_string($email) ? $email : '',
            is_string($password) ? $password : '',
            $request->ip(),
            $request->userAgent(),
        ));

        if ($result->user === null) {
            throw ValidationException::withMessages([
                Fortify::username() => $result->outcome === SignInOutcome::Locked
                    ? SignInThrottle::LOCKED_MESSAGE
                    : self::FAILED_MESSAGE,
            ]);
        }

        SignInStage::remember($request, $result->user);
        $request->attributes->set('bitflag.sign_in.second_factor', $result->outcome === SignInOutcome::TwoFactorRequired);

        return $next($request);
    }
}
