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

    public function test_контакты_не_ломают_обычное_сохранение(): void
    {
        $user = User::factory()->create(['phone' => '77771112233', 'name' => 'Старое']);
        Sanctum::actingAs($user);

        $this->putJson('/api/mobile/profile', ['name' => 'Денис Дудников'])->assertOk();

        $this->assertSame('Денис Дудников', $user->fresh()->name);
        $this->assertNull($user->fresh()->whatsapp);
    }
}
