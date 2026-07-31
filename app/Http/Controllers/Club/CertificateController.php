<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
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

        return view('club.certificates.template', compact('certificate', 'club'));
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
