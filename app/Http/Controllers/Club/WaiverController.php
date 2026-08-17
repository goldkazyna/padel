<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubWaiverSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Подписи под отказом от ответственности в клубной админке.
 */
class WaiverController extends Controller
{
    /** Клуб текущего пользователя — как в остальных разделах клуба. */
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();

        return $user->adminClubs()->first();
    }

    /** Подпись видит супер-админ и свой клуб. Остальным 403. */
    private function authorizeSignature(ClubWaiverSignature $signature): void
    {
        if (auth()->user()->isSuperAdmin()) {
            return;
        }

        $club = $this->getClub();
        abort_if(!$club || $signature->club_id !== $club->id, 403);
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        abort_if(!$club, 403, 'У вас нет клуба');

        $search = trim((string) $request->get('q'));

        $signatures = ClubWaiverSignature::with('user:id,name,avatar')
            ->where('club_id', $club->id)
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D/', '', $search);
                $query->where(function ($sub) use ($search, $digits) {
                    $sub->where('full_name', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $sub->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            })
            ->orderByDesc('signed_at')
            ->paginate(50)
            ->withQueryString();

        return view('club.waivers.index', compact('signatures', 'club', 'search'));
    }

    /** Подписанный отказ для окна в карточке клиента. */
    public function show(ClubWaiverSignature $signature)
    {
        $this->authorizeSignature($signature);

        return response()->json([
            'full_name' => $signature->full_name,
            'phone' => $signature->phone,
            'signed_at' => $signature->signed_at->translatedFormat('j F Y, H:i'),
            'text' => $signature->waiver_text,
            'image_url' => route('club.waivers.image', $signature),
        ]);
    }

    /**
     * Картинка подписи.
     *
     * Файл лежит вне public: это персональные данные, и видеть их должен
     * только свой клуб или супер-админ.
     */
    public function image(ClubWaiverSignature $signature)
    {
        $this->authorizeSignature($signature);

        abort_unless(Storage::disk('local')->exists($signature->signature_path), 404);

        return response(
            Storage::disk('local')->get($signature->signature_path),
            200,
            ['Content-Type' => 'image/png']
        );
    }
}
