<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Support\Models\StaffUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

/**
 * The staff identity for the admin console, which is a separate guard from the portal.
 */
final class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $staff = StaffUser::query()->find(DemoIds::staff(1)) ?? new StaffUser;
        $staff->forceFill([
            'id' => DemoIds::staff(1),
            'name' => 'Support Staff',
            'email' => 'support@bitflag.test',
            'password' => Config::string('bitflag.demo_password'),
            'status' => 'active',
        ])->save();
    }
}
