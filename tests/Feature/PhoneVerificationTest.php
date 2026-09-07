<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TelegramPhoneLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Телефон подтверждается кодом, а не доверием.
 *
 * Аккаунт через Apple/Google создаётся без номера. Раньше пустой телефон
 * можно было вписать прямо в профиле — без СМС. Так у игрока 2503 в базе
 * оказался чужой номер: он ошибся цифрой, а проверить было нечем.
 */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_профиль_не_принимает_телефон(): void
    {
        $user = User::factory()->create(['phone' => null]);
        Sanctum::actingAs($user);

        $this->putJson('/api/mobile/profile', [
            'name' => 'Василий Моисеев',
            'phone' => '77701829772',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Номер телефона подтверждается кодом из СМС');

        $this->assertNull($user->fresh()->phone);
    }

    public function test_остальные_поля_профиля_сохраняются(): void
    {
        $user = User::factory()->create(['phone' => '77771112233']);
        Sanctum::actingAs($user);

        // Тот же телефон в теле — не смена, ошибки быть не должно.
        $this->putJson('/api/mobile/profile', [
            'name' => 'Василий Моисеев',
            'phone' => '77771112233',
        ])->assertOk();

        $this->assertSame('Василий Моисеев', $user->fresh()->name);
    }

    public function test_без_номера_код_приходит_сразу(): void
    {
        // Вход через Apple: старого номера нет, подтверждать нечего.
        $user = User::factory()->create(['phone' => null]);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/auth/phone/send-new-code', [
            'phone' => '+7 (777) 111-22-33',
        ])->assertOk();

        $this->postJson('/api/mobile/auth/phone/confirm-new', ['code' => '1111'])
            ->assertOk()
            ->assertJsonPath('phone', '77771112233');

        $fresh = $user->fresh();
        $this->assertSame('77771112233', $fresh->phone);
        $this->assertNotNull($fresh->phone_verified_at, 'номер помечен подтверждённым');
    }

    public function test_чужой_номер_занять_нельзя(): void
    {
        User::factory()->create(['phone' => '77771112233']);
        $user = User::factory()->create(['phone' => null]);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/auth/phone/send-new-code', [
            'phone' => '77771112233',
        ])->assertStatus(400)
          ->assertJsonPath('message', 'Этот номер уже занят');
    }

    public function test_смена_номера_у_кого_он_есть_требует_старый_код(): void
    {
        $user = User::factory()->create(['phone' => '77771112233']);
        Sanctum::actingAs($user);

        // Без подтверждения старого номера — от ворот поворот.
        $this->postJson('/api/mobile/auth/phone/send-new-code', [
            'phone' => '77009998877',
        ])->assertStatus(400)
          ->assertJsonPath('message', 'Сначала подтвердите старый номер');
    }

    public function test_вход_по_коду_помечает_номер_подтверждённым(): void
    {
        Cache::put('sms_code_77771112233', '4321', now()->addMinutes(5));

        $this->postJson('/api/mobile/auth/verify-code', [
            'phone' => '77771112233',
            'code' => '4321',
        ])->assertOk();

        $user = User::where('phone', '77771112233')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
    }

    public function test_номер_от_телеграма_считается_подтверждённым(): void
    {
        $user = User::factory()->create(['phone' => null, 'telegram_id' => '777']);

        TelegramPhoneLinker::linkPhone($user, '+7 777 111 22 33');

        $fresh = $user->fresh();
        $this->assertSame('77771112233', $fresh->phone);
        $this->assertNotNull($fresh->phone_verified_at, 'номер дал сам телеграм');
    }
}
