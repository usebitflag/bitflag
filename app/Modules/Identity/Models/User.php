<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Domain\AccountStatus;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A person who signs in to the portal. Users are global: they belong to organizations through memberships.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $timezone
 * @property AccountStatus $status
 * @property string|null $last_organization_id
 * @property string|null $last_project_id
 * @property string|null $last_environment_id
 * @property string|null $pending_email
 * @property Carbon $password_changed_at
 * @property bool $remember_org_choice
 * @property Carbon|null $last_active_at
 * @property string|null $deleted_display_name
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, HasUlids, Notifiable, UsesMicrosecondTimestamps;

    protected $fillable = ['name', 'email', 'password', 'timezone'];

    protected $hidden = ['password'];

    /** @var array<string, mixed> */
    protected $attributes = ['remember_org_choice' => false];

    /**
     * There is no "remember me": a session already lasts up to thirty days, so there is no token to store.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public static function publicIdPrefix(): IdPrefix
    {
        return IdPrefix::User;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'remember_org_choice' => 'boolean',
            'last_active_at' => 'datetime',
            'status' => AccountStatus::class,
        ];
    }
}
