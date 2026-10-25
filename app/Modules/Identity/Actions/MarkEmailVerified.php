<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\MarkEmailVerifiedData;
use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Identity\Models\User;
use App\Shared\Errors\AuthorizationDenied;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

use function event;

/**
 * Confirms the address the link was made for. The link must belong to the person who opened it and to the address on the
 * account now, so a link for an address that was since changed does nothing. Returns false when it was already confirmed.
 */
final class MarkEmailVerified
{
    public function __construct(private readonly ClockInterface $clock) {}

    public function handle(MarkEmailVerifiedData $data): bool
    {
        $user = User::query()->find($data->userId);

        if ($user === null || $data->actingUserId !== $user->id || ! hash_equals(sha1($user->email), $data->hash)) {
            throw new AuthorizationDenied('This confirmation link is not for your account.');
        }

        if ($user->email_verified_at !== null) {
            return false;
        }

        DB::transaction(fn () => $user->forceFill(['email_verified_at' => $this->clock->now()])->save());

        event(new EmailVerified($user->id));

        return true;
    }
}
