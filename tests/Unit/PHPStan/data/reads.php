<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OnlyReads
{
    public function run(): void
    {
        User::query()->where('id', 1)->get();
        User::find(1);
        DB::table('users')->count();

        $items = new Collection;
        $items->push('x');
    }
}
