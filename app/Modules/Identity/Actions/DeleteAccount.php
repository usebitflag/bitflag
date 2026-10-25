<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\DeleteAccountData;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\RemoveAccountMemberships;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a person's own account. It needs their password and their email typed out, and is refused while they are the only
 * Org Owner of an organization. The person is taken out of every organization and project; their sessions, trusted browsers,
 * devices and second factor go; the address is replaced by one that cannot receive mail and the name by "Deleted user",
 * except that the old name is kept for the audit trail so history still reads right. The row itself stays, because audit
 * entries point at it, and it can no longer sign in.
 */
final class DeleteAccount
{
    public const DELETED_NAME = 'Deleted user';

    public function __construct(private readonly RemoveAccountMemberships $memberships) {}

    /**
     * @throws LastOwner
     * @throws ValidationException
     */
    public function handle(DeleteAccountData $data): void
    {
        $user = User::query()->findOrFail($data->userId);

        if (! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        if (mb_strtolower(trim($data->typedEmail)) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages(['email' => 'Type your email address exactly to confirm.']);
        }

        DB::transaction(function () use ($user): void {
            $this->memberships->handle(Actor::user($user->id, $user->name, Channel::Dashboard), $user->id);

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('trusted_browsers')->where('user_id', $user->id)->delete();
            DB::table('known_devices')->where('user_id', $user->id)->delete();
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            MfaFactor::query()->where('user_id', $user->id)->delete();

            $user->forceFill([
                'deleted_display_name' => $user->name,
                'name' => self::DELETED_NAME,
                'email' => 'deleted-'.strtolower($user->id).'@deleted.invalid',
                'pending_email' => null,
                'previous_email' => null,
                'password' => Hash::make(Str::random(64)),
                'status' => AccountStatus::Deactivated,
                'email_verified_at' => null,
                'last_organization_id' => null,
                'last_project_id' => null,
                'last_environment_id' => null,
            ])->save();
        });
    }
}
