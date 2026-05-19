<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tournament>
 */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'name' => 'Test Tournament ' . fake()->randomNumber(4),
            'description' => fake()->sentence(),
            'start_date' => now()->addDays(7),
            'registration_deadline' => now()->addDays(5),
            'min_level' => 1.00,
            'max_level' => 5.75,
            'max_participants' => 16,
            'price' => 0,
            'status' => 'open',
            'type' => 'americano',
            'courts_count' => 2,
        ];
    }
}
