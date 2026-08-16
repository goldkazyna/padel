# Отказ от ответственности по клубу — план реализации

> **Для агентов:** реализовывать по задачам, шаги отмечены чекбоксами. Спека: `docs/superpowers/specs/2026-08-16-club-waiver-design.md`.

**Цель:** клуб включает отказ от ответственности и вешает QR на стойке; клиент сканирует, читает текст в приложении и расписывается пальцем; клуб видит список подписавших.

**Архитектура:** текст и флаг живут у клуба, подписи — в отдельной таблице со снимком текста и телефона. QR ведёт на страницу `/w/{club}`, которая сразу пробует `padelp://waiver/{club}` и уводит в стор, если приложения нет. Картинка подписи лежит вне `public/` и отдаётся через маршрут с проверкой доступа.

**Стек:** Laravel 12, PHP 8.3, MySQL (прод) / SQLite in-memory (тесты), Flutter 3.38.

## Общие требования

- Весь текст в коде, комментариях и коммитах — на русском.
- Комментарии объясняют «почему», а не «что».
- Тесты точечно: `php artisan test --filter`, полный прогон: `php -d memory_limit=1G vendor/bin/phpunit`.
- Базовый уровень полного прогона: **3 ошибки, 23 падения** (Breeze-заготовки, CourtScheduleTest, AmericanoFlexServiceTest, TournamentRemindersTest; два Auth-теста плавают от порядка запуска). Отклонение — регрессия.
- Коммитить после каждой задачи.
- Прод: миграции точечно через `--path`.
- Картинка подписи — персональные данные: **никогда не в `public/`**.
- Текст на подпись сервер берёт из своей копии; клиент присылает только контрольную сумму показанного.

---

### Задача 1: Хранилище

**Файлы:**
- Создать: `database/migrations/2026_08_17_000002_add_waiver_to_clubs_table.php`
- Создать: `database/migrations/2026_08_17_000003_create_club_waiver_signatures_table.php`
- Создать: `app/Models/ClubWaiverSignature.php`
- Изменить: `app/Models/Club.php` — `$fillable`, связь `waiverSignatures()`, хелперы
- Тест: `tests/Feature/ClubWaiverStorageTest.php`

**Отдаёт дальше:** `Club::$waiver_enabled`, `Club::$waiver_text`, `Club::collectsWaiver(): bool`, `Club::waiverTextHash(): ?string`, модель `ClubWaiverSignature`.

- [x] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubWaiverStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_club_collects_waiver_only_with_flag_and_text(): void
    {
        $off = Club::create(['name' => 'A', 'address' => 'A']);
        $this->assertFalse($off->collectsWaiver());

        $noText = Club::create(['name' => 'B', 'address' => 'B', 'waiver_enabled' => true]);
        $this->assertFalse($noText->collectsWaiver(), 'галочка без текста ничего не значит');

        $on = Club::create([
            'name' => 'C', 'address' => 'C',
            'waiver_enabled' => true, 'waiver_text' => 'За травму отвечаю сам.',
        ]);
        $this->assertTrue($on->collectsWaiver());
    }

    public function test_text_hash_changes_with_text(): void
    {
        $club = Club::create([
            'name' => 'C', 'address' => 'C',
            'waiver_enabled' => true, 'waiver_text' => 'Первая редакция',
        ]);
        $first = $club->waiverTextHash();

        $club->update(['waiver_text' => 'Вторая редакция']);
        $this->assertNotSame($first, $club->fresh()->waiverTextHash());
    }

    public function test_signature_keeps_snapshots(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'C']);
        $user = User::factory()->create(['phone' => '77771234567']);

        $sig = ClubWaiverSignature::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'full_name' => 'Дудников Денис Сергеевич',
            'phone' => $user->phone,
            'waiver_text' => 'Текст на момент подписи',
            'signature_path' => 'waivers/1/1.png',
            'signed_at' => now(),
            'ip' => '127.0.0.1',
            'user_agent' => 'PadelKZ/1.7.3',
        ]);

        $this->assertSame('Текст на момент подписи', $sig->waiver_text);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $sig->signed_at);
    }

    public function test_one_signature_per_club_and_player(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'C']);
        $user = User::factory()->create();

        $row = [
            'club_id' => $club->id, 'user_id' => $user->id, 'full_name' => 'И И И',
            'phone' => '7', 'waiver_text' => 'т', 'signature_path' => 'p', 'signed_at' => now(),
        ];
        ClubWaiverSignature::create($row);

        $this->expectException(QueryException::class);
        ClubWaiverSignature::create($row);
    }

    public function test_signatures_go_away_with_the_club(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'C']);
        $user = User::factory()->create();
        ClubWaiverSignature::create([
            'club_id' => $club->id, 'user_id' => $user->id, 'full_name' => 'И',
            'phone' => '7', 'waiver_text' => 'т', 'signature_path' => 'p', 'signed_at' => now(),
        ]);

        $club->delete();

        $this->assertSame(0, ClubWaiverSignature::count(), 'без клуба подписи бессмысленны');
    }
}
```

- [x] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=ClubWaiverStorageTest`
Ожидание: FAIL — колонки и модель не существуют.

- [x] **Шаг 3: Миграции и модель**

`2026_08_17_000002_add_waiver_to_clubs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Отказ от ответственности: включён ли сбор и текст, который подписывают.
     * Выключенная галочка не стирает ни текст, ни собранные подписи — клуб
     * может приостановить сбор и вернуть его.
     */
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('waiver_enabled')->default(false)->after('privacy_policy');
            $table->text('waiver_text')->nullable()->after('waiver_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['waiver_enabled', 'waiver_text']);
        });
    }
};
```

`2026_08_17_000003_create_club_waiver_signatures_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Подпись под отказом от ответственности.
     *
     * Текст и телефон хранятся снимком: клуб текст правит, игрок меняет номер,
     * а доказывать через год нужно то, что человек видел в момент подписи.
     */
    public function up(): void
    {
        Schema::create('club_waiver_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 32)->nullable();
            $table->text('waiver_text');
            $table->string('signature_path');
            $table->timestamp('signed_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'user_id']);
            $table->index(['club_id', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_waiver_signatures');
    }
};
```

`app/Models/ClubWaiverSignature.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Подпись игрока под отказом от ответственности конкретного клуба.
 * Одна на клуб и навсегда: переподписывать не просим.
 */
class ClubWaiverSignature extends Model
{
    protected $fillable = [
        'club_id', 'user_id', 'full_name', 'phone',
        'waiver_text', 'signature_path', 'signed_at', 'ip', 'user_agent',
    ];

    protected $casts = ['signed_at' => 'datetime'];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

В `app/Models/Club.php` добавить `'waiver_enabled'` и `'waiver_text'` в `$fillable`, каст `'waiver_enabled' => 'boolean'`, и:

```php
    public function waiverSignatures()
    {
        return $this->hasMany(ClubWaiverSignature::class);
    }

    /** Собирает ли клуб отказ: галочка без текста ничего не значит. */
    public function collectsWaiver(): bool
    {
        return (bool) $this->waiver_enabled && trim((string) $this->waiver_text) !== '';
    }

    /**
     * Контрольная сумма текста.
     *
     * Приложение присылает её обратно при подписи: если текст успели поправить,
     * пока человек читал, подпись отклоняется и он перечитывает свежую версию.
     */
    public function waiverTextHash(): ?string
    {
        return $this->collectsWaiver() ? hash('sha256', (string) $this->waiver_text) : null;
    }
```

- [x] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=ClubWaiverStorageTest`
Ожидание: 5 passed.

- [x] **Шаг 5: Коммит**

```bash
git add database/migrations app/Models tests/Feature/ClubWaiverStorageTest.php
git commit -m "feat(waiver): хранилище отказа от ответственности"
```

---

### Задача 2: Настройка в супер-админке

**Файлы:**
- Изменить: `app/Http/Controllers/Admin/ClubController.php` — валидация `update()`
- Изменить: `resources/views/admin/clubs/edit.blade.php` — блок с галочкой, текстом и QR
- Тест: `tests/Feature/ClubWaiverAdminTest.php`

**Берёт:** поля из задачи 1.

- [x] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubWaiverAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function payload(Club $club, array $over = []): array
    {
        return array_merge([
            'name' => $club->name,
            'address' => $club->address,
        ], $over);
    }

    public function test_admin_turns_the_waiver_on_with_text(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.clubs.update', $club), $this->payload($club, [
                'waiver_enabled' => 1,
                'waiver_text' => 'За травму отвечаю сам.',
            ]))
            ->assertRedirect();

        $club = $club->fresh();
        $this->assertTrue($club->collectsWaiver());
        $this->assertSame('За травму отвечаю сам.', $club->waiver_text);
    }

    /** Выключение сохраняет текст: клуб может приостановить сбор и вернуть его. */
    public function test_turning_off_keeps_the_text(): void
    {
        $club = Club::create([
            'name' => 'Клуб', 'address' => 'Адрес',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.clubs.update', $club), $this->payload($club, ['waiver_enabled' => 0]))
            ->assertRedirect();

        $club = $club->fresh();
        $this->assertFalse($club->collectsWaiver());
        $this->assertSame('Текст', $club->waiver_text);
    }

    public function test_qr_appears_only_when_the_waiver_is_collected(): void
    {
        $off = Club::create(['name' => 'Без отказа', 'address' => 'А']);
        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.edit', $off))
            ->assertOk()
            ->assertDontSee('waiver-qr', false);

        $on = Club::create([
            'name' => 'С отказом', 'address' => 'Б',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);
        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.edit', $on))
            ->assertOk()
            ->assertSee('waiver-qr', false)
            ->assertSee(url('/w/' . $on->id), false);
    }
}
```

- [x] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=ClubWaiverAdminTest`
Ожидание: FAIL — поля не сохраняются, QR нет.

- [x] **Шаг 3: Валидация и форма**

В `Admin\ClubController@update` в массив правил добавить:

```php
            'waiver_enabled' => 'nullable|boolean',
            'waiver_text' => 'nullable|string|max:20000',
```

и после `$validated = $request->validate(...)`:

```php
        // Галочка приходит парой hidden+checkbox, как остальные флаги формы.
        $validated['waiver_enabled'] = $request->boolean('waiver_enabled');
```

В `resources/views/admin/clubs/edit.blade.php` после блока с документами клуба:

```blade
                    <div class="mb-4">
                        <label class="form-check">
                            <input type="hidden" name="waiver_enabled" value="0">
                            <input type="checkbox" name="waiver_enabled" value="1" class="form-check-input"
                                   id="waiverEnabled"
                                   {{ old('waiver_enabled', $club->waiver_enabled) ? 'checked' : '' }}
                                   style="background-color: var(--bg-secondary); border-color: var(--border);">
                            <span class="form-check-label">Отказ от ответственности</span>
                        </label>
                        <div class="text-secondary small mt-1">
                            Клиент сканирует QR на стойке, читает текст в приложении и расписывается пальцем.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Текст отказа</label>
                        <textarea name="waiver_text" class="form-control" rows="8"
                                  placeholder="Текст, который клиент прочитает и подпишет">{{ old('waiver_text', $club->waiver_text) }}</textarea>
                        <div class="text-secondary small mt-1">
                            Правка текста не отменяет уже собранные подписи: у каждой сохранён тот текст,
                            который человек видел.
                        </div>
                    </div>

                    @if($club->collectsWaiver())
                        @php $waiverUrl = url('/w/' . $club->id); @endphp
                        <div class="mb-4" id="waiver-qr">
                            <label class="form-label">QR для стойки</label>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <canvas id="waiverQrCanvas" width="220" height="220"
                                        style="background:#fff;border-radius:12px;padding:10px"></canvas>
                                <div>
                                    <div class="text-secondary small mb-2">{{ $waiverUrl }}</div>
                                    <a id="waiverQrDownload" class="btn-outline-custom btn-sm" download="waiver-{{ $club->id }}.png">
                                        <i class="bi bi-download"></i> Скачать для печати
                                    </a>
                                </div>
                            </div>
                        </div>
                        <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                var canvas = document.getElementById('waiverQrCanvas');
                                QRCode.toCanvas(canvas, @json($waiverUrl), { width: 220, margin: 1 }, function (err) {
                                    if (err) return;
                                    // Ссылку на скачивание собираем из уже нарисованного холста,
                                    // чтобы не генерировать картинку второй раз.
                                    document.getElementById('waiverQrDownload').href = canvas.toDataURL('image/png');
                                });
                            });
                        </script>
                    @endif
```

- [x] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=ClubWaiverAdminTest`
Ожидание: 3 passed.

- [x] **Шаг 5: Коммит**

```bash
git add app/Http/Controllers/Admin/ClubController.php resources/views/admin/clubs/edit.blade.php tests/Feature/ClubWaiverAdminTest.php
git commit -m "feat(waiver): галочка, текст и QR на странице клуба"
```

---

### Задача 3: Страница `/w/{club}`

**Файлы:**
- Создать: `resources/views/waiver-open.blade.php`
- Изменить: `routes/web.php` — рядом с маршрутом `/t/{tournament}` (около строки 35)
- Тест: `tests/Feature/ClubWaiverLandingTest.php`

**Отдаёт дальше:** публичный маршрут `GET /w/{club}` с именем `waiver.open`.

- [x] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubWaiverLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_pushes_into_the_app(): void
    {
        $club = Club::create([
            'name' => 'Клуб', 'address' => 'А',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);

        $this->get('/w/' . $club->id)
            ->assertOk()
            ->assertSee('padelp://waiver/' . $club->id, false)
            ->assertSee('отсканируйте код ещё раз')
            ->assertSee('Клуб');
    }

    public function test_page_says_so_when_the_club_does_not_collect(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'А']);

        $this->get('/w/' . $club->id)
            ->assertOk()
            ->assertSee('не собирает')
            ->assertDontSee('padelp://waiver/', false);
    }

    public function test_unknown_club_gives_404(): void
    {
        $this->get('/w/999999')->assertNotFound();
    }
}
```

- [x] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=ClubWaiverLandingTest`
Ожидание: FAIL — маршрута нет (404 на первом же тесте).

- [x] **Шаг 3: Маршрут и страница**

В `routes/web.php` рядом с `/t/{tournament}`:

```php
/**
 * Отказ от ответственности: QR на стойке клуба ведёт сюда.
 * Страница сразу пробует открыть приложение и уводит в стор, если его нет.
 */
Route::get('/w/{club}', function (\App\Models\Club $club) {
    $ua = request()->header('User-Agent', '');
    $isIOS = (bool) preg_match('/iPad|iPhone|iPod/i', $ua);

    return view('waiver-open', [
        'club' => $club,
        'storeUrl' => $isIOS
            ? config('mobile_app.store_url_ios')
            : config('mobile_app.store_url_android'),
    ]);
})->name('waiver.open');
```

`resources/views/waiver-open.blade.php` — за образец берётся `tournament-share.blade.php`, включая тайминг 1,8 секунды и отмену перехода в стор, когда страница уходит в фон:

```blade
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Отказ от ответственности — {{ $club->name }}</title>
    <style>
        body{margin:0;background:#0c0e0f;color:#fff;font-family:-apple-system,system-ui,sans-serif;
             display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
        .box{max-width:420px;text-align:center}
        h1{font-size:22px;margin:0 0 8px}
        p{color:#9ca3af;font-size:15px;line-height:1.5;margin:0 0 22px}
        .btn{display:block;background:#22c55e;color:#08130c;text-decoration:none;font-weight:700;
             padding:14px;border-radius:12px;margin-bottom:10px}
        .btn-sec{background:transparent;color:#9ca3af;border:1px solid #2d2d2d}
        .note{color:#6b7280;font-size:13px;line-height:1.5;margin-top:18px}
    </style>
</head>
<body>
<div class="box">
    <h1>{{ $club->name }}</h1>
    @if($club->collectsWaiver())
        <p>Отказ от ответственности подписывается в приложении Padel KZ.</p>
        <a class="btn" href="padelp://waiver/{{ $club->id }}">Открыть в приложении</a>
        <a class="btn btn-sec" href="{{ $storeUrl }}">Установить приложение</a>
        <div class="note">
            Если приложения ещё нет — установите его и <b>отсканируйте код ещё раз</b>:
            ссылка сама после установки не откроется.
        </div>
    @else
        <p>Этот клуб не собирает отказ от ответственности.</p>
    @endif
</div>

@if($club->collectsWaiver())
<script>
    (function () {
        var ua = navigator.userAgent || navigator.vendor || window.opera;
        if (!/android/i.test(ua) && !(/iPad|iPhone|iPod/.test(ua) && !window.MSStream)) return;

        var storeUrl = {!! json_encode($storeUrl) !!};
        var deepLink = 'padelp://waiver/{{ $club->id }}';

        var fallbackTimer = setTimeout(function () { window.location.href = storeUrl; }, 1800);

        // Страница ушла в фон — приложение перехватило ссылку, в стор не идём.
        window.addEventListener('blur', function () { clearTimeout(fallbackTimer); });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) clearTimeout(fallbackTimer);
        });

        window.location.href = deepLink;
    })();
</script>
@endif
</body>
</html>
```

- [x] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=ClubWaiverLandingTest`
Ожидание: 3 passed.

- [x] **Шаг 5: Коммит**

```bash
git add routes/web.php resources/views/waiver-open.blade.php tests/Feature/ClubWaiverLandingTest.php
git commit -m "feat(waiver): страница QR ведёт в приложение"
```

---

### Задача 4: API подписи

**Файлы:**
- Создать: `app/Services/WaiverSignatureService.php`
- Создать: `app/Http/Controllers/Api/MobileWaiverController.php`
- Изменить: `routes/api.php` — рядом с `/clubs/{club}` (около строки 377)
- Тест: `tests/Feature/ClubWaiverApiTest.php`

**Берёт:** `Club::collectsWaiver()`, `Club::waiverTextHash()`, модель `ClubWaiverSignature`.
**Отдаёт дальше:**
- `GET /api/mobile/clubs/{club}/waiver` → `{ collects, text, text_hash, signed_at, full_name }`
- `POST /api/mobile/clubs/{club}/waiver/sign` с `full_name`, `text_hash`, `signature` (data:image/png;base64,…)
- `WaiverSignatureService::store(Club, User, string $fullName, string $pngBase64, Request): ClubWaiverSignature`

- [x] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubWaiverApiTest extends TestCase
{
    use RefreshDatabase;

    /** Крошечный непрозрачный PNG 2×2. */
    private function pngBase64(): string
    {
        $img = imagecreatetruecolor(2, 2);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagepng($img);
        $raw = ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($raw);
    }

    private function club(): Club
    {
        return Club::create([
            'name' => 'Клуб', 'address' => 'А',
            'waiver_enabled' => true, 'waiver_text' => 'За травму отвечаю сам.',
        ]);
    }

    public function test_player_reads_the_waiver(): void
    {
        $club = $this->club();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/clubs/{$club->id}/waiver")
            ->assertOk()
            ->assertJsonPath('collects', true)
            ->assertJsonPath('text', 'За травму отвечаю сам.')
            ->assertJsonPath('text_hash', $club->waiverTextHash())
            ->assertJsonPath('signed_at', null);
    }

    public function test_player_signs_and_snapshots_are_kept(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $user = User::factory()->create(['phone' => '77771234567']);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [
            'full_name' => 'Дудников Денис Сергеевич',
            'text_hash' => $club->waiverTextHash(),
            'signature' => $this->pngBase64(),
        ])->assertOk()->assertJsonPath('success', true);

        $sig = ClubWaiverSignature::firstOrFail();
        $this->assertSame('Дудников Денис Сергеевич', $sig->full_name);
        $this->assertSame('77771234567', $sig->phone, 'телефон снимком');
        $this->assertSame('За травму отвечаю сам.', $sig->waiver_text, 'текст снимком');
        Storage::disk('local')->assertExists($sig->signature_path);
    }

    /** Правка текста не трогает уже собранные подписи. */
    public function test_editing_the_text_does_not_touch_old_signatures(): void
    {
        Storage::fake('local');
        $club = $this->club();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [
            'full_name' => 'И И', 'text_hash' => $club->waiverTextHash(), 'signature' => $this->pngBase64(),
        ])->assertOk();

        $club->update(['waiver_text' => 'Совсем другой текст']);

        $this->assertSame('За травму отвечаю сам.', ClubWaiverSignature::firstOrFail()->waiver_text);
    }

    public function test_stale_text_hash_is_refused(): void
    {
        Storage::fake('local');
        $club = $this->club();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [
            'full_name' => 'И И',
            'text_hash' => hash('sha256', 'что-то своё'),
            'signature' => $this->pngBase64(),
        ])->assertStatus(409)->assertJsonPath('text', 'За травму отвечаю сам.');

        $this->assertSame(0, ClubWaiverSignature::count());
    }

    public function test_second_signature_returns_the_first(): void
    {
        Storage::fake('local');
        $club = $this->club();
        Sanctum::actingAs(User::factory()->create());

        $body = [
            'full_name' => 'И И', 'text_hash' => $club->waiverTextHash(), 'signature' => $this->pngBase64(),
        ];
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", $body)->assertOk();
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", $body)->assertOk();

        $this->assertSame(1, ClubWaiverSignature::count(), 'двойной тап не плодит подписи');
    }

    public function test_disabled_waiver_refuses_the_signature(): void
    {
        Storage::fake('local');
        $club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/clubs/{$club->id}/waiver")
            ->assertOk()->assertJsonPath('collects', false);

        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [
            'full_name' => 'И И', 'text_hash' => 'x', 'signature' => $this->pngBase64(),
        ])->assertStatus(422);

        $this->assertSame(0, ClubWaiverSignature::count());
    }

    public function test_guest_cannot_sign(): void
    {
        $club = $this->club();
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [])->assertUnauthorized();
    }
}
```

- [x] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=ClubWaiverApiTest`
Ожидание: FAIL — маршрутов нет.

- [x] **Шаг 3: Сервис, контроллер, маршруты**

`app/Services/WaiverSignatureService.php`:

```php
<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Сохранение подписи под отказом от ответственности.
 *
 * Текст в базу кладётся из копии клуба, а не из запроса: иначе подписать
 * можно было бы что угодно, вплоть до собственной редакции.
 */
class WaiverSignatureService
{
    /** Подпись пальцем весит десятки килобайт; больше — попытка что-то залить. */
    private const MAX_BYTES = 1024 * 1024;

    public function store(
        Club $club,
        User $user,
        string $fullName,
        string $signatureBase64,
        Request $request
    ): ClubWaiverSignature {
        $existing = ClubWaiverSignature::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->first();

        // Двойной тап по кнопке не должен порождать вторую подпись.
        if ($existing) {
            return $existing;
        }

        $png = $this->decode($signatureBase64);

        $signature = ClubWaiverSignature::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'full_name' => $fullName,
            'phone' => $user->phone,
            'waiver_text' => (string) $club->waiver_text,
            'signature_path' => 'waivers/placeholder',
            'signed_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        // Путь знаем только после вставки: в нём id подписи.
        $path = "waivers/{$club->id}/{$signature->id}.png";
        Storage::disk('local')->put($path, $png);
        $signature->update(['signature_path' => $path]);

        return $signature;
    }

    /** @throws RuntimeException если это не PNG, он пуст или слишком велик */
    private function decode(string $value): string
    {
        $value = preg_replace('#^data:image/png;base64,#', '', trim($value));
        $png = base64_decode((string) $value, true);

        if ($png === false || $png === '') {
            throw new RuntimeException('Подпись не распознана');
        }
        if (strlen($png) > self::MAX_BYTES) {
            throw new RuntimeException('Слишком большая картинка подписи');
        }
        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('Подпись должна быть PNG');
        }
        if ($this->isBlank($png)) {
            throw new RuntimeException('Подпись пустая');
        }

        return $png;
    }

    /** Полностью прозрачная или одноцветная картинка — это не подпись. */
    private function isBlank(string $png): bool
    {
        $img = @imagecreatefromstring($png);
        if (!$img) {
            return true;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $first = null;
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $color = imagecolorat($img, $x, $y);
                if ($first === null) {
                    $first = $color;
                } elseif ($color !== $first) {
                    imagedestroy($img);

                    return false;
                }
            }
        }
        imagedestroy($img);

        return true;
    }
}
```

`app/Http/Controllers/Api/MobileWaiverController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Services\WaiverSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Отказ от ответственности в приложении: чтение текста и подпись.
 */
class MobileWaiverController extends Controller
{
    public function show(Request $request, Club $club): JsonResponse
    {
        $signature = ClubWaiverSignature::where('club_id', $club->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'success' => true,
            'collects' => $club->collectsWaiver(),
            'club_name' => $club->name,
            'text' => $club->collectsWaiver() ? $club->waiver_text : null,
            'text_hash' => $club->waiverTextHash(),
            'signed_at' => $signature?->signed_at?->toIso8601String(),
            'full_name' => $signature?->full_name,
            'signed_text' => $signature?->waiver_text,
        ]);
    }

    public function sign(Request $request, Club $club, WaiverSignatureService $service): JsonResponse
    {
        if (!$club->collectsWaiver()) {
            return response()->json([
                'success' => false,
                'message' => 'Клуб не собирает отказ от ответственности',
            ], 422);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|min:3|max:255',
            'text_hash' => 'required|string',
            'signature' => 'required|string',
        ]);

        // Текст успели поправить, пока человек читал: подписывать старую
        // редакцию нельзя, отдаём свежую и просим перечитать.
        if (!hash_equals((string) $club->waiverTextHash(), $validated['text_hash'])) {
            return response()->json([
                'success' => false,
                'message' => 'Текст изменился, перечитайте его',
                'text' => $club->waiver_text,
                'text_hash' => $club->waiverTextHash(),
            ], 409);
        }

        try {
            $signature = $service->store(
                $club,
                $request->user(),
                trim($validated['full_name']),
                $validated['signature'],
                $request
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'signed_at' => $signature->signed_at->toIso8601String(),
        ]);
    }
}
```

В `routes/api.php` рядом с остальными маршрутами клубов:

```php
        // Отказ от ответственности
        Route::get('/clubs/{club}/waiver', [MobileWaiverController::class, 'show']);
        Route::post('/clubs/{club}/waiver/sign', [MobileWaiverController::class, 'sign']);
```

плюс импорт `use App\Http\Controllers\Api\MobileWaiverController;`.

- [x] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=ClubWaiverApiTest`
Ожидание: 7 passed.

- [x] **Шаг 5: Коммит**

```bash
git add app/Services/WaiverSignatureService.php app/Http/Controllers/Api/MobileWaiverController.php routes/api.php tests/Feature/ClubWaiverApiTest.php
git commit -m "feat(waiver): API чтения текста и подписи"
```

---

### Задача 5: Список подписей в клубной админке

**Файлы:**
- Создать: `app/Http/Controllers/Club/WaiverController.php`
- Создать: `resources/views/club/waivers/index.blade.php`
- Изменить: `routes/web.php` — в группе клубных маршрутов
- Изменить: `resources/views/layouts/app.blade.php` — пункт меню в обеих ветках (админ и модератор)
- Тест: `tests/Feature/ClubWaiverListTest.php`

**Берёт:** модель `ClubWaiverSignature`.
**Отдаёт дальше:** маршруты `club.waivers.index` и `club.waivers.image`.

- [x] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClubWaiverListTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Club, 1: User, 2: ClubWaiverSignature} */
    private function scenario(): array
    {
        Storage::fake('local');
        $club = Club::create([
            'name' => 'Клуб', 'address' => 'А',
            'waiver_enabled' => true, 'waiver_text' => 'Текст отказа',
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $player = User::factory()->create(['phone' => '77771234567']);
        Storage::disk('local')->put('waivers/1/1.png', 'png-bytes');
        $signature = ClubWaiverSignature::create([
            'club_id' => $club->id, 'user_id' => $player->id,
            'full_name' => 'Дудников Денис Сергеевич', 'phone' => '77771234567',
            'waiver_text' => 'Текст отказа', 'signature_path' => 'waivers/1/1.png',
            'signed_at' => now(),
        ]);

        return [$club, $admin, $signature];
    }

    public function test_club_admin_sees_who_signed(): void
    {
        [, $admin] = $this->scenario();

        $this->actingAs($admin)
            ->get(route('club.waivers.index'))
            ->assertOk()
            ->assertSee('Дудников Денис Сергеевич')
            ->assertSee('Текст отказа');
    }

    public function test_signature_image_is_served_to_its_club(): void
    {
        [, $admin, $signature] = $this->scenario();

        $this->actingAs($admin)
            ->get(route('club.waivers.image', $signature))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    /** Подпись — персональные данные: чужой клуб её не видит. */
    public function test_foreign_club_admin_is_refused(): void
    {
        [, , $signature] = $this->scenario();

        $other = Club::create(['name' => 'Чужой', 'address' => 'Б']);
        $stranger = User::factory()->create(['role' => 'club_admin']);
        $stranger->adminClubs()->attach($other->id);

        $this->actingAs($stranger)
            ->get(route('club.waivers.image', $signature))
            ->assertForbidden();
    }

    public function test_super_admin_sees_any_signature(): void
    {
        [, , $signature] = $this->scenario();
        $super = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($super)
            ->get(route('club.waivers.image', $signature))
            ->assertOk();
    }
}
```

- [x] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=ClubWaiverListTest`
Ожидание: FAIL — маршрутов нет.

- [x] **Шаг 3: Контроллер, вью, маршруты, меню**

`app/Http/Controllers/Club/WaiverController.php`:

```php
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubWaiverSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Подписи под отказом от ответственности в клубной админке.
 */
class WaiverController extends Controller
{
    /** Клуб текущего администратора; у супер-админа клуба нет — он видит все. */
    private function getClub()
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return null;
        }
        if ($user->isClubModerator()) {
            return $user->moderatorClubs()->first();
        }

        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        $search = trim((string) $request->get('q'));

        $signatures = ClubWaiverSignature::with('user:id,name,avatar')
            ->when($club, fn ($q) => $q->where('club_id', $club->id))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', '%' . preg_replace('/\D/', '', $search) . '%');
                });
            })
            ->orderByDesc('signed_at')
            ->paginate(50)
            ->withQueryString();

        return view('club.waivers.index', compact('signatures', 'club', 'search'));
    }

    /**
     * Картинка подписи.
     *
     * Файл лежит вне public: это персональные данные, и видеть их должен
     * только свой клуб или супер-админ.
     */
    public function image(ClubWaiverSignature $signature)
    {
        $club = $this->getClub();
        abort_if($club && $signature->club_id !== $club->id, 403);
        abort_unless(Storage::disk('local')->exists($signature->signature_path), 404);

        return response(
            Storage::disk('local')->get($signature->signature_path),
            200,
            ['Content-Type' => 'image/png']
        );
    }
}
```

`resources/views/club/waivers/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'Отказы от ответственности')

@section('content')
<div class="page-header">
    <div>
        <h2>Отказы от ответственности</h2>
        <p>Кто подписал и что именно</p>
    </div>
</div>

<form method="GET" class="mb-4" style="max-width:420px">
    <input type="text" name="q" value="{{ $search }}" class="form-control"
           placeholder="Поиск по имени или телефону">
</form>

<div class="waivers-list">
    @forelse($signatures as $signature)
        <div class="waiver-row">
            <div class="waiver-info">
                <div class="waiver-name">{{ $signature->full_name }}</div>
                <small class="text-secondary">@phoneFmt($signature->phone)</small>
            </div>
            <div class="waiver-date">{{ $signature->signed_at->translatedFormat('j F Y, H:i') }}</div>
            <a class="btn-outline-custom btn-sm" data-bs-toggle="collapse"
               href="#waiver{{ $signature->id }}">
                <i class="bi bi-eye"></i> Посмотреть
            </a>
        </div>
        <div class="collapse" id="waiver{{ $signature->id }}">
            <div class="waiver-detail">
                <img src="{{ route('club.waivers.image', $signature) }}" alt="Подпись" class="waiver-sign">
                <pre class="waiver-text">{{ $signature->waiver_text }}</pre>
            </div>
        </div>
    @empty
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-pencil-square fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-0">Пока никто не подписал</p>
            </div>
        </div>
    @endforelse
</div>

<div class="mt-3">{{ $signatures->links() }}</div>

<style>
.waivers-list { display: flex; flex-direction: column; gap: 8px; }
.waiver-row {
    display: flex; align-items: center; gap: 16px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 12px; padding: 14px 18px;
}
.waiver-info { flex: 1; min-width: 0; }
.waiver-name { font-weight: 600; color: var(--text-primary); }
.waiver-date { color: var(--text-secondary); font-size: 13px; white-space: nowrap; }
.waiver-detail {
    background: var(--bg-secondary); border: 1px solid var(--border);
    border-radius: 12px; padding: 16px; margin: 4px 0 8px;
}
.waiver-sign {
    background: #fff; border-radius: 8px; padding: 8px;
    max-width: 320px; width: 100%; display: block; margin-bottom: 14px;
}
.waiver-text {
    color: var(--text-secondary); font-size: 13px; line-height: 1.55;
    white-space: pre-wrap; word-break: break-word; margin: 0;
    font-family: inherit;
}
</style>
@endsection
```

В `routes/web.php` в группе клубных маршрутов:

```php
            Route::get('/waivers', [App\Http\Controllers\Club\WaiverController::class, 'index'])
                ->name('waivers.index');
            Route::get('/waivers/{signature}/image', [App\Http\Controllers\Club\WaiverController::class, 'image'])
                ->name('waivers.image');
```

В `resources/views/layouts/app.blade.php` пункт меню добавляется **в обе ветки** — админа и модератора, рядом с «Инвентарь». Показывается только тем клубам, что собирают отказ, — иначе пустой раздел висит у всех:

```blade
					@if(($modClub ?? null)?->collectsWaiver())
					<li class="nav-item">
						<a href="{{ route('club.waivers.index') }}" class="nav-link {{ request()->routeIs('club.waivers.*') ? 'active' : '' }}">
							<i class="bi bi-pencil-square"></i>
							<span>Отказы</span>
						</a>
					</li>
					@endif
```

- [x] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=ClubWaiverListTest`
Ожидание: 4 passed.

- [x] **Шаг 5: Полный прогон и коммит**

Запуск: `php -d memory_limit=1G vendor/bin/phpunit`
Ожидание: не хуже базового уровня — 3 ошибки, 23 падения.

```bash
git add app/Http/Controllers/Club/WaiverController.php resources/views/club/waivers routes/web.php resources/views/layouts/app.blade.php tests/Feature/ClubWaiverListTest.php
git commit -m "feat(waiver): список подписей в клубной админке"
git push
```

---

### Задача 6: Приложение — модель и сервис

**Файлы:**
- Создать: `C:\projects\padel_app\lib\models\club_waiver.dart`
- Создать: `C:\projects\padel_app\lib\services\waiver_service.dart`
- Изменить: `C:\projects\padel_app\lib\main.dart` — регистрация сервиса

**Берёт:** эндпоинты из задачи 4.
**Отдаёт дальше:** класс `ClubWaiver` с полями `collects`, `clubName`, `text`, `textHash`, `signedAt`, `signedText`; `WaiverService.load(int clubId)` и `WaiverService.sign(...)`.

- [x] **Шаг 1: Модель**

```dart
/// Отказ от ответственности клуба и состояние подписи текущего игрока.
class ClubWaiver {
  final bool collects;
  final String clubName;
  final String? text;

  /// Контрольная сумма текста — возвращается при подписи, чтобы сервер
  /// понял, ту ли редакцию человек читал.
  final String? textHash;

  final DateTime? signedAt;

  /// Текст, который человек подписал. Может отличаться от текущего:
  /// клуб мог поправить его после подписи.
  final String? signedText;

  const ClubWaiver({
    required this.collects,
    required this.clubName,
    this.text,
    this.textHash,
    this.signedAt,
    this.signedText,
  });

  bool get isSigned => signedAt != null;

  factory ClubWaiver.fromJson(Map<String, dynamic> json) {
    final signed = json['signed_at'] as String?;
    return ClubWaiver(
      collects: json['collects'] as bool? ?? false,
      clubName: json['club_name'] as String? ?? '',
      text: json['text'] as String?,
      textHash: json['text_hash'] as String?,
      signedAt: signed == null ? null : DateTime.tryParse(signed),
      signedText: json['signed_text'] as String?,
    );
  }
}
```

- [x] **Шаг 2: Сервис**

```dart
import 'dart:convert';
import 'dart:typed_data';

import '../models/club_waiver.dart';
import 'api_service.dart';
import 'storage_service.dart';

/// Ошибка «текст изменился, пока вы читали» — сервер отвечает 409.
class WaiverTextChanged implements Exception {
  WaiverTextChanged(this.text, this.textHash);

  final String text;
  final String textHash;
}

class WaiverService {
  final ApiService _api;
  final StorageService _storage;

  WaiverService(this._api, this._storage);

  Future<ClubWaiver> load(int clubId) async {
    final token = await _storage.getToken();
    final response = await _api.get('/clubs/$clubId/waiver', token);
    return ClubWaiver.fromJson(response);
  }

  /// Отправить подпись. [signature] — сырые байты PNG с холста.
  Future<DateTime> sign({
    required int clubId,
    required String fullName,
    required String textHash,
    required Uint8List signature,
  }) async {
    final token = await _storage.getToken();
    final response = await _api.post(
      '/clubs/$clubId/waiver/sign',
      {
        'full_name': fullName,
        'text_hash': textHash,
        'signature': 'data:image/png;base64,${base64Encode(signature)}',
      },
      token,
    );

    if (response['success'] != true && response['text'] is String) {
      throw WaiverTextChanged(
        response['text'] as String,
        response['text_hash'] as String? ?? '',
      );
    }

    return DateTime.tryParse(response['signed_at'] as String? ?? '') ?? DateTime.now();
  }
}
```

Зарегистрировать в `main.dart` тем же способом, что и `AchievementService`: создать рядом с остальными сервисами и добавить `Provider<WaiverService>.value(...)` в список провайдеров.

- [x] **Шаг 3: Проверить**

Запуск: `flutter analyze lib/models/club_waiver.dart lib/services/waiver_service.dart lib/main.dart`
Ожидание: 0 errors.

- [x] **Шаг 4: Коммит**

```bash
git add lib/models/club_waiver.dart lib/services/waiver_service.dart lib/main.dart
git commit -m "feat(waiver): модель и сервис отказа от ответственности"
```

---

### Задача 7: Приложение — экран подписи

**Файлы:**
- Создать: `C:\projects\padel_app\lib\widgets\waiver\signature_pad.dart`
- Создать: `C:\projects\padel_app\lib\screens\club_waiver_screen.dart`

**Берёт:** `ClubWaiver`, `WaiverService` из задачи 6.
**Отдаёт дальше:** `SignaturePad` с контроллером `SignaturePadController` (методы `clear()`, `isEmpty`, `Future<Uint8List?> toPng()`), экран `ClubWaiverScreen(clubId: int)`.

Холст подписи пишется руками, без пакета: `GestureDetector` копит точки штрихов, `CustomPainter` их рисует, `ui.PictureRecorder` отдаёт PNG. Новых зависимостей не появляется.

- [x] **Шаг 1: Холст подписи**

```dart
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

/// Управление холстом снаружи: очистка, проверка на пустоту, выгрузка PNG.
class SignaturePadController extends ChangeNotifier {
  final List<List<Offset>> _strokes = [];
  Size _size = Size.zero;

  bool get isEmpty => _strokes.every((s) => s.isEmpty);

  void clear() {
    _strokes.clear();
    notifyListeners();
  }

  /// PNG на белом фоне: подпись потом печатают и подшивают к документам.
  Future<Uint8List?> toPng() async {
    if (isEmpty || _size.isEmpty) return null;

    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    canvas.drawRect(Offset.zero & _size, Paint()..color = Colors.white);
    _paintStrokes(canvas, Colors.black);

    final image = await recorder
        .endRecording()
        .toImage(_size.width.round(), _size.height.round());
    final data = await image.toByteData(format: ui.ImageByteFormat.png);

    return data?.buffer.asUint8List();
  }

  void _paintStrokes(Canvas canvas, Color color) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 2.6
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    for (final stroke in _strokes) {
      if (stroke.length < 2) continue;
      final path = Path()..moveTo(stroke.first.dx, stroke.first.dy);
      for (final point in stroke.skip(1)) {
        path.lineTo(point.dx, point.dy);
      }
      canvas.drawPath(path, paint);
    }
  }
}

/// Поле для подписи пальцем.
class SignaturePad extends StatefulWidget {
  const SignaturePad({super.key, required this.controller, this.height = 180});

  final SignaturePadController controller;
  final double height;

  @override
  State<SignaturePad> createState() => _SignaturePadState();
}
```

Состояние: `GestureDetector` с `onPanStart` (новый штрих), `onPanUpdate` (добавить точку, `setState`), `onPanEnd`. Внутри `CustomPaint` с painter, который зовёт `controller._paintStrokes(canvas, Colors.black)`. Размер холста запоминается в `controller._size` через `LayoutBuilder`.

- [x] **Шаг 2: Экран**

`ClubWaiverScreen(clubId)`:

- при открытии зовёт `WaiverService.load(clubId)`;
- `collects == false` — «Этот клуб не собирает отказ»;
- `isSigned` — «Подписано {дата}» и кнопка «Посмотреть текст», открывающая `signedText`;
- иначе: название клуба, текст с прокруткой, поле ФИО (подставлено `profile.user.name`), `SignaturePad`, кнопка «Очистить» и кнопка «Подписываю»;
- «Подписываю» доступна, когда ФИО не короче трёх символов и `controller.isEmpty == false`;
- на `WaiverTextChanged` показывает диалог «Текст изменился, перечитайте», подставляет свежий текст и очищает холст;
- шапка как на остальных вложенных экранах: `AppBackButton` в отступе слева, заголовок 20/w800.

- [x] **Шаг 3: Проверить**

Запуск: `flutter analyze lib/`
Ожидание: 0 errors.

- [x] **Шаг 4: Коммит**

```bash
git add lib/widgets/waiver lib/screens/club_waiver_screen.dart
git commit -m "feat(waiver): экран подписи с холстом"
```

---

### Задача 8: Приложение — диплинк

**Файлы:**
- Изменить: `C:\projects\padel_app\lib\services\deep_link_service.dart` — разбор `padelp://waiver/{club}` и `https://padel-p.kz/w/{club}`

**Берёт:** `ClubWaiverScreen` из задачи 7.

- [x] **Шаг 1: Разбор ссылки**

В `_handleUri`, перед разбором клуба (иначе `/w/` не спутается ни с чем):

```dart
    // --- Отказ от ответственности ---
    // padelp://waiver/12  — host=waiver, segments=[12]
    // https://padel-p.kz/w/12 — segments=[w, 12]
    int? waiverClubId;
    if (uri.host == 'waiver' && uri.pathSegments.isNotEmpty) {
      waiverClubId = int.tryParse(uri.pathSegments.first);
    } else if (uri.pathSegments.length >= 2 &&
        (uri.pathSegments[0] == 'w' || uri.pathSegments[0] == 'waiver')) {
      waiverClubId = int.tryParse(uri.pathSegments[1]);
    }

    if (waiverClubId != null) {
      _navigateToWaiver(waiverClubId, fromInitial: fromInitial);
      return;
    }
```

плюс метод `_navigateToWaiver`, повторяющий `_navigateToTournament`: ожидание готовности навигатора до ста попыток по 100 мс, затем `push` на `ClubWaiverScreen(clubId: id)`.

- [x] **Шаг 2: Проверить**

Запуск: `flutter analyze lib/`
Ожидание: 0 errors.

- [x] **Шаг 3: Коммит и пуш**

```bash
git add lib/services/deep_link_service.dart
git commit -m "feat(waiver): переход на подпись по ссылке из QR"
git push
```

---

## Деплой

```bash
git pull
php artisan migrate --path=database/migrations/2026_08_17_000002_add_waiver_to_clubs_table.php
php artisan migrate --path=database/migrations/2026_08_17_000003_create_club_waiver_signatures_table.php
php artisan route:clear
php artisan view:clear
```

Подписи ложатся в `storage/app/waivers/` — каталог создаётся сам, права те же, что у остального `storage`.

Экран подписи и переход по QR приедут со следующей сборкой приложения. До неё веб-часть работает: клуб включает отказ, печатает QR, но подписать ещё нечем.
