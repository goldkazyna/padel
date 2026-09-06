<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ContactHandle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Необязательные контакты: WhatsApp, Telegram, Instagram.
 *
 * Люди вводят ник как придётся — «@denis», «t.me/denis»,
 * «https://instagram.com/denis/». Храним один вид, иначе потом ни сравнить,
 * ни собрать ссылку.
 */
class ProfileContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ник_очищается_от_адреса_и_собачки(): void
    {
        $this->assertSame('denis', ContactHandle::username('@denis'));
        $this->assertSame('denis', ContactHandle::username('t.me/denis'));
        $this->assertSame('denis', ContactHandle::username('https://t.me/denis'));
        $this->assertSame('denis', ContactHandle::username('https://www.instagram.com/denis/'));
        $this->assertSame('denis', ContactHandle::username('instagram.com/denis?igsh=abc'));
        $this->assertNull(ContactHandle::username('   '));
        $this->assertNull(ContactHandle::username(null));
    }

    public function test_номер_ватсапа_приводится_к_одиннадцати_цифрам(): void
    {
        $this->assertSame('77774333822', ContactHandle::phone('+7 (777) 433-38-22'));
        $this->assertSame('77774333822', ContactHandle::phone('87774333822'));
        $this->assertSame('77774333822', ContactHandle::phone('7774333822'));
        $this->assertNull(ContactHandle::phone('12345'));
        $this->assertNull(ContactHandle::phone(''));
    }

    public function test_контакты_сохраняются_и_приходят_обратно(): void
    {
        $user = User::factory()->create(['phone' => '77771112233']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/mobile/profile', [
            'whatsapp' => '+7 (777) 433-38-22',
            'telegram_username' => '@denis',
            'instagram' => 'https://instagram.com/denis/',
        ])->assertOk();

        $this->assertSame('77774333822', $response->json('user.whatsapp'));
        $this->assertSame('denis', $response->json('user.telegram_username'));
        $this->assertSame('denis', $response->json('user.instagram'));

        $profile = $this->getJson('/api/mobile/profile')->assertOk();
        $this->assertSame('denis', $profile->json('user.telegram_username'));
    }

    public function test_пустая_строка_стирает_контакт(): void
    {
        $user = User::factory()->create([
            'phone' => '77771112233',
            'instagram' => 'denis',
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/mobile/profile', ['instagram' => ''])->assertOk();

        $this->assertNull($user->fresh()->instagram);
    }

    public function test_кривой_номер_ватсапа_не_принимаем(): void
    {
        $user = User::factory()->create(['phone' => '77771112233']);
        Sanctum::actingAs($user);

        $this->putJson('/api/mobile/profile', ['whatsapp' => '123'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Неверный формат номера WhatsApp');

        $this->assertNull($user->fresh()->whatsapp);
    }

    public function test_бот_запоминает_ник_телеграма(): void
    {
        // Ник приходит в каждом апдейте от бота, а мы его выбрасывали: на
        // 1457 привязанных телеграм-аккаунтов не было ни одного ника.
        $user = User::factory()->create([
            'telegram_id' => '555',
            'telegram_username' => null,
        ]);

        \App\Support\TelegramPhoneLinker::adoptTelegramIdentity($user, '555', 'denis');

        $this->assertSame('denis', $user->fresh()->telegram_username);
    }

    public function test_свой_ник_бот_не_затирает(): void
    {
        $user = User::factory()->create([
            'telegram_id' => '555',
            'telegram_username' => 'моё_имя',
        ]);

        \App\Support\TelegramPhoneLinker::adoptTelegramIdentity($user, '555', 'denis');

        $this->assertSame('моё_имя', $user->fresh()->telegram_username);
    }

    public function test_админка_видит_номер_ватсапа_участника(): void
    {
        // Организатор пишет игроку из админки. Раньше кнопка вела на телефон
        // входа — а это логин, и не всегда тот номер, где человек читает.
        $club = \App\Models\Club::create([
            'name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы',
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $player = User::factory()->create([
            'phone' => '77771112233',
            'whatsapp' => '77774333822',
        ]);

        $tournament = \App\Models\Tournament::factory()->create([
            'club_id' => $club->id,
            'status' => 'open',
            'type' => 'americano',
            'creator_id' => $admin->id,
        ]);
        $tournament->participants()->attach($player->id, ['status' => 'registered']);

        Sanctum::actingAs($admin);

        $rows = $this->getJson("/api/mobile/admin/tournaments/{$tournament->id}/participants")
            ->assertOk()
            ->json('participants');

        $row = collect($rows)->firstWhere('id', $player->id);

        $this->assertNotNull($row, 'участник в списке');
        $this->assertSame('77774333822', $row['whatsapp']);
        $this->assertSame('77771112233', $row['phone'], 'телефон входа тоже на месте');
    }

    public function test_контакты_не_ломают_обычное_сохранение(): void
    {
        $user = User::factory()->create(['phone' => '77771112233', 'name' => 'Старое']);
        Sanctum::actingAs($user);

        $this->putJson('/api/mobile/profile', ['name' => 'Денис Дудников'])->assertOk();

        $this->assertSame('Денис Дудников', $user->fresh()->name);
        $this->assertNull($user->fresh()->whatsapp);
    }
}
