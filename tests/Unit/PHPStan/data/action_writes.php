<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class WritesFromAction
{
    public function handle(User $user): void
    {
        User::create(['name' => 'x']);
        $user->save();
        DB::table('users')->insert(['name' => 'x']);
    }
}
