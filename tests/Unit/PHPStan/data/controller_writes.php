<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Web;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class WritesFromController
{
    public function store(User $user): void
    {
        User::create(['name' => 'x']);
        $user->save();
        DB::table('users')->insert(['name' => 'x']);
        DB::table('users')->where('id', 1)->update(['name' => 'y']);
    }
}
