<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubInstruction;
use App\Models\ClubInstructionFile;
use App\Models\ClubInstructionSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Должностные инструкции клуба.
 *
 * У каждого клуба свои тексты: что и как делать на каждом этапе работы.
 * Пишет их админ клуба, читают ещё и менеджеры смены — им инструкция нужна
 * прямо во время работы, поэтому чтение шире, чем правка.
 */
class InstructionController extends Controller
{
    /** Разрешённые типы вложений: скриншоты и документы. */
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
    ];

    private function getClub(): ?Club
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();

        return $user->adminClubs()->first();
    }

    /** Правит только владелец клуба: менеджер смены инструкции читает. */
    private function canEdit(): bool
    {
        $user = auth()->user();

        return $user->isSuperAdmin() || !$user->isClubModerator();
    }

    private function assertCanEdit(): void
    {
        if (!$this->canEdit()) {
            abort(403, 'Инструкции правит администратор клуба');
        }
    }

    /** Список: разделы и инструкции внутри них. */
    public function index()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $sections = ClubInstructionSection::where('club_id', $club->id)
            ->with('instructions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('club.instructions.index', [
            'club' => $club,
            'sections' => $sections,
            'canEdit' => $this->canEdit(),
            'total' => ClubInstruction::where('club_id', $club->id)->count(),
        ]);
    }

    /** Одна инструкция целиком. */
    public function show(ClubInstruction $instruction)
    {
        $club = $this->getClub();
        if (!$club || $instruction->club_id !== $club->id) abort(403);

        $instruction->load(['section', 'files', 'editor']);

        return view('club.instructions.show', [
            'club' => $club,
            'instruction' => $instruction,
            'canEdit' => $this->canEdit(),
        ]);
    }

    // ── Разделы ──────────────────────────────────────────────────────────

    public function storeSection(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        $this->assertCanEdit();

        $data = $request->validate(['title' => 'required|string|max:120']);

        ClubInstructionSection::create([
            'club_id' => $club->id,
            'title' => $data['title'],
            'sort_order' => (int) ClubInstructionSection::where('club_id', $club->id)->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Раздел добавлен');
    }

    public function updateSection(Request $request, ClubInstructionSection $section)
    {
        $club = $this->getClub();
        if (!$club || $section->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        $section->update($request->validate(['title' => 'required|string|max:120']));

        return back()->with('success', 'Раздел переименован');
    }

    public function destroySection(ClubInstructionSection $section)
    {
        $club = $this->getClub();
        if (!$club || $section->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        // Инструкции раздела уходят вместе с ним — предупреждение в интерфейсе.
        foreach ($section->instructions as $instruction) {
            $this->deleteFiles($instruction);
        }
        $section->delete();

        return redirect()->route('club.instructions.index')->with('success', 'Раздел удалён');
    }

    // ── Инструкции ───────────────────────────────────────────────────────

    public function create(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        $this->assertCanEdit();

        $sections = ClubInstructionSection::where('club_id', $club->id)
            ->orderBy('sort_order')->orderBy('id')->get();

        if ($sections->isEmpty()) {
            return redirect()->route('club.instructions.index')
                ->with('error', 'Сначала создайте раздел — инструкции живут внутри разделов');
        }

        return view('club.instructions.form', [
            'club' => $club,
            'sections' => $sections,
            'instruction' => new ClubInstruction([
                'section_id' => (int) $request->get('section_id') ?: $sections->first()->id,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        $this->assertCanEdit();

        $data = $this->validated($request, $club);

        $instruction = ClubInstruction::create($data + [
            'club_id' => $club->id,
            'updated_by' => auth()->id(),
            'sort_order' => (int) ClubInstruction::where('section_id', $data['section_id'])->max('sort_order') + 1,
        ]);

        $this->attachFiles($request, $instruction);

        return redirect()->route('club.instructions.show', $instruction)
            ->with('success', 'Инструкция сохранена');
    }

    public function edit(ClubInstruction $instruction)
    {
        $club = $this->getClub();
        if (!$club || $instruction->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        $instruction->load('files');

        return view('club.instructions.form', [
            'club' => $club,
            'instruction' => $instruction,
            'sections' => ClubInstructionSection::where('club_id', $club->id)
                ->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, ClubInstruction $instruction)
    {
        $club = $this->getClub();
        if (!$club || $instruction->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        $instruction->update($this->validated($request, $club) + ['updated_by' => auth()->id()]);
        $this->attachFiles($request, $instruction);

        return redirect()->route('club.instructions.show', $instruction)
            ->with('success', 'Инструкция обновлена');
    }

    public function destroy(ClubInstruction $instruction)
    {
        $club = $this->getClub();
        if (!$club || $instruction->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        $this->deleteFiles($instruction);
        $instruction->delete();

        return redirect()->route('club.instructions.index')->with('success', 'Инструкция удалена');
    }

    /** Переставить инструкцию внутри раздела. */
    public function move(Request $request, ClubInstruction $instruction)
    {
        $club = $this->getClub();
        if (!$club || $instruction->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        $up = $request->get('direction') === 'up';

        $neighbour = ClubInstruction::where('section_id', $instruction->section_id)
            ->when($up,
                fn ($q) => $q->where('sort_order', '<', $instruction->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $instruction->sort_order)->orderBy('sort_order'),
            )
            ->first();

        if ($neighbour) {
            $order = $instruction->sort_order;
            $instruction->update(['sort_order' => $neighbour->sort_order]);
            $neighbour->update(['sort_order' => $order]);
        }

        return back();
    }

    /** Удалить вложение. */
    public function destroyFile(ClubInstructionFile $file)
    {
        $club = $this->getClub();
        $instruction = $file->instruction;
        if (!$club || !$instruction || $instruction->club_id !== $club->id) abort(403);
        $this->assertCanEdit();

        @unlink(public_path(ltrim($file->path, '/')));
        $file->delete();

        return back()->with('success', 'Файл удалён');
    }

    // ── Внутреннее ───────────────────────────────────────────────────────

    /** @return array{section_id:int, title:string, body:string|null} */
    private function validated(Request $request, Club $club): array
    {
        $data = $request->validate([
            'section_id' => 'required|integer',
            'title' => 'required|string|max:200',
            'body' => 'nullable|string|max:60000',
        ]);

        // Раздел — только свой: id из формы приходит от человека.
        $section = ClubInstructionSection::where('club_id', $club->id)->find($data['section_id']);
        if (!$section) {
            abort(422, 'Раздел не найден');
        }

        $data['body'] = $this->sanitize($data['body'] ?? '');

        return $data;
    }

    /**
     * Чистим разметку инструкции.
     *
     * Текст пишет свой же администратор, но редактор в браузере легко тащит
     * стили и скрипты из буфера обмена: оставляем только то, чем правда
     * оформляют инструкцию.
     */
    private function sanitize(string $html): string
    {
        $clean = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><h3><h4><a><img><blockquote><div><span>');

        // Обработчики событий и javascript: в ссылках — вырезаем.
        $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1="#"', $clean);

        return trim((string) $clean);
    }

    /** Сохранить приложенные файлы в public/club_instructions/{club}. */
    private function attachFiles(Request $request, ClubInstruction $instruction): void
    {
        $files = $request->file('files');
        if (!$files) {
            return;
        }

        $dir = public_path('club_instructions/' . $instruction->club_id);
        File::ensureDirectoryExists($dir, 0775);

        foreach ((array) $files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
                continue;
            }

            $name = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension() ?: 'bin');
            $size = $file->getSize();
            $mime = $file->getMimeType();
            $file->move($dir, $name);

            ClubInstructionFile::create([
                'instruction_id' => $instruction->id,
                'path' => '/club_instructions/' . $instruction->club_id . '/' . $name,
                'name' => mb_substr($file->getClientOriginalName() ?: $name, 0, 190),
                'mime' => $mime,
                'size' => (int) $size,
                'is_image' => str_starts_with((string) $mime, 'image/'),
            ]);
        }
    }

    private function deleteFiles(ClubInstruction $instruction): void
    {
        foreach ($instruction->files as $file) {
            @unlink(public_path(ltrim($file->path, '/')));
            $file->delete();
        }
    }
}
