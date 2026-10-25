<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Every demo project gets development, staging and production, in that order.
 */
final class EnvironmentsSeeder extends Seeder
{
    private const ENVIRONMENTS = [
        1 => ['development', EnvironmentColor::Slate],
        2 => ['staging', EnvironmentColor::Lilac],
        3 => ['production', EnvironmentColor::Violet],
    ];

    public function run(): void
    {
        $owners = [
            ProjectsSeeder::STOREFRONT => OrganizationsSeeder::ACME,
            ProjectsSeeder::CHECKOUT_API => OrganizationsSeeder::ACME,
            ProjectsSeeder::BILLING => OrganizationsSeeder::GLOBEX,
            ProjectsSeeder::PORTFOLIO => OrganizationsSeeder::STUDIO,
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
                        'status' => EnvironmentStatus::Active,
                    ])->save();

                    // Production starts protected, like a project made through CreateProject.
                    $protected = $key === 'production';
                    $policy = EnvironmentPolicy::query()->find(DemoIds::policy($project, $position)) ?? new EnvironmentPolicy;
                    $policy->forceFill([
                        'id' => DemoIds::policy($project, $position),
                        'organization_id' => $organizationId,
                        'project_id' => DemoIds::project($project),
                        'environment_id' => $id,
                        'version' => 1,
                        'is_protected' => $protected,
                        'require_reason' => $protected,
                        'required_approvals' => 0,
                        'allow_self_approval' => false,
                    ])->save();

                    $environment->forceFill(['current_policy_id' => $policy->id])->save();
                }
            });
        }
    }
}
