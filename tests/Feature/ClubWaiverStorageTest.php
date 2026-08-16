<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Хранилище отказа от ответственности.
 */
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
