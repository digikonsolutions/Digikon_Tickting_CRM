<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Agency> */
class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'status' => 'active',
            'ticket_prefix' => Agency::generatePrefix($name),
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
