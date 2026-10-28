<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\CountSignInFailureData;
use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\SignInThrottle;
use App\Modules\Identity\Support\RequestOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Counts one failed sign-in step (a wrong password or a wrong second-factor code) against the email address, and logs it.
 * Reaching the limit locks the address, logs the lock once and tells the owner of a real account once.
 */
final class CountSignInFailure
{
    public function __construct(
        private readonly SignInThrottle $throttle,
        private readonly RecordSecurityEvent $securityLog,
        private readonly RequestAccountEmail $accountEmail,
        private readonly RequestOrigin $origin,
    ) {}

    /**
     * @return int how many failures the address has had in the current window
     */
    public function handle(CountSignInFailureData $data): int
    {
        $failures = $this->throttle->recordFailure($data->email);

        $this->log($data->type, $data);

        if ($failures >= SignInThrottle::MAX_FAILURES) {
            DB::transaction(function () use ($data): void {
                $this->log(SecurityEventType::SignInLocked, $data);

                if ($data->userId !== null) {
                    $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::AccountLocked, $data->userId, $this->origin->describe($data->ipAddress, $data->userAgent)));
                }
            });
        }

        return $failures;
    }

    private function log(SecurityEventType $type, CountSignInFailureData $data): void
    {
        $this->securityLog->handle(new RecordSecurityEventData($type, $data->userId, $data->email, $data->ipAddress, $data->userAgent));
    }
}
