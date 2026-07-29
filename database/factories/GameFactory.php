<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        $start = now()->addDay();
        return [
            'creator_id' => User::factory(),
            'club_id' => Club::factory(),
            'court_id' => null,
            'starts_at' => $start,
            'ends_at' => (clone $start)->addMinutes(90),
            'type' => Game::TYPE_RATED,
            'visibility' => Game::VISIBILITY_PUBLIC,
            'format' => Game::FORMAT_SETS,
            'format_meta' => null,
            'rating_min' => null,
            'rating_max' => null,
            'capacity' => 4,
            'price' => null,
            'description' => null,
            'status' => Game::STATUS_OPEN,
            'score_locked' => false,
            'share_token' => Str::random(32),
            'share_uses' => 0,
        ];
    }
}
