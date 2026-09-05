<?php

namespace Tests\Feature;

use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Раздел жалоб в супер-админке.
 *
 * Жалоба без человека, который её читает, — это просто строка в таблице.
 * Поэтому экран проверяется вместе с самой возможностью жаловаться.
 */
class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reporter;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->reporter = User::factory()->create(['name' => 'Денис Дудников']);
        $this->target = User::factory()->create(['name' => 'Спамер Спамерович']);
    }

    private function report(array $over = []): ContentReport
    {
        return ContentReport::create(array_merge([
            'reporter_id' => $this->reporter->id,
            'reportable_type' => User::class,
            'reportable_id' => $this->target->id,
            'reason' => 'spam',
            'comment' => 'Рассылает рекламу тренировок',
            'status' => ContentReport::STATUS_NEW,
        ], $over));
    }

    public function test_список_показывает_кто_на_кого_и_почему(): void
    {
        $this->report();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Денис Дудников')
            ->assertSee('Спамер Спамерович')
            ->assertSee('Спам и реклама');
    }

    public function test_карточка_показывает_переписку(): void
    {
        $conversation = Conversation::between($this->reporter->id, $this->target->id);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->target->id,
            'text' => 'Купите абонемент со скидкой',
            'created_at' => now(),
        ]);

        $report = $this->report();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.show', $report))
            ->assertOk()
            ->assertSee('Купите абонемент со скидкой')
            ->assertSee('Рассылает рекламу тренировок');
    }

    public function test_жалобу_можно_пометить_разобранной(): void
    {
        $report = $this->report();

        $this->actingAs($this->admin)
            ->post(route('admin.reports.review', $report))
            ->assertRedirect(route('admin.reports.index'));

        $this->assertSame(ContentReport::STATUS_REVIEWED, $report->fresh()->status);
    }

    public function test_фильтр_по_статусу(): void
    {
        $this->report();
        $this->report(['status' => ContentReport::STATUS_REVIEWED, 'reason' => 'abuse']);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['status' => ContentReport::STATUS_REVIEWED]))
            ->assertOk()
            ->assertSee('Оскорбления')
            ->assertDontSee('Спам и реклама');
    }

    public function test_админ_клуба_в_раздел_не_ходит(): void
    {
        $clubAdmin = User::factory()->create(['role' => 'club_admin']);
        $report = $this->report();

        $this->actingAs($clubAdmin)->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs($clubAdmin)->get(route('admin.reports.show', $report))->assertForbidden();
    }
}
