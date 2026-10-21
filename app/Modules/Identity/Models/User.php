<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs in to the portal. Users are global: they belong to organizations through memberships.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $timezone
 * @property string $status
 * @property string|null $last_organization_id
 * @property string|null $last_project_id
 * @property string|null $last_environment_id
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, HasUlids, Notifiable, UsesMicrosecondTimestamps;

    protected $fillable = ['name', 'email', 'password', 'timezone'];

    protected $hidden = ['password', 'remember_token'];

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
        ];
    }
}
