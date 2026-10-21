<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Shared\Persistence\UsesMicrosecondTimestamps;
use Database\Factories\StaffUserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A member of BitFlag staff who signs in to the admin console. Staff identities are never customer accounts and are
 * never joined to users.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $status
 */
#[UseFactory(StaffUserFactory::class)]
class StaffUser extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<StaffUserFactory> */
    use HasFactory, HasUlids, UsesMicrosecondTimestamps;

    protected $table = 'staff_users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'status'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
