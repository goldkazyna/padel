<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Пуш по этапу лиги.
 *
 * Этап — обычный турнир клуба, но ему проставляют `creator_id` (кто из админов
 * завёл вечер). Признак «личного турнира» смотрел только на это поле, поэтому
 * этап считался личным и рассылку не давали ни в приложении, ни в вебе.
 */
class LeagueStagePushTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private League $league;
    private Tournament $stage;

    protected function setUp(): void
    {
        parent::setUp();

        // Рассылка лезет в Firebase — в тестах его нет.
        $fcm = Mockery::mock(FCMNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturn(true);
        $fcm->shouldReceive('sendToUsers')->andReturn(['sent' => 0, 'failed' => 0]);
        $fcm->shouldReceive('sendMulticastToTokens')->andReturn(['sent' => 0, 'failed' => 0]);
        $this->instance(FCMNotificationService::class, $fcm);

        $this->club = Club::create(['name' => 'DAVAY PADEL', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->league = League::create([
            'club_id' => $this->club->id,
            'name' => 'Парная лига',
            'status' => 'in_progress',
            'stages_planned' => 4,
        ]);

        $this->stage = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'league_id' => $this->league->id,
            'league_stage' => 1,
            'creator_id' => $this->admin->id,   // так их и создаёт LeagueService
            'type' => 'americano_flex',
            'status' => 'open',
            'name' => '1й этап',
        ]);
    }

    public function test_этап_лиги_не_личный_турнир(): void
    {
        $this->assertFalse($this->stage->isPersonal(),
            'у этапа есть клуб — он клубный, кто бы его ни завёл');

        $personal = Tournament::factory()->create([
            'club_id' => null,
            'creator_id' => $this->admin->id,
            'status' => 'open',
        ]);
        $this->assertTrue($personal->isPersonal(), 'турнир без клуба — личный');
    }

    public function test_пуш_по_этапу_уходит_из_приложения(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/tournaments/{$this->stage->id}/send-push", [
            'title' => 'Этап сегодня',
            'body' => 'Ждём вас в 20:00',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_в_личном_турнире_рассылки_нет(): void
    {
        $personal = Tournament::factory()->create([
            'club_id' => null,
            'creator_id' => $this->admin->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/mobile/admin/tournaments/{$personal->id}/send-push")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_кнопка_пуша_есть_на_странице_лиги(): void
    {
        $html = $this->actingAs($this->admin)
            ->get("/club/leagues/{$this->league->id}")
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            route('club.tournaments.sendPush', $this->stage), $html, 'нет кнопки рассылки');
        $this->assertStringContainsString('openPushModal', $html, 'нет модалки отправки');
    }
}
