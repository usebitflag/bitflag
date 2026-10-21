<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('????? ?????');

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'key' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => null,
            'status' => ProjectStatus::Active,
        ];
    }
}
