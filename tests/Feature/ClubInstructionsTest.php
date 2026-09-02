<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubInstruction;
use App\Models\ClubInstructionSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Должностные инструкции клуба.
 *
 * У каждого клуба свои: чужие не видны и не правятся. Пишет админ клуба,
 * менеджер смены только читает — инструкция нужна ему во время работы.
 */
class ClubInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function section(string $title = 'Открытие смены', ?Club $club = null): ClubInstructionSection
    {
        return ClubInstructionSection::create([
            'club_id' => ($club ?? $this->club)->id,
            'title' => $title,
        ]);
    }

    private function moderator(): User
    {
        $user = User::factory()->create(['role' => 'club_moderator']);
        $user->moderatorClubs()->attach($this->club->id);

        return $user;
    }

    public function test_админ_создаёт_раздел_и_инструкцию(): void
    {
        $this->actingAs($this->admin)
            ->post(route('club.instructions.sections.store'), ['title' => 'Брони'])
            ->assertRedirect();

        $section = ClubInstructionSection::where('club_id', $this->club->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('club.instructions.store'), [
                'section_id' => $section->id,
                'title' => 'Приём брони по телефону',
                'body' => '<p>Спросить имя и телефон</p><ul><li>Проверить корт</li></ul>',
            ])
            ->assertRedirect();

        $instruction = ClubInstruction::firstOrFail();
        $this->assertSame($this->club->id, $instruction->club_id);
        $this->assertSame($this->admin->id, $instruction->updated_by);
        $this->assertStringContainsString('Проверить корт', $instruction->body);

        $this->actingAs($this->admin)
            ->get(route('club.instructions.index'))
            ->assertOk()
            ->assertSee('Брони')
            ->assertSee('Приём брони по телефону');
    }

    public function test_разметка_чистится_от_скриптов(): void
    {
        $section = $this->section();

        $this->actingAs($this->admin)->post(route('club.instructions.store'), [
            'section_id' => $section->id,
            'title' => 'Проверка',
            'body' => '<p onclick="alert(1)">Шаг</p><script>alert(2)</script>'
                . '<a href="javascript:alert(3)">ссылка</a>',
        ])->assertRedirect();

        $body = ClubInstruction::firstOrFail()->body;

        $this->assertStringContainsString('Шаг', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('javascript:', $body);
    }

    public function test_менеджер_смены_читает_но_не_правит(): void
    {
        $section = $this->section();
        $instruction = ClubInstruction::create([
            'club_id' => $this->club->id,
            'section_id' => $section->id,
            'title' => 'Открыть смену',
            'body' => '<p>Проверить корты</p>',
        ]);

        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('club.instructions.index'))
            ->assertOk()
            ->assertSee('Открыть смену');

        $this->actingAs($moderator)
            ->get(route('club.instructions.show', $instruction))
            ->assertOk()
            ->assertSee('Проверить корты', false);

        // Правка закрыта: и форма, и сохранение.
        $this->actingAs($moderator)
            ->get(route('club.instructions.edit', $instruction))
            ->assertForbidden();

        $this->actingAs($moderator)
            ->post(route('club.instructions.sections.store'), ['title' => 'Своё'])
            ->assertForbidden();
    }

    public function test_чужой_клуб_не_видит_и_не_правит(): void
    {
        $other = Club::create(['name' => 'Другой клуб', 'address' => 'Б', 'city' => 'Астана']);
        $otherAdmin = User::factory()->create(['role' => 'club_admin']);
        $otherAdmin->adminClubs()->attach($other->id);

        $section = $this->section();
        $instruction = ClubInstruction::create([
            'club_id' => $this->club->id,
            'section_id' => $section->id,
            'title' => 'Секрет клуба',
        ]);

        $this->actingAs($otherAdmin)
            ->get(route('club.instructions.index'))
            ->assertOk()
            ->assertDontSee('Секрет клуба');

        $this->actingAs($otherAdmin)
            ->get(route('club.instructions.show', $instruction))
            ->assertForbidden();

        $this->actingAs($otherAdmin)
            ->delete(route('club.instructions.destroy', $instruction))
            ->assertForbidden();
    }

    public function test_инструкцию_нельзя_переложить_в_чужой_раздел(): void
    {
        $other = Club::create(['name' => 'Другой клуб', 'address' => 'Б', 'city' => 'Астана']);
        $foreign = $this->section('Чужой раздел', $other);

        $this->actingAs($this->admin)->post(route('club.instructions.store'), [
            'section_id' => $foreign->id,
            'title' => 'Попытка',
        ])->assertStatus(422);

        $this->assertSame(0, ClubInstruction::count());
    }

    public function test_порядок_инструкций_меняется_кнопками(): void
    {
        $section = $this->section();
        $first = ClubInstruction::create([
            'club_id' => $this->club->id, 'section_id' => $section->id,
            'title' => 'Первая', 'sort_order' => 1,
        ]);
        $second = ClubInstruction::create([
            'club_id' => $this->club->id, 'section_id' => $section->id,
            'title' => 'Вторая', 'sort_order' => 2,
        ]);

        $this->actingAs($this->admin)
            ->post(route('club.instructions.move', $second), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
    }

    public function test_удаление_раздела_уносит_инструкции(): void
    {
        $section = $this->section();
        ClubInstruction::create([
            'club_id' => $this->club->id, 'section_id' => $section->id, 'title' => 'Внутри',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('club.instructions.sections.destroy', $section))
            ->assertRedirect();

        $this->assertSame(0, ClubInstructionSection::count());
        $this->assertSame(0, ClubInstruction::count(), 'инструкции раздела уходят вместе с ним');
    }

    public function test_к_инструкции_прикладываются_файлы(): void
    {
        $section = $this->section();

        $this->actingAs($this->admin)->post(route('club.instructions.store'), [
            'section_id' => $section->id,
            'title' => 'Со скриншотом',
            'files' => [UploadedFile::fake()->image('screen.png', 20, 20)],
        ])->assertRedirect();

        $instruction = ClubInstruction::firstOrFail();
        $file = $instruction->files()->firstOrFail();

        $this->assertTrue($file->is_image);
        $this->assertStringContainsString('/club_instructions/' . $this->club->id . '/', $file->path);
        $this->assertFileExists(public_path(ltrim($file->path, '/')));

        // Прибираем за тестом: файлы кладутся в public, а не во временный диск.
        @unlink(public_path(ltrim($file->path, '/')));
    }

    public function test_чужой_файл_не_удалить(): void
    {
        $section = $this->section();
        $instruction = ClubInstruction::create([
            'club_id' => $this->club->id, 'section_id' => $section->id, 'title' => 'Своя',
        ]);
        $file = $instruction->files()->create([
            'path' => '/club_instructions/999/x.png', 'name' => 'x.png', 'size' => 10, 'is_image' => true,
        ]);

        $other = Club::create(['name' => 'Другой клуб', 'address' => 'Б', 'city' => 'Астана']);
        $otherAdmin = User::factory()->create(['role' => 'club_admin']);
        $otherAdmin->adminClubs()->attach($other->id);

        $this->actingAs($otherAdmin)
            ->delete(route('club.instructions.files.destroy', $file))
            ->assertForbidden();

        $this->assertSame(1, $instruction->files()->count());
    }
}
