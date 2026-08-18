<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /** Список сертификатов клуба. */
    public function index()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $certificates = Certificate::where('club_id', $club->id)
            ->latest()
            ->paginate(30);

        return view('club.certificates.index', compact('certificates', 'club'));
    }

    /** Создать сертификат (именной или обычный) с уникальным номером. */
    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $data = $request->validate([
            'type' => 'required|in:named,generic',
            'recipient_name' => 'required_if:type,named|nullable|string|max:255',
            'client_id' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'value_type' => 'required|in:amount,hours,tournament',
            'amount' => 'required_if:value_type,amount|nullable|integer|min:1|max:100000000',
            'hours' => 'required_if:value_type,hours|nullable|integer|min:1|max:1000',
            'tournaments' => 'required_if:value_type,tournament|nullable|integer|min:1|max:1000',
        ], [
            'recipient_name.required_if' => 'Укажите ФИО для именного сертификата.',
            'amount.required_if' => 'Укажите сумму сертификата.',
            'hours.required_if' => 'Укажите количество часов.',
            'tournaments.required_if' => 'Укажите количество турниров.',
        ]);

        // Привязка к клиенту — только если он из этого клуба.
        $clientId = null;
        if ($data['type'] === Certificate::TYPE_NAMED && !empty($data['client_id'])) {
            $clientId = \App\Models\ClubClient::where('club_id', $club->id)
                ->where('id', $data['client_id'])
                ->value('id');
        }

        $vt = $data['value_type'];
        $template = CertificateTemplate::defaultForClub($club->id);

        $certificate = Certificate::create([
            'club_id' => $club->id,
            'type' => $data['type'],
            'recipient_name' => $data['type'] === Certificate::TYPE_NAMED ? trim($data['recipient_name']) : null,
            'client_id' => $clientId,
            'value_type' => $vt,
            'amount' => $vt === Certificate::VALUE_AMOUNT ? $data['amount'] : null,
            'hours' => $vt === Certificate::VALUE_HOURS ? $data['hours'] : null,
            'tournaments' => $vt === Certificate::VALUE_TOURNAMENT ? $data['tournaments'] : null,
            'number' => Certificate::generateNumber($club->id, $template->number_prefix),
            'title' => $data['title'] ?? null,
            'template_id' => $template->id,
            'created_by' => auth()->id(),
        ]);

        return redirect()
            ->route('club.certificates.index')
            ->with('success', 'Сертификат создан: ' . $certificate->number);
    }

    /** Печатный шаблон сертификата. */
    public function show(Certificate $certificate)
    {
        $club = $this->getClub();
        if (!$club || $certificate->club_id !== $club->id) abort(403);

        $template = $certificate->template ?? CertificateTemplate::defaultForClub($club->id);

        return view('club.certificates.template', compact('certificate', 'club', 'template'));
    }

    /** Конструктор шаблона (дизайн сертификата). */
    public function design()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $template = CertificateTemplate::defaultForClub($club->id);

        return view('club.certificates.design', compact('template', 'club'));
    }

    /** Сохранить дизайн шаблона. */
    public function designUpdate(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $template = CertificateTemplate::defaultForClub($club->id);

        $request->validate([
            'number_prefix' => 'nullable|regex:/^[A-Za-z0-9_-]{1,30}$/',
            'heading' => 'nullable|string|max:120',
            'subtitle_named' => 'nullable|string|max:200',
            'subtitle_generic' => 'nullable|string|max:200',
            'body_text' => 'nullable|string|max:500',
            'background_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'accent_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'border_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'text_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'orientation' => 'nullable|in:landscape,portrait',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
            'background_image' => 'nullable|image|max:6144',
            'remove_background' => 'nullable|boolean',
            'layout' => 'nullable|string',
        ]);

        // Старые поля (цвета/тексты) обновляем только если пришли — новый
        // конструктор (по картинке) их может не присылать.
        $fields = [];
        foreach ([
            'heading', 'subtitle_named', 'subtitle_generic', 'body_text',
            'background_color', 'accent_color', 'border_color', 'text_color', 'orientation',
        ] as $k) {
            if ($request->has($k)) {
                $fields[$k] = $request->input($k);
            }
        }
        if ($request->filled('number_prefix')) {
            $fields['number_prefix'] = trim($request->input('number_prefix'));
        }

        // Логотип.
        if ($request->boolean('remove_logo') && $template->logo_path) {
            \Storage::disk('public')->delete($template->logo_path);
            $fields['logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($template->logo_path) {
                \Storage::disk('public')->delete($template->logo_path);
            }
            $fields['logo_path'] = $request->file('logo')->store('certificates/logos', 'public');
        }

        // Картинка-фон (режим v2).
        if ($request->boolean('remove_background') && $template->background_image_path) {
            \Storage::disk('public')->delete($template->background_image_path);
            $fields['background_image_path'] = null;
            $fields['layout'] = null;
        }
        if ($request->hasFile('background_image')) {
            if ($template->background_image_path) {
                \Storage::disk('public')->delete($template->background_image_path);
            }
            $fields['background_image_path'] =
                $request->file('background_image')->store('certificates/backgrounds', 'public');
        }

        // Позиции полей (JSON строкой).
        if ($request->filled('layout')) {
            $decoded = json_decode($request->input('layout'), true);
            if (is_array($decoded)) {
                $fields['layout'] = $this->sanitizeLayout($decoded);
            }
        }

        $template->update($fields);

        return redirect()
            ->route('club.certificates.design')
            ->with('success', 'Шаблон сохранён');
    }

    /** Валидируем позиции полей: name/value/number → x%, y%, size, color, align. */
    private function sanitizeLayout(array $in): array
    {
        $out = [];
        foreach (['name', 'value', 'number'] as $key) {
            $f = is_array($in[$key] ?? null) ? $in[$key] : [];
            $color = (string) ($f['color'] ?? '');
            $align = $f['align'] ?? 'left';
            $out[$key] = [
                'x' => max(0, min(100, (float) ($f['x'] ?? 0))),
                'y' => max(0, min(100, (float) ($f['y'] ?? 0))),
                'size' => max(8, min(120, (int) ($f['size'] ?? 30))),
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#1e2a44',
                'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : 'left',
            ];
        }
        return $out;
    }

    /** Активные сертификаты клиента по телефону (для модалки брони). */
    public function forClient(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return response()->json(['certificates' => []]);

        // Клиентов с одним номером может быть несколько — берём сертификаты
        // со всех, иначе найдётся не та запись.
        $clientIds = \App\Models\ClubClient::where('club_id', $club->id)
            ->byPhone((string) $request->get('phone', ''))
            ->pluck('id');
        if ($clientIds->isEmpty()) return response()->json(['certificates' => []]);

        // При редактировании брони её сертификат уже погашен (used_at) — чтобы он
        // отображался и был выбран, его id передаётся в include и включается всегда.
        $includeId = (int) $request->get('include', 0);

        $certs = Certificate::whereIn('client_id', $clientIds)
            ->whereIn('value_type', [Certificate::VALUE_AMOUNT, Certificate::VALUE_HOURS])
            ->where(function ($q) use ($includeId) {
                $q->whereNull('used_at');
                if ($includeId > 0) {
                    $q->orWhere('id', $includeId);
                }
            })
            ->latest()
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'value_type' => $c->value_type,
                'amount' => (int) ($c->amount ?? 0),
                'hours' => (int) ($c->hours ?? 0),
                'is_free' => $c->value_type === Certificate::VALUE_HOURS,
                'label' => $c->valueLabel(),
            ]);

        return response()->json(['certificates' => $certs]);
    }

    /** Сертификаты конкретного клиента. */
    public function client(\App\Models\ClubClient $client)
    {
        $club = $this->getClub();
        if (!$club || $client->club_id !== $club->id) abort(403);

        $certificates = Certificate::where('client_id', $client->id)
            ->latest()
            ->get();

        return view('club.certificates.client', compact('certificates', 'client', 'club'));
    }

    /** Погасить / вернуть в активные. */
    public function redeem(Certificate $certificate)
    {
        $club = $this->getClub();
        if (!$club || $certificate->club_id !== $club->id) abort(403);

        $certificate->update(['used_at' => $certificate->used_at ? null : now()]);

        return back()->with('success', $certificate->isUsed()
            ? 'Сертификат погашен: ' . $certificate->number
            : 'Сертификат снова активен: ' . $certificate->number);
    }

    /** Удалить сертификат. */
    public function destroy(Certificate $certificate)
    {
        $club = $this->getClub();
        if (!$club || $certificate->club_id !== $club->id) abort(403);

        if ($certificate->isUsed()) {
            return back()->with('error', 'Нельзя удалить погашенный сертификат. Чтобы вернуть его — отмените бронь, в которой он использован.');
        }

        $number = $certificate->number;
        $certificate->delete();

        return redirect()
            ->route('club.certificates.index')
            ->with('success', 'Сертификат удалён: ' . $number);
    }
}
