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

    public const MAX_ATTEMPTS = 5;

    /**
     * The sign-in waiting for its second factor, or null when there is none or it has lapsed (which also forgets it).
     *
     * @return array{user_id: string, attempts: int}|null
     */
    public function current(Session $session): ?array
    {
        $pending = $session->get(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['user_id'] ?? null) || ! is_string($pending['expires_at'] ?? null)) {
            return null;
        }

        if (strtotime($pending['expires_at']) <= $this->clock->now()->getTimestamp()) {
            $session->forget(self::SESSION_KEY);

            return null;
        }

        $attempts = $pending['attempts'] ?? 0;

        return ['user_id' => $pending['user_id'], 'attempts' => is_int($attempts) ? $attempts : 0];
    }

    /**
     * Counts a wrong code. Returns true when the attempt is over because there have been too many.
     */
    public function recordFailure(Session $session): bool
    {
        $pending = $session->get(self::SESSION_KEY);
        $previous = is_array($pending) ? ($pending['attempts'] ?? 0) : 0;
        $attempts = (is_int($previous) ? $previous : 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->clear($session);

            return true;
        }

        $session->put(self::SESSION_KEY.'.attempts', $attempts);

        return false;
    }

    public function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

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
