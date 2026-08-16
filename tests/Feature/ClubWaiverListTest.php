<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Список подписей в клубной админке.
 */
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

    public function test_search_narrows_the_list(): void
    {
        [$club, $admin] = $this->scenario();
        $other = User::factory()->create(['phone' => '77009998877']);
        ClubWaiverSignature::create([
            'club_id' => $club->id, 'user_id' => $other->id,
            'full_name' => 'Петров Пётр', 'phone' => '77009998877',
            'waiver_text' => 'Текст отказа', 'signature_path' => 'waivers/1/2.png',
            'signed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('club.waivers.index', ['q' => 'Петров']))
            ->assertOk()
            ->assertSee('Петров Пётр')
            ->assertDontSee('Дудников Денис Сергеевич');
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
