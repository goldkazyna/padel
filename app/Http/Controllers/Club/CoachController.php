<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubCoach;
use App\Models\CoachBlock;
use App\Models\CoachSchedule;
use App\Models\CoachScheduleOverride;
use App\Models\CourtBooking;
use App\Models\User;
use App\Support\PhoneVisibility;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CoachController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $clubCoaches = ClubCoach::where('club_id', $club->id)
            ->with(['user', 'schedules', 'rates'])
            ->get();

        return view('club.coaches.index', compact('clubCoaches', 'club'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'specialization' => 'nullable|string|max:255',
            'hourly_rate' => 'nullable|numeric|min:0',
            'rate_group' => 'nullable|numeric|min:0',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Тренер привязан только к одному клубу. Если этот пользователь уже
        // тренер где-либо (в этом или другом клубе) — запрещаем назначение.
        // Для работы с другим клубом нужно заводить отдельный аккаунт.
        if (ClubCoach::where('user_id', $user->id)->exists()) {
            return back()->with('error', 'Этот пользователь уже является тренером и привязан к клубу. Один аккаунт — один клуб: для работы с другим клубом заведите тренеру отдельный аккаунт.');
        }

        $user->update(['role' => 'coach']);

        ClubCoach::create([
            'club_id' => $club->id,
            'user_id' => $validated['user_id'],
            'specialization' => $validated['specialization'] ?? null,
            'hourly_rate' => $validated['hourly_rate'] ?? null,
            'rate_group' => $validated['rate_group'] ?? null,
        ]);

        return back()->with('success', 'Тренер добавлен!');
    }

    /**
     * Сменить логин-данные тренера: email и/или пароль. Телефон не меняется.
     */
    public function updateCredentials(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        // Тренер должен принадлежать этому клубу.
        ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
        ], [
            'email.email' => 'Некорректный email',
            'email.unique' => 'Этот email уже занят другим пользователем',
            'password.min' => 'Пароль должен быть не менее :min символов',
        ]);

        $data = [];
        if ($request->filled('email')) {
            $data['email'] = $validated['email'];
        }
        if ($request->filled('password')) {
            // password имеет cast 'hashed' — хешируется автоматически.
            $data['password'] = $validated['password'];
        }

        if (empty($data)) {
            return back()->with('error', 'Укажите новый email или пароль');
        }

        $user->update($data);

        return back()->with('success', 'Данные для входа тренера обновлены');
    }

    public function edit(User $user)
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->with(['user', 'rates'])
            ->firstOrFail();

        return view('club.coaches.edit', ['cc' => $coach]);
    }

    public function update(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'specialization' => 'nullable|string|max:255',
            'hourly_rate' => 'nullable|numeric|min:0',
            'rate_group' => 'nullable|numeric|min:0',
            'info' => 'nullable|string|max:5000',
            'rating' => 'nullable|string|max:50',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096|dimensions:min_width=500,min_height=500,max_width=2000,max_height=2000',
            'certificates' => 'nullable|array',
            'certificates.*' => 'image|mimes:jpeg,jpg,png,webp|max:8192',
            'remove_certificates' => 'nullable|array',
            'remove_certificates.*' => 'string',
            'rates' => 'nullable|array',
            'rates.*.hours' => 'required|integer|min:1|max:8',
            'rates.*.rate' => 'nullable|numeric|min:0',
        ], [
            'photo.dimensions' => 'Фото должно быть от 500×500 до 2000×2000 пикселей.',
            'photo.image' => 'Файл должен быть изображением.',
            'photo.max' => 'Размер фото не должен превышать 4 МБ.',
            'certificates.*.image' => 'Сертификат должен быть изображением.',
            'certificates.*.max' => 'Размер сертификата не должен превышать 8 МБ.',
        ]);

        $updateData = [
            'specialization' => $validated['specialization'] ?? null,
            'hourly_rate' => $validated['hourly_rate'] ?? null,
            'rate_group' => $validated['rate_group'] ?? null,
            'info' => $validated['info'] ?? null,
            'rating' => $validated['rating'] ?? null,
        ];

        // Загрузка фото тренера в public/coaches/<coach_id>.<ext>
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $ext = strtolower($file->getClientOriginalExtension());
            // Гарантируем наличие папки (на проде её могло не быть, и веб-сервер
            // не всегда может создать её сам — поэтому создаём явно).
            \Illuminate\Support\Facades\File::ensureDirectoryExists(public_path('coaches'), 0775);
            // Удаляем старые файлы фото этого тренера
            foreach (glob(public_path('coaches/' . $coach->id . '.*')) ?: [] as $oldPath) {
                @unlink($oldPath);
            }
            $filename = $coach->id . '.' . $ext;
            $file->move(public_path('coaches'), $filename);
            // Версионируем через ?v= чтобы браузер не показывал старый кеш
            $updateData['photo'] = '/coaches/' . $filename . '?v=' . time();
        }

        // Сертификаты (несколько фото): удаляем отмеченные, добавляем новые.
        $certificates = $coach->certificates ?? [];

        // Удаление выбранных сертификатов
        $toRemove = $request->input('remove_certificates', []);
        if (!empty($toRemove)) {
            foreach ($certificates as $i => $certPath) {
                if (in_array($certPath, $toRemove, true)) {
                    $clean = strtok(ltrim($certPath, '/'), '?'); // отбрасываем ?v=
                    @unlink(public_path($clean));
                    unset($certificates[$i]);
                }
            }
            $certificates = array_values($certificates);
        }

        // Добавление новых сертификатов
        if ($request->hasFile('certificates')) {
            $dir = public_path('coaches/certificates/' . $coach->id);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($dir, 0775);
            foreach ($request->file('certificates') as $certFile) {
                $ext = strtolower($certFile->getClientOriginalExtension());
                $name = uniqid('cert_') . '.' . $ext;
                $certFile->move($dir, $name);
                $certificates[] = '/coaches/certificates/' . $coach->id . '/' . $name;
            }
        }

        $updateData['certificates'] = !empty($certificates) ? array_values($certificates) : null;

        $coach->update($updateData);

        // Обновляем ставки по длительности
        $coach->rates()->delete();
        if (!empty($validated['rates'])) {
            foreach ($validated['rates'] as $r) {
                if ($r['rate'] > 0) {
                    \App\Models\CoachRate::create([
                        'club_coach_id' => $coach->id,
                        'hours' => $r['hours'],
                        'rate' => $r['rate'],
                    ]);
                }
            }
        }

        return redirect()->route('club.coaches.index')->with('success', 'Тренер обновлён!');
    }

    public function destroy(User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();
        $coach->delete();

        $hasOtherCoachProfiles = ClubCoach::where('user_id', $user->id)->exists();
        if (!$hasOtherCoachProfiles) {
            $user->update(['role' => 'player']);
        }

        return back()->with('success', 'Тренер удалён!');
    }

    public function schedule(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $clubCoach = ClubCoach::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->with(['schedules', 'overrides', 'user'])
            ->firstOrFail();

        $date = $request->get('date', now()->format('Y-m-d'));
        $dayOfWeek = Carbon::parse($date)->dayOfWeekIso;

        // Получаем рабочие часы тренера на этот день
        $override = $clubCoach->overrides()->whereDate('date', $date)->first();
        $weekSchedules = $clubCoach->schedules()->where('day_of_week', $dayOfWeek)->orderBy('start_time')->get();

        $timeSlots = [];

        // Собираем интервалы [start, end] в минутах (один или несколько)
        $intervals = [];

        if ($override && !$override->is_available && !$override->start_time) {
            // Полный выходной — слотов нет
        } elseif ($override && $override->is_available && $override->start_time) {
            $intervals[] = [
                Carbon::parse($override->start_time)->hour * 60 + Carbon::parse($override->start_time)->minute,
                Carbon::parse($override->end_time)->hour * 60 + Carbon::parse($override->end_time)->minute,
            ];
        } elseif ($weekSchedules->isNotEmpty()) {
            foreach ($weekSchedules as $ws) {
                $intervals[] = [
                    Carbon::parse($ws->start_time)->hour * 60 + Carbon::parse($ws->start_time)->minute,
                    Carbon::parse($ws->end_time)->hour * 60 + Carbon::parse($ws->end_time)->minute,
                ];
            }
        }

        foreach ($intervals as [$startMin, $endMin]) {
            for ($m = $startMin; $m + 60 <= $endMin; $m += 60) {
                $timeSlots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            }
        }

        // Бронирования кортов с этим тренером
        $bookings = CourtBooking::where('coach_id', $user->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->with('court')
            ->get();

        // Ручные блокировки
        $blocks = CoachBlock::where('club_coach_id', $clubCoach->id)
            ->whereDate('date', $date)
            ->get();

        // Строим расписание
        $schedule = [];
        foreach ($timeSlots as $time) {
            $slotStart = Carbon::parse($time)->hour * 60 + Carbon::parse($time)->minute;

            // Проверяем бронирование
            $booking = $bookings->first(function ($b) use ($slotStart) {
                $bStart = Carbon::parse($b->start_time)->hour * 60 + Carbon::parse($b->start_time)->minute;
                $bEnd = Carbon::parse($b->end_time)->hour * 60 + Carbon::parse($b->end_time)->minute;
                if ($bEnd <= $bStart) $bEnd += 1440;
                return $slotStart >= $bStart && $slotStart < $bEnd;
            });

            if ($booking) {
                $schedule[$time] = ['status' => 'booked', 'booking' => $booking];
                continue;
            }

            // Проверяем блокировку
            $block = $blocks->first(function ($bl) use ($slotStart) {
                $blStart = Carbon::parse($bl->start_time)->hour * 60 + Carbon::parse($bl->start_time)->minute;
                $blEnd = Carbon::parse($bl->end_time)->hour * 60 + Carbon::parse($bl->end_time)->minute;
                if ($blEnd <= $blStart) $blEnd += 1440;
                return $slotStart >= $blStart && $slotStart < $blEnd;
            });

            if ($block) {
                $schedule[$time] = ['status' => 'blocked', 'block' => $block];
                continue;
            }

            $schedule[$time] = ['status' => 'free'];
        }

        // Навигация по неделе
        $selectedDate = Carbon::parse($date);
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $weekDays[] = [
                'date' => $d->format('Y-m-d'),
                'dayName' => $d->locale('ru')->isoFormat('dd'),
                'dayNum' => $d->format('d'),
                'month' => $d->locale('ru')->isoFormat('MMM'),
                'isSelected' => $d->format('Y-m-d') === $date,
                'isToday' => $d->format('Y-m-d') === now()->format('Y-m-d'),
            ];
        }

        $dayNames = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];

        return view('club.coaches.schedule', compact(
            'clubCoach', 'date', 'schedule', 'timeSlots',
            'weekDays', 'prevWeek', 'nextWeek', 'dayNames'
        ));
    }

    public function blockSlot(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $clubCoach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'reason' => 'nullable|string|max:255',
        ]);

        CoachBlock::create([
            'club_coach_id' => $clubCoach->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', 'Слот заблокирован');
    }

    public function unblockSlot(CoachBlock $block)
    {
        $block->delete();
        return back()->with('success', 'Слот разблокирован');
    }

    public function updateSchedule(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'schedules' => 'nullable|array',
            'schedules.*.day_of_week' => 'required|integer|min:1|max:7',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i',
        ]);

        $coach->schedules()->delete();

        if (!empty($validated['schedules'])) {
            foreach ($validated['schedules'] as $schedule) {
                CoachSchedule::create([
                    'club_coach_id' => $coach->id,
                    'day_of_week' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                ]);
            }
        }

        return back()->with('success', 'Расписание обновлено!');
    }

    public function addOverride(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_available' => 'required|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        $coach->overrides()->where('date', $validated['date'])->delete();

        CoachScheduleOverride::create([
            'club_coach_id' => $coach->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'is_available' => $validated['is_available'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', 'Исключение добавлено!');
    }

    public function deleteOverride(CoachScheduleOverride $override)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $override->delete();

        return back()->with('success', 'Исключение удалено!');
    }

    public function searchUsers(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return response()->json([]);

        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) return response()->json([]);

        // Тренер привязан к одному клубу — исключаем всех, кто уже тренер в
        // любом клубе (их нельзя назначить повторно), а не только в этом.
        $existingCoachIds = ClubCoach::pluck('user_id');

        $users = User::where('name', 'like', "%{$q}%")
            ->whereNotIn('id', $existingCoachIds)
            ->limit(10)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->full_name,
                'phone' => PhoneVisibility::forExport($user->phone),
                'email' => $user->email,
            ]);

        return response()->json($users);
    }
}
