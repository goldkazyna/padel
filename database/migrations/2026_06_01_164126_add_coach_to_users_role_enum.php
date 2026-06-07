<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Добавляем значение 'coach' в enum колонки users.role.
     * Без него update(['role' => 'coach']) в strict MySQL падает с
     * "Data truncated for column 'role'", а в non-strict тихо пишет ''
     * (роль отображается как «Игрок») — поэтому тренеры теряли роль.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('player','club_admin','super_admin','reserve','club_moderator','coach') NOT NULL DEFAULT 'player'");

            // Чиним уже сломанных тренеров: у кого есть запись в club_coaches,
            // но роль пустая/player — выставляем корректную 'coach'.
            DB::statement("
                UPDATE users u
                JOIN club_coaches cc ON cc.user_id = u.id
                SET u.role = 'coach'
                WHERE u.role NOT IN ('club_admin','super_admin','club_moderator')
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Возвращаем тренеров в player, затем сужаем enum обратно.
            DB::statement("UPDATE users SET role = 'player' WHERE role = 'coach'");
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('player','club_admin','super_admin','reserve','club_moderator') NOT NULL DEFAULT 'player'");
        }
    }
};
