<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'organization_id' => fn (array $attributes) => Tenancy::crossTenant(
                'factory reads the project to find its organization',
                fn () => Project::query()->whereKey($attributes['project_id'])->firstOrFail()->organization_id,
            ),
            'user_id' => User::factory(),
            'role' => 'viewer',
        ];
    }
}
