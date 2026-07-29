# Games Module — Backend S0+S1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заложить схему БД и Eloquent-модели нового домена `games` (S0) и реализовать создание/редактирование игры со ссылкой-приглашением + базовые эндпоинты `/games` (S1) в бэкенде Laravel.

**Architecture:** Новый домен `games` строится **параллельно** старому `challenge` (старый не трогаем). 6 таблиц (`games`, `game_players`, `game_rounds`, `game_action_logs`, `invitations`, `game_transfers`), Eloquent-модели, новый контроллер `Api\MobileGameController` под тем же mobile/auth:sanctum-роутингом. Форматы счёта, роли/вступление, движок счёта — следующие слайсы (не входят сюда).

**Tech Stack:** PHP 8 / Laravel 12, Eloquent, Sanctum, PHPUnit (sqlite :memory:), фабрики Laravel.

## Global Constraints

- НЕ трогать `app/Traits/RatingCalculator.php` и `app/Support/AmericanoRanking.php` (общие с турнирами).
- НЕ трогать старый домен `challenge` (модели `Challenge`/`ChallengePlayer`, `MobileChallengeController`, его роуты и таблицы). `games` — рядом.
- Формат ответа API: `response()->json(['success' => true, 'data' => ...])`; ошибки доступа — `403`, валидации — `422`.
- Роуты игр — внутри `Route::prefix('mobile')` → `Route::middleware('auth:sanctum')->group(...)` в `routes/api.php` (кроме публичного resolve-by-share, он вне auth-группы).
- Миграции — стиль анонимного класса (`return new class extends Migration`), `foreignId(...)->constrained(...)`.
- Диапазон `rating_min`/`rating_max` — это шкала **уровней** (1.00–5.75), как `min_level`/`max_level` у challenge; `null` = без ограничения.
- Названия статусов/типов/форматов на русском — через аксессоры модели.
- Третий формат называется `americano` (не «king»).
- Даты миграций: префикс `2026_07_29_0001..` по порядку.

---

### Task 1: Миграции — 6 таблиц домена games

**Files:**
- Create: `database/migrations/2026_07_29_000101_create_games_table.php`
- Create: `database/migrations/2026_07_29_000102_create_game_players_table.php`
- Create: `database/migrations/2026_07_29_000103_create_game_rounds_table.php`
- Create: `database/migrations/2026_07_29_000104_create_game_action_logs_table.php`
- Create: `database/migrations/2026_07_29_000105_create_invitations_table.php`
- Create: `database/migrations/2026_07_29_000106_create_game_transfers_table.php`
- Test: `tests/Feature/Games/GamesSchemaTest.php`

**Interfaces:**
- Produces: таблицы `games`, `game_players`, `game_rounds`, `game_action_logs`, `invitations`, `game_transfers` с колонками ниже. Их используют все последующие задачи и слайсы.

- [ ] **Step 1: Написать падающий тест схемы**

`tests/Feature/Games/GamesSchemaTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GamesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_games_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('games'));
        $this->assertTrue(Schema::hasColumns('games', [
            'id', 'creator_id', 'club_id', 'court_id', 'starts_at', 'ends_at',
            'type', 'visibility', 'format', 'format_meta', 'rating_min', 'rating_max',
            'capacity', 'price', 'description', 'status', 'score_locked',
            'share_token', 'share_expires_at', 'share_max_uses', 'share_uses',
            'share_revoked_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_supporting_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('game_players', [
            'game_id', 'user_id', 'position', 'status', 'source', 'out_of_range',
            'rating_before', 'rating_after', 'rating_change', 'score_confirmed', 'responded_at',
        ]));
        $this->assertTrue(Schema::hasColumns('game_rounds', [
            'game_id', 'round_no', 'pair_a', 'pair_b', 'score_a', 'score_b',
            'tiebreak_a', 'tiebreak_b', 'is_played',
        ]));
        $this->assertTrue(Schema::hasColumns('game_action_logs', ['game_id', 'user_id', 'action', 'payload']));
        $this->assertTrue(Schema::hasColumns('invitations', [
            'user_id', 'inviter_id', 'invitable_type', 'invitable_id', 'kind', 'status', 'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('game_transfers', ['game_id', 'from_user_id', 'to_user_id', 'status']));
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GamesSchemaTest.php`
Expected: FAIL — `Failed asserting that false is true` (таблиц ещё нет).

- [ ] **Step 3: Создать миграции**

`2026_07_29_000101_create_games_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('club_id')->constrained('clubs')->onDelete('cascade');
            $table->foreignId('court_id')->nullable()->constrained('courts')->onDelete('set null');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('type', ['rated', 'friendly'])->default('rated');
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->enum('format', ['sets', 'points', 'americano'])->default('sets');
            $table->json('format_meta')->nullable();
            $table->decimal('rating_min', 4, 2)->nullable();
            $table->decimal('rating_max', 4, 2)->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->integer('price')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'full', 'in_progress', 'finished', 'cancelled', 'disputed'])->default('open');
            $table->boolean('score_locked')->default(false);
            $table->string('share_token')->nullable()->unique();
            $table->dateTime('share_expires_at')->nullable();
            $table->integer('share_max_uses')->nullable();
            $table->unsignedInteger('share_uses')->default(0);
            $table->dateTime('share_revoked_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('visibility');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
```

`2026_07_29_000102_create_game_players_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedTinyInteger('position')->nullable();
            $table->enum('status', ['invited', 'candidate', 'accepted', 'declined', 'left', 'removed'])->default('candidate');
            $table->enum('source', ['creator', 'invite', 'app_feed', 'app_link'])->default('invite');
            $table->boolean('out_of_range')->default(false);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->integer('rating_change')->nullable();
            $table->boolean('score_confirmed')->default(false);
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
```

`2026_07_29_000103_create_game_rounds_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->integer('round_no');
            $table->json('pair_a')->nullable();
            $table->json('pair_b')->nullable();
            $table->integer('score_a')->nullable();
            $table->integer('score_b')->nullable();
            $table->integer('tiebreak_a')->nullable();
            $table->integer('tiebreak_b')->nullable();
            $table->boolean('is_played')->default(false);
            $table->timestamps();

            $table->index(['game_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
```

`2026_07_29_000104_create_game_action_logs_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('action');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_action_logs');
    }
};
```

`2026_07_29_000105_create_invitations_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('inviter_id')->nullable()->constrained('users')->onDelete('set null');
            $table->morphs('invitable');
            $table->enum('kind', ['game', 'tournament', 'training'])->default('game');
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired', 'cancelled'])->default('pending');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
```

`2026_07_29_000106_create_game_transfers_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->foreignId('from_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('to_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_transfers');
    }
};
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GamesSchemaTest.php`
Expected: PASS (2 теста).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_29_0001*_*.php tests/Feature/Games/GamesSchemaTest.php
git commit -m "feat(games): миграции домена games (S0)"
```

---

### Task 2: Eloquent-модели + фабрики

**Files:**
- Create: `app/Models/Game.php`
- Create: `app/Models/GamePlayer.php`
- Create: `app/Models/GameRound.php`
- Create: `app/Models/GameActionLog.php`
- Create: `app/Models/Invitation.php`
- Create: `app/Models/GameTransfer.php`
- Create: `database/factories/GameFactory.php`
- Create: `database/factories/GamePlayerFactory.php`
- Test: `tests/Feature/Games/GameModelTest.php`

**Interfaces:**
- Consumes: таблицы из Task 1.
- Produces:
  - `Game` со связями `creator()`, `club()`, `court()`, `players()`, `acceptedPlayers()`, `rounds()`, `transfers()`, `invitations()`; хелперы `isOrganizer(int $userId): bool`, `acceptedCount(): int`, `isFull(): bool`, `getAvailablePositions(): array`, `shareLinkActive(): bool`; константы `STATUS_*`, `TYPE_*`, `FORMAT_*`, `VISIBILITY_PUBLIC/PRIVATE`; аксессоры `status_name`, `type_name`, `format_name`.
  - `GamePlayer` с константами `STATUS_*` (`invited`/`candidate`/`accepted`/`declined`/`left`/`removed`) и `SOURCE_*`; связи `game()`, `user()`.
  - `Game::factory()`, `GamePlayer::factory()`.

- [ ] **Step 1: Написать падающий тест моделей**

`tests/Feature/Games/GameModelTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_belongs_to_creator_and_club(): void
    {
        $game = Game::factory()->create();
        $this->assertInstanceOf(User::class, $game->creator);
        $this->assertInstanceOf(Club::class, $game->club);
    }

    public function test_is_organizer_matches_creator(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $creator->id]);
        $this->assertTrue($game->isOrganizer($creator->id));
        $this->assertFalse($game->isOrganizer($other->id));
    }

    public function test_accepted_count_and_available_positions(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id, 'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id, 'position' => 2,
            'status' => GamePlayer::STATUS_INVITED,
        ]);
        $this->assertSame(1, $game->fresh()->acceptedCount());
        // Позиция занята и accepted, и invited; свободны 3 и 4.
        $this->assertSame([3, 4], $game->fresh()->getAvailablePositions());
    }

    public function test_format_meta_is_cast_to_array(): void
    {
        $game = Game::factory()->create(['format' => 'points', 'format_meta' => ['points_mode' => 'first_to', 'points_target' => 21]]);
        $this->assertIsArray($game->fresh()->format_meta);
        $this->assertSame(21, $game->fresh()->format_meta['points_target']);
    }

    public function test_share_link_active_respects_revoke(): void
    {
        $game = Game::factory()->create(['share_token' => 'abc', 'share_revoked_at' => null]);
        $this->assertTrue($game->shareLinkActive());
        $game->update(['share_revoked_at' => now()]);
        $this->assertFalse($game->fresh()->shareLinkActive());
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameModelTest.php`
Expected: FAIL — `Class "App\Models\Game" not found`.

- [ ] **Step 3: Создать модели и фабрики**

`app/Models/Game.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    const STATUS_OPEN = 'open';
    const STATUS_FULL = 'full';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_FINISHED = 'finished';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_DISPUTED = 'disputed';

    const TYPE_RATED = 'rated';
    const TYPE_FRIENDLY = 'friendly';

    const VISIBILITY_PUBLIC = 'public';
    const VISIBILITY_PRIVATE = 'private';

    const FORMAT_SETS = 'sets';
    const FORMAT_POINTS = 'points';
    const FORMAT_AMERICANO = 'americano';

    protected $fillable = [
        'creator_id', 'club_id', 'court_id', 'starts_at', 'ends_at',
        'type', 'visibility', 'format', 'format_meta', 'rating_min', 'rating_max',
        'capacity', 'price', 'description', 'status', 'score_locked',
        'share_token', 'share_expires_at', 'share_max_uses', 'share_uses', 'share_revoked_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'format_meta' => 'array',
        'rating_min' => 'decimal:2',
        'rating_max' => 'decimal:2',
        'capacity' => 'integer',
        'price' => 'integer',
        'score_locked' => 'boolean',
        'share_expires_at' => 'datetime',
        'share_max_uses' => 'integer',
        'share_uses' => 'integer',
        'share_revoked_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function players()
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function acceptedPlayers()
    {
        return $this->hasMany(GamePlayer::class)->where('status', GamePlayer::STATUS_ACCEPTED);
    }

    public function rounds()
    {
        return $this->hasMany(GameRound::class)->orderBy('round_no');
    }

    public function transfers()
    {
        return $this->hasMany(GameTransfer::class);
    }

    public function invitations()
    {
        return $this->morphMany(Invitation::class, 'invitable');
    }

    public function isOrganizer(int $userId): bool
    {
        return $this->creator_id === $userId;
    }

    public function acceptedCount(): int
    {
        return $this->players()->where('status', GamePlayer::STATUS_ACCEPTED)->count();
    }

    public function isFull(): bool
    {
        return $this->acceptedCount() >= (int) $this->capacity;
    }

    public function getAvailablePositions(): array
    {
        // Позиция занята, если на ней accepted или invited игрок.
        $taken = $this->players()
            ->whereIn('status', [GamePlayer::STATUS_ACCEPTED, GamePlayer::STATUS_INVITED])
            ->pluck('position')
            ->filter()
            ->all();
        $all = range(1, (int) $this->capacity);
        return array_values(array_diff($all, $taken));
    }

    public function shareLinkActive(): bool
    {
        if (!$this->share_token || $this->share_revoked_at) {
            return false;
        }
        if ($this->share_expires_at && $this->share_expires_at->isPast()) {
            return false;
        }
        if ($this->share_max_uses !== null && $this->share_uses >= $this->share_max_uses) {
            return false;
        }
        return true;
    }

    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Открыта',
            self::STATUS_FULL => 'Состав собран',
            self::STATUS_IN_PROGRESS => 'В процессе',
            self::STATUS_FINISHED => 'Завершена',
            self::STATUS_CANCELLED => 'Отменена',
            self::STATUS_DISPUTED => 'Оспаривается',
            default => $this->status ?? 'Открыта',
        };
    }

    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_RATED => 'Рейтинговая',
            self::TYPE_FRIENDLY => 'Товарищеская',
            default => $this->type,
        };
    }

    public function getFormatNameAttribute(): string
    {
        return match ($this->format) {
            self::FORMAT_SETS => 'По сетам',
            self::FORMAT_POINTS => 'До N очков',
            self::FORMAT_AMERICANO => 'Американо',
            default => $this->format,
        };
    }
}
```

`app/Models/GamePlayer.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GamePlayer extends Model
{
    use HasFactory;

    const STATUS_INVITED = 'invited';
    const STATUS_CANDIDATE = 'candidate';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_LEFT = 'left';
    const STATUS_REMOVED = 'removed';

    const SOURCE_CREATOR = 'creator';
    const SOURCE_INVITE = 'invite';
    const SOURCE_APP_FEED = 'app_feed';
    const SOURCE_APP_LINK = 'app_link';

    protected $fillable = [
        'game_id', 'user_id', 'position', 'status', 'source', 'out_of_range',
        'rating_before', 'rating_after', 'rating_change', 'score_confirmed', 'responded_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'out_of_range' => 'boolean',
        'rating_before' => 'integer',
        'rating_after' => 'integer',
        'rating_change' => 'integer',
        'score_confirmed' => 'boolean',
        'responded_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }
}
```

`app/Models/GameRound.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameRound extends Model
{
    protected $fillable = [
        'game_id', 'round_no', 'pair_a', 'pair_b',
        'score_a', 'score_b', 'tiebreak_a', 'tiebreak_b', 'is_played',
    ];

    protected $casts = [
        'round_no' => 'integer',
        'pair_a' => 'array',
        'pair_b' => 'array',
        'score_a' => 'integer',
        'score_b' => 'integer',
        'tiebreak_a' => 'integer',
        'tiebreak_b' => 'integer',
        'is_played' => 'boolean',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
```

`app/Models/GameActionLog.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameActionLog extends Model
{
    protected $fillable = ['game_id', 'user_id', 'action', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/Invitation.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    const KIND_GAME = 'game';
    const KIND_TOURNAMENT = 'tournament';
    const KIND_TRAINING = 'training';

    protected $fillable = [
        'user_id', 'inviter_id', 'invitable_type', 'invitable_id', 'kind', 'status', 'expires_at',
    ];

    protected $casts = ['expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitable()
    {
        return $this->morphTo();
    }
}
```

`app/Models/GameTransfer.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameTransfer extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['game_id', 'from_user_id', 'to_user_id', 'status'];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
```

`database/factories/GameFactory.php`:
```php
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
```

`database/factories/GamePlayerFactory.php`:
```php
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
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameModelTest.php`
Expected: PASS (5 тестов).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Game.php app/Models/GamePlayer.php app/Models/GameRound.php app/Models/GameActionLog.php app/Models/Invitation.php app/Models/GameTransfer.php database/factories/GameFactory.php database/factories/GamePlayerFactory.php tests/Feature/Games/GameModelTest.php
git commit -m "feat(games): Eloquent-модели и фабрики домена games (S0)"
```

---

### Task 3: Роуты + контроллер + GET /games/clubs

**Files:**
- Create: `app/Http/Controllers/Api/MobileGameController.php`
- Modify: `routes/api.php` (добавить блок роутов games внутри mobile/auth:sanctum группы; публичный resolve — вне auth)
- Test: `tests/Feature/Games/GameClubsTest.php`

**Interfaces:**
- Consumes: `Game`, `GamePlayer` (Task 2), таблица `clubs`.
- Produces: класс `App\Http\Controllers\Api\MobileGameController` с методом `clubs(Request $request)`; зарегистрированные роуты (см. Step 3). Метод `clubs` возвращает `{success, data: [{id, name, address, city}]}` — активные клубы, при наличии `city` у пользователя фильтрует по городу.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameClubsTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameClubsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clubs_returns_active_clubs(): void
    {
        $active = Club::factory()->create(['is_active' => true]);
        Club::factory()->create(['is_active' => false]);

        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/mobile/games/clubs');
        $res->assertOk()->assertJson(['success' => true]);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_clubs_requires_auth(): void
    {
        $this->getJson('/api/mobile/games/clubs')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameClubsTest.php`
Expected: FAIL — 404 (роут не зарегистрирован).

- [ ] **Step 3: Создать контроллер и зарегистрировать роуты**

`app/Http/Controllers/Api/MobileGameController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use Illuminate\Http\Request;

class MobileGameController extends Controller
{
    /** Справочник клубов для создания игры (активные, опц. фильтр по городу). */
    public function clubs(Request $request)
    {
        $user = $request->user();

        $query = Club::where('is_active', true);
        if (!empty($user->city)) {
            $query->where('city', $user->city);
        }

        $clubs = $query->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'address' => $c->address,
            'city' => $c->city,
        ]);

        return response()->json(['success' => true, 'data' => $clubs]);
    }
}
```

В `routes/api.php` найти блок `// Поединки` (роуты challenges внутри `Route::middleware('auth:sanctum')->group`) и сразу ПОСЛЕ него (внутри той же auth-группы) добавить:
```php
        // Игры (games) — новый домен, рядом с поединками (challenges)
        Route::get('/games/clubs', [\App\Http\Controllers\Api\MobileGameController::class, 'clubs']);
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameClubsTest.php`
Expected: PASS (2 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameClubsTest.php
git commit -m "feat(games): контроллер + GET /games/clubs (S1)"
```

---

### Task 4: POST /games — создание игры + ссылка-приглашение

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `store`, `formatGame`)
- Modify: `routes/api.php` (роут POST /games)
- Test: `tests/Feature/Games/GameCreateTest.php`

**Interfaces:**
- Consumes: `Game`, `GamePlayer`.
- Produces:
  - `store(Request $request)` — валидирует поля, создаёт `Game` (status `open`), генерит уникальный `share_token` (`Str::random(32)`), создаёт создателя как `GamePlayer` (`status=accepted`, `source=creator`, `position=1`). Ответ 201 `{success, data: formatGame(...)}`.
  - `formatGame(Game $game, ?User $user): array` — форматтер (используют Task 5/6/7). Ключи: `id, creator_id, is_creator, club{id,name}, court_id, starts_at(iso), ends_at(iso), type, type_name, visibility, format, format_name, format_meta, rating_min, rating_max, capacity, price, description, status, status_name, score_locked, share_token, share_active, available_positions, accepted_count, players[]`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameCreateTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameCreateTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Club $club, array $override = []): array
    {
        return array_merge([
            'club_id' => $club->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_creates_game_with_creator_as_accepted_player(): void
    {
        $club = Club::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/mobile/games', $this->payload($club));

        $res->assertCreated()->assertJson(['success' => true]);
        $res->assertJsonPath('data.is_creator', true);
        $res->assertJsonPath('data.status', 'open');
        $this->assertNotEmpty($res->json('data.share_token'));

        $game = Game::first();
        $this->assertSame($user->id, $game->creator_id);
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $user->id)->first();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertSame(GamePlayer::SOURCE_CREATOR, $player->source);
    }

    public function test_validation_rejects_end_before_start(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'ends_at' => now()->addDay()->toIso8601String(),
            'starts_at' => now()->addDay()->addHour()->toIso8601String(),
        ]))->assertStatus(422);
    }

    public function test_validation_rejects_too_long_duration(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHours(7)->toIso8601String(),
        ]))->assertStatus(422);
    }

    public function test_points_format_meta_persisted(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to', 'points_target' => 21],
        ]));
        $res->assertCreated();
        $this->assertSame(21, Game::first()->format_meta['points_target']);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameCreateTest.php`
Expected: FAIL — 404/405 (роут POST /games не зарегистрирован).

- [ ] **Step 3: Реализовать store + formatGame + роут**

В `app/Http/Controllers/Api/MobileGameController.php` добавить `use` и методы:
```php
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
```
```php
    /** Создать игру. Создатель занимает слот 1. Генерится ссылка-приглашение. */
    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $this->validateGame($request);

        // Длительность 30 мин – 6 ч.
        $mins = Carbon::parse($validated['starts_at'])->diffInMinutes(Carbon::parse($validated['ends_at']));
        if ($mins < 30 || $mins > 360) {
            return response()->json([
                'success' => false,
                'message' => 'Длительность игры должна быть от 30 минут до 6 часов',
            ], 422);
        }

        $game = Game::create([
            'creator_id' => $user->id,
            'club_id' => $validated['club_id'],
            'court_id' => $validated['court_id'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'format' => $validated['format'],
            'format_meta' => $validated['format_meta'] ?? null,
            'rating_min' => $validated['rating_min'] ?? null,
            'rating_max' => $validated['rating_max'] ?? null,
            'capacity' => 4,
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => Game::STATUS_OPEN,
            'share_token' => $this->uniqueShareToken(),
            'share_expires_at' => $validated['starts_at'], // по умолчанию до старта
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'source' => GamePlayer::SOURCE_CREATOR,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ], 201);
    }

    /** Общие правила валидации создания/редактирования. */
    private function validateGame(Request $request): array
    {
        return $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'court_id' => 'nullable|exists:courts,id',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
            'type' => 'required|in:rated,friendly',
            'visibility' => 'required|in:public,private',
            'format' => 'required|in:sets,points,americano',
            'format_meta' => 'nullable|array',
            'rating_min' => 'nullable|numeric|min:1|max:5.75',
            'rating_max' => 'nullable|numeric|min:1|max:5.75|gte:rating_min',
            'price' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:1000',
        ]);
    }

    private function uniqueShareToken(): string
    {
        do {
            $token = Str::random(32);
        } while (Game::where('share_token', $token)->exists());
        return $token;
    }

    /** Форматтер игры для API. $user может быть null (публичный переход по ссылке). */
    public function formatGame(Game $game, ?User $user): array
    {
        $players = $game->players->map(function ($p) use ($user) {
            $name = $p->user->name ?? 'Без имени';
            return [
                'id' => $p->user->id,
                'position' => $p->position,
                'status' => $p->status,
                'source' => $p->source,
                'out_of_range' => (bool) $p->out_of_range,
                'full_name' => $name,
                'avatar' => $p->user->avatar,
                'rating' => $p->user->rating,
                'level' => (float) $p->user->level,
                'is_me' => $user && $p->user->id === $user->id,
            ];
        })->values();

        return [
            'id' => $game->id,
            'creator_id' => $game->creator_id,
            'is_creator' => $user && $game->creator_id === $user->id,
            'club' => $game->club ? ['id' => $game->club->id, 'name' => $game->club->name] : null,
            'court_id' => $game->court_id,
            'starts_at' => $game->starts_at?->toIso8601String(),
            'ends_at' => $game->ends_at?->toIso8601String(),
            'type' => $game->type,
            'type_name' => $game->type_name,
            'visibility' => $game->visibility,
            'format' => $game->format,
            'format_name' => $game->format_name,
            'format_meta' => $game->format_meta,
            'rating_min' => $game->rating_min !== null ? (float) $game->rating_min : null,
            'rating_max' => $game->rating_max !== null ? (float) $game->rating_max : null,
            'capacity' => (int) $game->capacity,
            'price' => $game->price,
            'description' => $game->description,
            'status' => $game->status,
            'status_name' => $game->status_name,
            'score_locked' => (bool) $game->score_locked,
            'share_token' => $game->share_token,
            'share_active' => $game->shareLinkActive(),
            'available_positions' => $game->getAvailablePositions(),
            'accepted_count' => $game->acceptedCount(),
            'players' => $players,
        ];
    }
```

В `routes/api.php` (в auth-группе, рядом с ранее добавленным `/games/clubs`) добавить:
```php
        Route::post('/games', [\App\Http\Controllers\Api\MobileGameController::class, 'store']);
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameCreateTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameCreateTest.php
git commit -m "feat(games): POST /games — создание игры + ссылка (S1)"
```

---

### Task 5: GET /games/{game} (детали) + GET /games (лента)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `show`, `index`)
- Modify: `routes/api.php` (роуты)
- Test: `tests/Feature/Games/GameShowIndexTest.php`

**Interfaces:**
- Consumes: `Game`, `formatGame` (Task 4).
- Produces:
  - `show(Request $request, Game $game)` → `{success, data: formatGame(...)}`.
  - `index(Request $request)` — лента: только `visibility=public`, `status` в `[open, full]`, `starts_at >= now`, сортировка `starts_at` asc. → `{success, data: [...]}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameShowIndexTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameShowIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_game(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $game->id);
    }

    public function test_index_lists_only_public_future_open_games(): void
    {
        $public = Game::factory()->create([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->addDay(),
        ]);
        Game::factory()->create([
            'visibility' => 'private', 'status' => 'open', 'starts_at' => now()->addDay(),
        ]);
        Game::factory()->create([
            'visibility' => 'public', 'status' => 'finished', 'starts_at' => now()->addDay(),
        ]);
        Game::factory()->create([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->subDay(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/mobile/games')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$public->id], $ids);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameShowIndexTest.php`
Expected: FAIL — 404 (роуты не зарегистрированы).

- [ ] **Step 3: Реализовать show + index + роуты**

В контроллер добавить методы:
```php
    /** Детали игры. */
    public function show(Request $request, Game $game)
    {
        $game->load(['creator', 'club', 'court', 'players.user']);
        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game, $request->user()),
        ]);
    }

    /** Лента игр — только публичные, набирающие состав, будущие. */
    public function index(Request $request)
    {
        $user = $request->user();
        $games = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where('visibility', Game::VISIBILITY_PUBLIC)
            ->whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json(['success' => true, 'data' => $games]);
    }
```

В `routes/api.php` (auth-группа) добавить. **Важно:** `/games/clubs` должен идти ДО `/games/{game}`, иначе `clubs` попадёт в `{game}` — он уже добавлен выше, поэтому статические роуты (`/games/clubs`, `POST /games`) выше, а параметрический `/games/{game}` — ниже:
```php
        Route::get('/games', [\App\Http\Controllers\Api\MobileGameController::class, 'index']);
        Route::get('/games/{game}', [\App\Http\Controllers\Api\MobileGameController::class, 'show']);
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameShowIndexTest.php`
Expected: PASS (2 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameShowIndexTest.php
git commit -m "feat(games): GET /games (лента) + GET /games/{game} (детали) (S1)"
```

---

### Task 6: PUT /games/{game} — редактирование + переключение приватности

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `update`)
- Modify: `routes/api.php` (роут)
- Test: `tests/Feature/Games/GameUpdateTest.php`

**Interfaces:**
- Consumes: `Game`, `validateGame`, `formatGame`.
- Produces: `update(Request $request, Game $game)` — только организатор (`isOrganizer`), только при `score_locked=false`; обновляет параметры и `visibility`. Иначе `403`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameUpdateTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function editPayload(Game $game, array $override = []): array
    {
        return array_merge([
            'club_id' => $game->club_id,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_organizer_can_edit_and_toggle_privacy(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'visibility' => 'public']);
        Sanctum::actingAs($organizer);

        $res = $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game, [
            'visibility' => 'private', 'price' => 5000,
        ]));

        $res->assertOk()->assertJsonPath('data.visibility', 'private');
        $this->assertSame(5000, $game->fresh()->price);
    }

    public function test_non_organizer_cannot_edit(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game))
            ->assertStatus(403);
    }

    public function test_cannot_edit_locked_game(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'score_locked' => true]);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game))
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameUpdateTest.php`
Expected: FAIL — 404/405.

- [ ] **Step 3: Реализовать update + роут**

В контроллер добавить метод:
```php
    /** Редактировать игру. Только организатор, пока счёт не залочен. */
    public function update(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор может редактировать игру'], 403);
        }
        if ($game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт утверждён, редактирование недоступно'], 403);
        }

        $validated = $this->validateGame($request);

        $mins = \Illuminate\Support\Carbon::parse($validated['starts_at'])->diffInMinutes(\Illuminate\Support\Carbon::parse($validated['ends_at']));
        if ($mins < 30 || $mins > 360) {
            return response()->json(['success' => false, 'message' => 'Длительность игры должна быть от 30 минут до 6 часов'], 422);
        }

        $game->update([
            'club_id' => $validated['club_id'],
            'court_id' => $validated['court_id'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'format' => $validated['format'],
            'format_meta' => $validated['format_meta'] ?? null,
            'rating_min' => $validated['rating_min'] ?? null,
            'rating_max' => $validated['rating_max'] ?? null,
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }
```

В `routes/api.php` (auth-группа) добавить:
```php
        Route::put('/games/{game}', [\App\Http\Controllers\Api\MobileGameController::class, 'update']);
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameUpdateTest.php`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameUpdateTest.php
git commit -m "feat(games): PUT /games/{game} — редактирование + приватность (S1)"
```

---

### Task 7: Ссылка-приглашение — перевыпуск/отзыв + публичный resolve

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `shareRotate`, `shareRevoke`, `resolveByShare`)
- Modify: `routes/api.php` (2 роута в auth-группе + 1 публичный)
- Test: `tests/Feature/Games/GameShareTest.php`

**Interfaces:**
- Consumes: `Game`, `uniqueShareToken`, `formatGame`, `shareLinkActive`.
- Produces:
  - `shareRotate(Request, Game)` — организатор; новый `share_token`, сброс `share_revoked_at=null`, `share_uses=0`. → `{success, data:{share_token, share_active}}`.
  - `shareRevoke(Request, Game)` — организатор; `share_revoked_at=now()`. → `{success}`.
  - `resolveByShare(string $token)` — публичный (без auth); если игра найдена по токену и `shareLinkActive()` — `{success, data: formatGame(game, null)}`; иначе `404`/`410`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameShareTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotate_changes_token_and_invalidates_old(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'share_token' => 'oldtoken']);
        Sanctum::actingAs($organizer);

        $res = $this->postJson("/api/mobile/games/{$game->id}/share/rotate")->assertOk();
        $new = $res->json('data.share_token');
        $this->assertNotSame('oldtoken', $new);
        $this->assertSame($new, $game->fresh()->share_token);

        // Старый токен больше не резолвится.
        $this->getJson('/api/mobile/games/by-share/oldtoken')->assertStatus(404);
    }

    public function test_revoke_disables_link(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'share_token' => 'tok']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/share/revoke")->assertOk();
        $this->assertNotNull($game->fresh()->share_revoked_at);
        $this->getJson('/api/mobile/games/by-share/tok')->assertStatus(410);
    }

    public function test_non_organizer_cannot_rotate(): void
    {
        $game = Game::factory()->create(['share_token' => 'tok']);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/games/{$game->id}/share/rotate")->assertStatus(403);
    }

    public function test_resolve_returns_game_for_active_link(): void
    {
        $game = Game::factory()->create(['share_token' => 'livetok', 'share_revoked_at' => null]);
        // Публичный роут — без авторизации.
        $this->getJson('/api/mobile/games/by-share/livetok')
            ->assertOk()
            ->assertJsonPath('data.id', $game->id);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameShareTest.php`
Expected: FAIL — 404/405.

- [ ] **Step 3: Реализовать методы + роуты**

В контроллер добавить методы:
```php
    /** Перевыпустить ссылку-приглашение (старая перестаёт работать). */
    public function shareRotate(Request $request, Game $game)
    {
        if (!$game->isOrganizer($request->user()->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        $game->update([
            'share_token' => $this->uniqueShareToken(),
            'share_revoked_at' => null,
            'share_uses' => 0,
        ]);
        return response()->json([
            'success' => true,
            'data' => ['share_token' => $game->share_token, 'share_active' => $game->shareLinkActive()],
        ]);
    }

    /** Отозвать ссылку-приглашение. */
    public function shareRevoke(Request $request, Game $game)
    {
        if (!$game->isOrganizer($request->user()->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        $game->update(['share_revoked_at' => now()]);
        return response()->json(['success' => true]);
    }

    /** Публичный переход по ссылке-приглашению → карточка игры. */
    public function resolveByShare(string $token)
    {
        $game = Game::where('share_token', $token)->first();
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Ссылка не найдена'], 404);
        }
        if (!$game->shareLinkActive()) {
            return response()->json(['success' => false, 'message' => 'Ссылка недействительна'], 410);
        }
        $game->load(['creator', 'club', 'court', 'players.user']);
        return response()->json(['success' => true, 'data' => $this->formatGame($game, null)]);
    }
```

В `routes/api.php`:
- В auth-группе (рядом с остальными games):
```php
        Route::post('/games/{game}/share/rotate', [\App\Http\Controllers\Api\MobileGameController::class, 'shareRotate']);
        Route::post('/games/{game}/share/revoke', [\App\Http\Controllers\Api\MobileGameController::class, 'shareRevoke']);
```
- Публичный роут — внутри `Route::prefix('mobile')`, но ВНЕ `auth:sanctum`-группы (рядом с другими публичными mobile-роутами, до строки `Route::middleware('auth:sanctum')->group`):
```php
    Route::get('/games/by-share/{token}', [\App\Http\Controllers\Api\MobileGameController::class, 'resolveByShare']);
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameShareTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Прогнать все тесты games и Commit**

Run: `php artisan test tests/Feature/Games`
Expected: PASS (все файлы Games — 6 тестовых классов).

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameShareTest.php
git commit -m "feat(games): ссылка-приглашение rotate/revoke + публичный resolve (S1)"
```

---

## Порядок выполнения

Task 1 → 2 → 3 → 4 → 5 → 6 → 7 (строго последовательно; 4 зависит от 2/3, 5–7 от 4).

## Что НЕ входит в этот план (следующие слайсы)

- S2: роли/вступление (invite/apply/approve/accept/decline/leave/remove, комната ожидания)
- S3: фильтр по рейтингу + `out_of_range` + тумблер «вне уровня»
- S4–S6: движки счёта sets/points/americano
- S7: отменяемость (`game_action_logs`, undo)
- S8: утверждение/подтверждение/спор
- S9: передача прав (`game_transfers`)
- S10: лента с фильтрами + «Мои игры»
- S11: инбокс «Приглашения» (`invitations`)
- S12: пуши + напоминания
- S13: Flutter-раздел
- S14: удаление старого challenge

Модели `GameRound`, `GameActionLog`, `Invitation`, `GameTransfer` и их таблицы созданы заранее (Task 1–2), но задействуются в соответствующих слайсах.
