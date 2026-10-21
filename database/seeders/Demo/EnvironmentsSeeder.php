<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Environments\Models\Environment;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Every demo project gets development, staging and production, in that order.
 */
final class EnvironmentsSeeder extends Seeder
{
    private const ENVIRONMENTS = [
        1 => ['development', 'slate'],
        2 => ['staging', 'lilac'],
        3 => ['production', 'violet'],
    ];

    public function run(): void
    {
        $owners = [
            ProjectsSeeder::STOREFRONT => OrganizationsSeeder::ACME,
            ProjectsSeeder::CHECKOUT_API => OrganizationsSeeder::ACME,
            ProjectsSeeder::BILLING => OrganizationsSeeder::GLOBEX,
        ];

        foreach ($owners as $project => $organization) {
            $organizationId = DemoIds::organization($organization);

            app(TenantContext::class)->run($organizationId, function () use ($project, $organizationId): void {
                foreach (self::ENVIRONMENTS as $position => [$key, $color]) {
                    $id = DemoIds::environment($project, $position);
                    $environment = Environment::query()->find($id) ?? new Environment;
                    $environment->forceFill([
                        'id' => $id,
                        'organization_id' => $organizationId,
                        'project_id' => DemoIds::project($project),
                        'name' => $key,
                        'key' => $key,
                        'color' => $color,
                        'position' => $position,
                        'status' => 'active',
                    ])->save();
                }
            });
        }
    }
}
