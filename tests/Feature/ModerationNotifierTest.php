<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\Notification;
use App\Services\ModerationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

class ModerationNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_notification_created(): void
    {
        // Firebase не настроен в тестовом окружении — подменяем Messaging заглушкой,
        // чтобы контейнер мог собрать FCMNotificationService
        $this->mock(Messaging::class);

        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $user = User::factory()->create();

        app(ModerationNotifier::class)->pending($user, $t, now()->addHours(48));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'type' => 'tournament_moderation_pending',
        ]);
    }
}
