<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GamePlayerFactory extends Factory
{
    protected $model = GamePlayer::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'source' => GamePlayer::SOURCE_INVITE,
            'out_of_range' => false,
            'score_confirmed' => false,
        ];
    }
}
