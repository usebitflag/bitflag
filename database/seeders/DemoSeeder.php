<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\EnvironmentsSeeder;
use Database\Seeders\Demo\InvitationsSeeder;
use Database\Seeders\Demo\OrganizationsSeeder;
use Database\Seeders\Demo\ProjectsSeeder;
use Database\Seeders\Demo\StaffSeeder;
use Database\Seeders\Demo\UsersSeeder;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Builds the demo world: Acme with Storefront and Checkout API, Globex for isolation checks, Maya's own organization,
 * five Acme people with different access (Maya and Jon use two-factor), an invitation waiting in Acme, and a staff user. Run it with `php artisan db:seed --class=DemoSeeder`.
 *
 * It uses fixed identifiers, so running it again updates the same rows. It never runs in production.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('The demo seeder never runs in production.');
        }

        $this->call([
            OrganizationsSeeder::class,
            ProjectsSeeder::class,
            EnvironmentsSeeder::class,
            UsersSeeder::class,
            InvitationsSeeder::class,
            StaffSeeder::class,
        ]);
    }
}
