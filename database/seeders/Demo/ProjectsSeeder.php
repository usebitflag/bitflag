<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class ProjectsSeeder extends Seeder
{
    public const STOREFRONT = 1;

    public const CHECKOUT_API = 2;

    public const BILLING = 3;

    public function run(): void
    {
        $projects = [
            self::STOREFRONT => [OrganizationsSeeder::ACME, 'Storefront', 'storefront', 'Web checkout, catalog and account pages.'],
            self::CHECKOUT_API => [OrganizationsSeeder::ACME, 'Checkout API', 'checkout-api', null],
            self::BILLING => [OrganizationsSeeder::GLOBEX, 'Billing', 'billing', null],
        ];

        foreach ($projects as $number => [$organization, $name, $key, $description]) {
            $organizationId = DemoIds::organization($organization);

            app(TenantContext::class)->run($organizationId, function () use ($number, $organizationId, $name, $key, $description): void {
                $project = Project::query()->find(DemoIds::project($number)) ?? new Project;
                $project->forceFill([
                    'id' => DemoIds::project($number),
                    'organization_id' => $organizationId,
                    'name' => $name,
                    'key' => $key,
                    'description' => $description,
                    'status' => ProjectStatus::Active,
                ])->save();
            });
        }
    }
}
