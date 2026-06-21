<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\TelegramPhoneLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramPhoneLinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_phone_when_no_collision(): void
    {
        $user = User::factory()->create(['telegram_id' => '111', 'phone' => null]);

        [$canonical, $merged] = TelegramPhoneLinker::linkPhone($user, '+7 705 279 04 41');

        $this->assertFalse($merged);
        $this->assertSame($user->id, $canonical->id);
        $this->assertSame('77052790441', $canonical->fresh()->phone);
    }

    public function test_merges_into_existing_account_with_same_phone(): void
    {
        // Существующий аккаунт уже владеет номером, у него нет telegram_id.
        $existing = User::factory()->create(['phone' => '77471485216', 'telegram_id' => null]);
        // Свежий telegram-аккаунт без телефона.
        $duplicate = User::factory()->create(['phone' => null, 'telegram_id' => '999']);

        [$canonical, $merged] = TelegramPhoneLinker::linkPhone($duplicate, '77471485216');

        $this->assertTrue($merged);
        // Возвращается существующий аккаунт.
        $this->assertSame($existing->id, $canonical->id);
        // telegram_id перенесён на существующий.
        $this->assertSame('999', $existing->fresh()->telegram_id);
        // У дубля telegram_id отвязан (чтобы поиск не попадал на него).
        $this->assertNull($duplicate->fresh()->telegram_id);
        // Дубль не получил телефон — второго аккаунта с этим номером не появилось.
        $this->assertNull($duplicate->fresh()->phone);
        $this->assertSame(1, User::where('phone', '77471485216')->count());
    }

    public function test_does_not_overwrite_existing_telegram_id(): void
    {
        $existing = User::factory()->create(['phone' => '77001112233', 'telegram_id' => '555']);
        $duplicate = User::factory()->create(['phone' => null, 'telegram_id' => '777']);

        [$canonical, $merged] = TelegramPhoneLinker::linkPhone($duplicate, '77001112233');

        $this->assertTrue($merged);
        $this->assertSame($existing->id, $canonical->id);
        // У существующего telegram_id не перезаписан.
        $this->assertSame('555', $existing->fresh()->telegram_id);
        // Дубль сохранил свой telegram_id (он не совпал с перенесённым).
        $this->assertSame('777', $duplicate->fresh()->telegram_id);
    }

    public function test_empty_phone_is_noop(): void
    {
        $user = User::factory()->create(['telegram_id' => '111', 'phone' => null]);

        [$canonical, $merged] = TelegramPhoneLinker::linkPhone($user, '   ');

        $this->assertFalse($merged);
        $this->assertSame($user->id, $canonical->id);
        $this->assertNull($canonical->fresh()->phone);
    }
}
