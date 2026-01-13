/**
 * Добавь этот метод в TelegramMiniAppController.php
 */

/**
 * Получить рейтинг игроков
 */
public function rating(Request $request)
{
    $user = $this->getUser($request);
    
    // Получаем топ-50 игроков по рейтингу
    $players = User::where('role', 'player')
        ->orderBy('rating', 'desc')
        ->limit(50)
        ->get()
        ->map(function ($player, $index) {
            return [
                'id' => $player->id,
                'name' => $player->name ?? $player->first_name,
                'rating' => $player->rating,
                'level' => $player->level,
                'position' => $index + 1,
            ];
        });
    
    // Находим позицию текущего пользователя
    $myRank = null;
    $myChange = 0;
    
    if ($user) {
        $myRank = User::where('role', 'player')
            ->where('rating', '>', $user->rating)
            ->count() + 1;
        
        // TODO: посчитать изменение рейтинга за последний турнир
        // $myChange = ...
    }
    
    return response()->json([
        'players' => $players,
        'my_rank' => $myRank,
        'my_change' => $myChange,
    ]);
}


/**
 * Также добавь роут в routes/api.php в группу tg:
 * 
 * Route::get('/rating', [TelegramMiniAppController::class, 'rating']);
 */
