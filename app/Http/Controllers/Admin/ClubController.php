<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\User;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    public function index()
    {
        $clubs = Club::withCount(['admins', 'tournaments'])->get();
        return view('admin.clubs.index', compact('clubs'));
    }

    public function create()
    {
        return view('admin.clubs.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string',
        ]);

        Club::create($validated);

        return redirect()->route('admin.clubs.index')->with('success', 'Клуб создан!');
    }

    public function edit(Club $club)
    {
        return view('admin.clubs.edit', compact('club'));
    }

    public function update(Request $request, Club $club)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'nullable|string|in:Алматы,Астана,Шымкент,Караганда,Актобе',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string',
            'payment_url' => 'nullable|url|max:500',
            'is_active' => 'boolean',
            'features' => 'nullable|array',
            'features.*' => 'boolean',
        ]);

        $features = $request->input('features', []);
        $validated['features'] = [
            'tournaments' => (bool) ($features['tournaments'] ?? true),
            'users' => (bool) ($features['users'] ?? true),
            'courts' => (bool) ($features['courts'] ?? true),
        ];

        $club->update($validated);

        return redirect()->route('admin.clubs.index')->with('success', 'Клуб обновлён!');
    }
	public function admins(Club $club)
	{
		$club->load(['admins', 'moderators']);
		$players = \App\Models\User::where('role', 'player')->get();
		
		return view('admin.clubs.admins', compact('club', 'players'));
	}

	public function addAdmin(Request $request, Club $club)
	{
		$validated = $request->validate([
			'user_id' => 'required|exists:users,id',
		]);

		$user = \App\Models\User::findOrFail($validated['user_id']);
		
		// Меняем роль на club_admin
		$user->update(['role' => 'club_admin']);
		
		// Привязываем к клубу
		$club->admins()->syncWithoutDetaching([$user->id]);

		return redirect()->route('admin.clubs.admins', $club)->with('success', 'Админ добавлен!');
	}

	public function removeAdmin(Club $club, User $user)
	{
		// Отвязываем от клуба
		$club->admins()->detach($user->id);
		
		// Если больше не админ ни в одном клубе — возвращаем роль player
		if ($user->adminClubs()->count() === 0) {
			$user->update(['role' => 'player']);
		}

		return redirect()->route('admin.clubs.admins', $club)->with('success', 'Админ удалён!');
	}
	
	
    public function destroy(Club $club)
    {
        $club->delete();
        return redirect()->route('admin.clubs.index')->with('success', 'Клуб удалён!');
    }
	// Поиск игрока по email
	public function searchPlayer(Request $request)
	{
		$email = $request->get('email');
		
		$player = User::where('email', $email)
					  ->where('role', 'player')
					  ->first();
		
		return response()->json([
			'found' => $player ? true : false,
			'player' => $player ? [
				'id' => $player->id,
				'name' => $player->full_name,
				'email' => $player->email,
			] : null
		]);
	}
	public function addModerator(Request $request, Club $club)
	{
		$request->validate(['user_id' => 'required|exists:users,id']);
		
		$user = User::findOrFail($request->user_id);
		
		// Меняем роль на модератора
		$user->update(['role' => 'club_moderator']);
		
		// Привязываем к клубу
		$club->moderators()->syncWithoutDetaching([$user->id]);
		
		return back()->with('success', 'Модератор добавлен');
	}

	public function removeModerator(Club $club, User $user)
	{
		$club->moderators()->detach($user->id);
		
		// Возвращаем роль player если больше нигде не модератор
		if ($user->moderatorClubs()->count() === 0) {
			$user->update(['role' => 'player']);
		}
		
		return back()->with('success', 'Модератор удалён');
	}
}