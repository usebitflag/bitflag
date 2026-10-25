<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use Illuminate\Contracts\Session\Session;
use Psr\Clock\ClockInterface;

/**
 * A sign-in that still owes a second factor. The session keeps only who is pending, for ten minutes; the person is not
 * signed in, so every route that needs a signed-in person treats them as a guest until the code is accepted.
 */
final class PendingSignIn
{
    public const SESSION_KEY = 'login.pending';

    public const MINUTES = 10;

    public function __construct(private readonly ClockInterface $clock) {}

    public function begin(Session $session, string $userId): void
    {
        // A new session id, so an id known before the password was typed is worthless afterwards.
        $session->regenerate();
        $session->put(self::SESSION_KEY, [
            'user_id' => $userId,
            'remember' => false,
            'expires_at' => $this->clock->now()->modify('+'.self::MINUTES.' minutes')->format(DATE_ATOM),
            'attempts' => 0,
        ]);
    }
}
