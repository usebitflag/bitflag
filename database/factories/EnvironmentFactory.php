<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Environments\Models\Environment;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(1).'-'.fake()->numerify('###');

        return [
            'project_id' => Project::factory(),
            // The environment belongs to the same organization as its project.
            'organization_id' => fn (array $attributes) => Tenancy::crossTenant(
                'factory reads the project to find its organization',
                fn () => Project::query()->whereKey($attributes['project_id'])->firstOrFail()->organization_id,
            ),
            'name' => ucfirst($key),
            'key' => $key,
            'color' => 'slate',
            'position' => 1,
            'status' => 'active',
        ];
    }
}
