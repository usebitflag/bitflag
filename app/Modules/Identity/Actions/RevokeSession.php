<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RevokeSessionData;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Queries\ActiveSessions;
use App\Shared\Errors\ResourceNotFound;
use Illuminate\Support\Facades\DB;

/**
 * Signs one of a person's other sessions out by deleting its row. The session making the request is not ended here (that is
 * signing out), and a person can only end their own.
 */
final class RevokeSession
{
    public function __construct(private readonly RecordSecurityEvent $securityLog) {}

    /**
     * @throws ResourceNotFound when no other session of this person has that name
     */
    public function handle(RevokeSessionData $data): void
    {
        $ids = DB::table('sessions')->where('user_id', $data->userId)->where('id', '!=', $data->currentSessionId)->pluck('id');
        $target = $ids->first(fn ($id): bool => is_string($id) && ActiveSessions::ref($id) === $data->sessionRef);

        if ($target === null) {
            throw new ResourceNotFound;
        }

        DB::transaction(function () use ($data, $target): void {
            DB::table('sessions')->where('id', $target)->where('user_id', $data->userId)->delete();
            $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::SessionRevoked, $data->userId, null, $data->ipAddress, $data->userAgent));
        });
    }
}
