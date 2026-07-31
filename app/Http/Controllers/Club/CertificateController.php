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
            'title' => 'nullable|string|max:255',
        ], [
            'recipient_name.required_if' => 'Укажите ФИО для именного сертификата.',
        ]);

        $certificate = Certificate::create([
            'club_id' => $club->id,
            'type' => $data['type'],
            'recipient_name' => $data['type'] === Certificate::TYPE_NAMED ? trim($data['recipient_name']) : null,
            'number' => Certificate::generateNumber($club->id),
            'title' => $data['title'] ?? null,
            'template_id' => CertificateTemplate::defaultForClub($club->id)->id,
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

        $data = $request->validate([
            'heading' => 'required|string|max:120',
            'subtitle_named' => 'required|string|max:200',
            'subtitle_generic' => 'required|string|max:200',
            'body_text' => 'nullable|string|max:500',
            'background_color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'accent_color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'border_color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'text_color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'orientation' => 'required|in:landscape,portrait',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);

        $fields = collect($data)->except(['logo', 'remove_logo'])->toArray();

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

        $template->update($fields);

        return redirect()
            ->route('club.certificates.design')
            ->with('success', 'Шаблон сохранён');
    }

    /** Удалить сертификат. */
    public function destroy(Certificate $certificate)
    {
        $club = $this->getClub();
        if (!$club || $certificate->club_id !== $club->id) abort(403);

        $number = $certificate->number;
        $certificate->delete();

        return redirect()
            ->route('club.certificates.index')
            ->with('success', 'Сертификат удалён: ' . $number);
    }
}
