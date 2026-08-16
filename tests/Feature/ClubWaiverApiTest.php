<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API отказа от ответственности: чтение текста и подпись.
 */
class ClubWaiverApiTest extends TestCase
{
    use RefreshDatabase;

    /** Крошечный непрозрачный PNG 2×2 — сойдёт за росчерк. */
    private function pngBase64(): string
    {
        $img = imagecreatetruecolor(2, 2);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagesetpixel($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
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
            'full_name' => 'Иванов Иван', 'text_hash' => $club->waiverTextHash(),
            'signature' => $this->pngBase64(),
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
            'full_name' => 'Иванов Иван',
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
            'full_name' => 'Иванов Иван', 'text_hash' => $club->waiverTextHash(),
            'signature' => $this->pngBase64(),
        ];
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", $body)->assertOk();
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", $body)->assertOk();

        $this->assertSame(1, ClubWaiverSignature::count(), 'двойной тап не плодит подписи');
    }

    public function test_blank_signature_is_refused(): void
    {
        Storage::fake('local');
        $club = $this->club();
        Sanctum::actingAs(User::factory()->create());

        $blank = imagecreatetruecolor(4, 4);
        imagefill($blank, 0, 0, imagecolorallocate($blank, 255, 255, 255));
        ob_start();
        imagepng($blank);
        $raw = ob_get_clean();
        imagedestroy($blank);

        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [
            'full_name' => 'Иванов Иван',
            'text_hash' => $club->waiverTextHash(),
            'signature' => 'data:image/png;base64,' . base64_encode($raw),
        ])->assertStatus(422);

        $this->assertSame(0, ClubWaiverSignature::count());
    }

    public function test_disabled_waiver_refuses_the_signature(): void
    {
        Storage::fake('local');
        $club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/clubs/{$club->id}/waiver")
            ->assertOk()->assertJsonPath('collects', false);

        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [
            'full_name' => 'Иванов Иван', 'text_hash' => 'x', 'signature' => $this->pngBase64(),
        ])->assertStatus(422);

        $this->assertSame(0, ClubWaiverSignature::count());
    }

    public function test_guest_cannot_sign(): void
    {
        $club = $this->club();
        $this->postJson("/api/mobile/clubs/{$club->id}/waiver/sign", [])->assertUnauthorized();
    }
}
