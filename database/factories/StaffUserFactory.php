<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Support\Models\StaffUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffUser>
 */
class StaffUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@bitflag.test',
            'password' => 'password',
            'status' => 'active',
        ];
    }
}
