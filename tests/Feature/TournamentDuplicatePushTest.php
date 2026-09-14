<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TournamentPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Дубль турнира начинает с чистого счётчика рассылок.
 *
 * Их всего четыре на турнир. Копия наследовала израсходованные отправки
 * исходника — и по новому турниру написать участникам было уже нельзя.
 */
class TournamentDuplicatePushTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private Tournament $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->source = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'name' => 'Вечер среды',
            'type' => 'americano',
            'status' => 'open',
            // По исходнику отправки уже израсходованы.
            'push_sent_count' => TournamentPushService::MAX_SENDS,
        ]);
    }

    public function test_копия_из_приложения_не_наследует_отправки(): void
    {
        $this->assertFalse(app(TournamentPushService::class)->canSend($this->source),
            'у исходника отправки кончились');

        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/mobile/admin/tournaments/{$this->source->id}/duplicate")
            ->assertOk()->json('tournament.id');

        $copy = Tournament::findOrFail($id);

        $this->assertSame(0, (int) $copy->push_sent_count);
        $this->assertSame(TournamentPushService::MAX_SENDS,
            app(TournamentPushService::class)->remaining($copy));
    }

    public function test_копия_из_веба_не_наследует_отправки(): void
    {
        // Веб подставляет поля исходника в форму создания.
        $form = $this->actingAs($this->admin)
            ->get("/club/tournaments/create?from={$this->source->id}")
            ->assertOk();

        $this->actingAs($this->admin)->post('/club/tournaments', [
            'club_id' => $this->club->id,
            'name' => 'Вечер среды (копия)',
            'type' => 'americano',
            'start_date' => now('Asia/Almaty')->addWeek()->format('Y-m-d H:i:s'),
            'max_participants' => 8,
            'price' => 10000,
            'min_level' => 1,
            'max_level' => 4,
            'courts_count' => 2,
            'status' => 'open',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $copy = Tournament::where('name', 'Вечер среды (копия)')->firstOrFail();

        $this->assertSame(0, (int) $copy->push_sent_count);
    }
}
