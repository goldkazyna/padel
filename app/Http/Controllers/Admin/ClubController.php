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
            'instagram_url' => 'nullable|url|max:500',
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
            'instagram_url' => 'nullable|url|max:500',
            'telegram_channel_id' => 'nullable|string|max:255',
            'telegram_bot_token' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'features' => 'nullable|array',
            'features.*' => 'boolean',
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
            'cover' => 'nullable|image',
            'remove_cover' => 'nullable|boolean',
            'online_payment_enabled' => 'boolean',
            'offer_agreement' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
            'remove_offer_agreement' => 'nullable|boolean',
            'privacy_policy' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
            'remove_privacy_policy' => 'nullable|boolean',
            'goods_description' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
            'remove_goods_description' => 'nullable|boolean',
            'card_payment_description' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
            'remove_card_payment_description' => 'nullable|boolean',
        ]);

        // Чекбокс онлайн-оплаты (снятый чекбокс не приходит в запросе).
        $validated['online_payment_enabled'] = $request->boolean('online_payment_enabled');

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
            'groups' => (bool) ($features['groups'] ?? true),
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

        // Удаление текущей обложки (если поставлен чекбокс)
        if ($request->boolean('remove_cover') && $club->cover) {
            $this->deleteClubCoverFile($club->cover);
            $validated['cover'] = null;
        }
        unset($validated['remove_cover']);

        // Загрузка новой обложки — имя как slug клуба, путь /covers/<slug>.<ext>.
        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $slug = \Illuminate\Support\Str::slug($validated['name'] ?? $club->name) ?: ('club-' . $club->id);
            $filename = $slug . '.' . $ext;

            // Удаляем старый файл, плюс любой другой файл с тем же slug (другое расширение).
            if ($club->cover) {
                $this->deleteClubCoverFile($club->cover);
            }
            foreach (glob(public_path('covers/' . $slug . '.*')) ?: [] as $oldPath) {
                @unlink($oldPath);
            }

            $file->move(public_path('covers'), $filename);
            $validated['cover'] = '/covers/' . $filename;
        }

        // Документы онлайн-оплаты (оферта, политика, описание товара/услуг,
        // описание оплаты картой) — загрузка в public/club_docs/.
        foreach (['offer_agreement', 'privacy_policy', 'goods_description', 'card_payment_description'] as $docField) {
            $this->handleClubDocUpload($request, $club, $docField, $validated);
        }

        $club->update($validated);

        return redirect()->route('admin.clubs.index')->with('success', 'Клуб обновлён!');
    }

    /**
     * Загрузка/удаление файла-документа клуба (public/club_docs/<id>-<field>.<ext>).
     * Записывает в $validated[$field] путь вида «/club_docs/...» или null при удалении.
     */
    private function handleClubDocUpload(Request $request, Club $club, string $field, array &$validated): void
    {
        // Удаление текущего файла по чекбоксу
        if ($request->boolean('remove_' . $field) && $club->$field) {
            $this->deleteClubDocFile($club->$field);
            $validated[$field] = null;
        }
        unset($validated['remove_' . $field]);

        if (!$request->hasFile($field)) {
            return;
        }

        $file = $request->file($field);
        $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $filename = $club->id . '-' . $field . '.' . $ext;

        // Удаляем старый файл (любое расширение с тем же именем)
        if ($club->$field) {
            $this->deleteClubDocFile($club->$field);
        }
        foreach (glob(public_path('club_docs/' . $club->id . '-' . $field . '.*')) ?: [] as $oldPath) {
            @unlink($oldPath);
        }

        if (!is_dir(public_path('club_docs'))) {
            @mkdir(public_path('club_docs'), 0775, true);
        }

        $file->move(public_path('club_docs'), $filename);
        $validated[$field] = '/club_docs/' . $filename;
    }

    /**
     * Удалить локальный файл-документ клуба (не трогаем внешние URL).
     */
    private function deleteClubDocFile(?string $path): void
    {
        if (!$path) return;
        if (preg_match('#^https?://#', $path)) return;
        $full = public_path(ltrim($path, '/'));
        if (is_file($full)) {
            @unlink($full);
        }
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

    /**
     * Удалить локальный файл обложки, если это не внешний URL.
     * Поддерживает оба формата записи в БД: «/covers/x.jpg» и «x.jpg».
     */
    private function deleteClubCoverFile(?string $cover): void
    {
        if (!$cover) return;
        if (preg_match('#^https?://#', $cover)) return;
        $relative = ltrim($cover, '/');
        // Убираем дублирующий префикс covers/, если он уже есть в строке
        if (str_starts_with($relative, 'covers/')) {
            $relative = substr($relative, strlen('covers/'));
        }
        $path = public_path('covers/' . $relative);
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