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
            'telegram_url' => 'nullable|url|max:500',
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
            'telegram_url' => 'nullable|url|max:500',
            'telegram_channel_id' => 'nullable|string|max:255',
            'telegram_bot_token' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'features' => 'nullable|array',
            'features.*' => 'boolean',
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);

        $features = $request->input('features', []);
        $validated['features'] = [
            'tournaments' => (bool) ($features['tournaments'] ?? true),
            'users' => (bool) ($features['users'] ?? true),
            'courts' => (bool) ($features['courts'] ?? true),
            'coaches' => (bool) ($features['coaches'] ?? true),
            'coach_booking' => (bool) ($features['coach_booking'] ?? false),
            'clients' => (bool) ($features['clients'] ?? true),
            'activity_log' => (bool) ($features['activity_log'] ?? true),
            'moderators' => (bool) ($features['moderators'] ?? true),
        ];

        // Удаление текущего логотипа (если поставлен чекбокс)
        if ($request->boolean('remove_logo') && $club->logo) {
            $this->deleteClubLogoFile($club->logo);
            $validated['logo'] = null;
        }
        unset($validated['remove_logo']);

        // Загрузка нового логотипа — имя как slug клуба, в БД сохраняем
        // путь /logos/<slug>.<ext> (как у существующих записей — этим путём
        // MobileClubController отдаёт через url($club->logo)).
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $slug = \Illuminate\Support\Str::slug($validated['name'] ?? $club->name) ?: ('club-' . $club->id);
            $filename = $slug . '.' . $ext;

            // Удаляем старый файл, плюс любой другой файл с тем же slug
            // (другое расширение — чтобы не оставлять дубль).
            if ($club->logo) {
                $this->deleteClubLogoFile($club->logo);
            }
            foreach (glob(public_path('logos/' . $slug . '.*')) ?: [] as $oldPath) {
                @unlink($oldPath);
            }

            $file->move(public_path('logos'), $filename);
            $validated['logo'] = '/logos/' . $filename;
        }

        $club->update($validated);

        return redirect()->route('admin.clubs.index')->with('success', 'Клуб обновлён!');
    }

    /**
     * Удалить локальный файл логотипа, если это не внешний URL.
     * Поддерживает оба формата записи в БД: «/logos/x.jpg» и «x.jpg».
     */
    private function deleteClubLogoFile(?string $logo): void
    {
        if (!$logo) return;
        if (preg_match('#^https?://#', $logo)) return;
        $relative = ltrim($logo, '/');
        // Убираем дублирующий префикс logos/, если он уже есть в строке
        if (str_starts_with($relative, 'logos/')) {
            $relative = substr($relative, strlen('logos/'));
        }
        $path = public_path('logos/' . $relative);
        if (is_file($path)) {
            @unlink($path);
        }
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
			'password' => 'nullable|string|min:6|max:255',
		]);

		$user = \App\Models\User::findOrFail($validated['user_id']);

		$updates = ['role' => 'club_admin'];
		if (!empty($validated['password'])) {
			$updates['password'] = bcrypt($validated['password']);
		}
		$user->update($updates);

		// Привязываем к клубу
		$club->admins()->syncWithoutDetaching([$user->id]);

		$msg = !empty($validated['password'])
			? 'Админ добавлен, пароль установлен!'
			: 'Админ добавлен!';
		return redirect()->route('admin.clubs.admins', $club)->with('success', $msg);
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
		$validated = $request->validate([
			'user_id' => 'required|exists:users,id',
			'password' => 'nullable|string|min:6|max:255',
		]);

		$user = User::findOrFail($validated['user_id']);

		$updates = ['role' => 'club_moderator'];
		if (!empty($validated['password'])) {
			$updates['password'] = bcrypt($validated['password']);
		}
		$user->update($updates);

		// Привязываем к клубу
		$club->moderators()->syncWithoutDetaching([$user->id]);

		$msg = !empty($validated['password'])
			? 'Модератор добавлен, пароль установлен'
			: 'Модератор добавлен';
		return back()->with('success', $msg);
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