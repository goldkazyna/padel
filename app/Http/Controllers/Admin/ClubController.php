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
        $clubs = Club::withCount('admins')->get();
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
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $club->update($validated);

        return redirect()->route('admin.clubs.index')->with('success', 'Клуб обновлён!');
    }
	public function admins(Club $club)
	{
		$club->load('admins');
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
}