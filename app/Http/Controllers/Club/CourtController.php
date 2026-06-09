<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtPriceRange;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use App\Services\CourtScheduleService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CourtController extends Controller
{
    private CourtScheduleService $scheduleService;

    public function __construct(CourtScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    // === Расписание (главный экран) ===

    public function schedule(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)->active()->orderBy('sort_order')->orderBy('name')->get();
        if ($courts->isEmpty()) return redirect()->route('club.courts.index')->with('error', 'Нет активных кортов. Добавьте корт в настройках.');

        $date = $request->get('date', now()->format('Y-m-d'));

        $schedules = [];
        foreach ($courts as $court) {
            $schedules[$court->id] = $this->scheduleService->buildSchedule($court, $date);
        }

        $allTimes = collect();
        foreach ($courts as $court) {
            $slots = $this->scheduleService->generateTimeSlots($court);
            foreach ($slots as $slot) {
                $allTimes->push($slot['time']);
            }
        }
        $timeSlots = $allTimes->unique()->sort()->values();

        // Неделя: пн-вс для выбранной даты
        $selectedDate = Carbon::parse($date);
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        // Считаем слоты для каждого корта отдельно
        $courtSlotCounts = [];
        $totalSlots = 0;
        foreach ($courts as $court) {
            $count = count($this->scheduleService->generateTimeSlots($court));
            $courtSlotCounts[$court->id] = $count;
            $totalSlots += $count;
        }

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $dayStr = $d->format('Y-m-d');

            $occupancy = 0;
            if ($totalSlots > 0) {
                $occupiedSlots = 0;
                $bookings = \App\Models\CourtBooking::whereIn('court_id', $courts->pluck('id'))
                    ->whereDate('date', $dayStr)
                    ->where('status', 'confirmed')
                    ->get();
                foreach ($bookings as $b) {
                    $startMin = Carbon::parse($b->start_time)->hour * 60 + Carbon::parse($b->start_time)->minute;
                    $endMin = Carbon::parse($b->end_time)->hour * 60 + Carbon::parse($b->end_time)->minute;
                    if ($endMin <= $startMin) $endMin += 1440;
                    $court = $courts->firstWhere('id', $b->court_id);
                    $duration = $court ? $court->slot_duration : 60;
                    $occupiedSlots += ($endMin - $startMin) / $duration;
                }
                $blocks = \App\Models\CourtBlock::whereIn('court_id', $courts->pluck('id'))
                    ->whereDate('date', $dayStr)
                    ->get();
                foreach ($blocks as $bl) {
                    $startMin = Carbon::parse($bl->start_time)->hour * 60 + Carbon::parse($bl->start_time)->minute;
                    $endMin = Carbon::parse($bl->end_time)->hour * 60 + Carbon::parse($bl->end_time)->minute;
                    if ($endMin <= $startMin) $endMin += 1440;
                    $court = $courts->firstWhere('id', $bl->court_id);
                    $duration = $court ? $court->slot_duration : 60;
                    $occupiedSlots += ($endMin - $startMin) / $duration;
                }
                $occupancy = min(100, round($occupiedSlots / $totalSlots * 100));
            }

            $weekDays[] = [
                'date' => $dayStr,
                'dayName' => $d->locale('ru')->isoFormat('dd'),
                'dayNum' => $d->format('d'),
                'month' => $d->locale('ru')->isoFormat('MMM'),
                'isSelected' => $dayStr === $date,
                'isToday' => $dayStr === now()->format('Y-m-d'),
                'occupancy' => $occupancy,
            ];
        }

        $clubCoaches = $club->clubCoaches()->with(['user', 'schedules', 'overrides', 'blocks', 'rates'])->get();

        // Подготовить доступность тренеров по слотам для JS
        $coachAvailability = [];
        foreach ($clubCoaches as $cc) {
            foreach ($timeSlots as $time) {
                $endTime = Carbon::parse($time)->addHour()->format('H:i');
                $coachAvailability[$cc->user_id][$time] = $cc->isFreeAt($date, $time, $endTime);
            }
        }

        // Необработанные заявки
        $unprocessedBookings = CourtBooking::whereIn('court_id', $courts->pluck('id'))
            ->where('status', 'confirmed')
            ->where('is_processed', false)
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $activeGroups = $club->hasFeature('groups')
            ? \App\Models\ClubGroup::where('club_id', $club->id)
                ->where('status', 'active')
                ->with(['members' => function ($q) {
                    $q->where('status', 'active')->with('client:id,name');
                }, 'members.enrollments:id,group_member_id,sessions', 'members.attendance'])
                ->orderBy('name')
                ->get()
            : collect();

        // Карта court_booking_id => group_id — чтобы окно редактирования
        // групповой брони показывало участников и тренера группы.
        $bookingGroupIds = \App\Models\ClubGroupSession::whereNotNull('court_booking_id')
            ->where('status', '!=', 'cancelled')
            ->whereIn('group_id', \App\Models\ClubGroup::where('club_id', $club->id)->pluck('id'))
            ->pluck('group_id', 'court_booking_id');

        return view('club.courts.schedule', compact(
            'club', 'courts', 'schedules', 'timeSlots', 'date',
            'weekDays', 'prevWeek', 'nextWeek', 'clubCoaches', 'coachAvailability',
            'unprocessedBookings', 'activeGroups', 'bookingGroupIds'
        ));
    }

    // === Недельное расписание (вид «По дням») ===

    public function scheduleWeek(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)->active()->orderBy('sort_order')->orderBy('name')->get();
        if ($courts->isEmpty()) return redirect()->route('club.courts.index')->with('error', 'Нет активных кортов. Добавьте корт в настройках.');

        $date = $request->get('date', now()->format('Y-m-d'));
        $selectedDate = Carbon::parse($date);
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        // Сетка времени и сколько всего слотов в дне (один проход по кортам)
        $allTimes = collect();
        $courtSlotCounts = [];
        $courtTimeSlots = [];
        foreach ($courts as $court) {
            $slots = $this->scheduleService->generateTimeSlots($court);
            $courtTimeSlots[$court->id] = $slots;
            $courtSlotCounts[$court->id] = count($slots);
            foreach ($slots as $slot) {
                $allTimes->push($slot['time']);
            }
        }
        $timeSlots = $allTimes->unique()->sort()->values();
        $totalSlotsPerDay = array_sum($courtSlotCounts);

        // === BATCH FETCH (1 запрос на все бронирования недели + 1 на все блоки) ===
        $courtIds = $courts->pluck('id');
        $weekStartStr = $weekStart->format('Y-m-d');
        $weekEndStr = $weekEnd->format('Y-m-d');

        $allBookings = \App\Models\CourtBooking::whereIn('court_id', $courtIds)
            ->whereBetween('date', [$weekStartStr, $weekEndStr])
            ->where('status', 'confirmed')
            ->with('coach')
            ->get()
            ->groupBy(fn($b) => $b->court_id . '|' . Carbon::parse($b->date)->format('Y-m-d'));

        // Кол-во необработанных заявок по датам недели — для бейджей в шапке дня
        $unprocessedByDate = \App\Models\CourtBooking::whereIn('court_id', $courtIds)
            ->whereBetween('date', [$weekStartStr, $weekEndStr])
            ->where('status', 'confirmed')
            ->where('is_processed', false)
            ->selectRaw('date, COUNT(*) as cnt')
            ->groupBy('date')
            ->pluck('cnt', 'date')
            ->mapWithKeys(fn($v, $k) => [Carbon::parse($k)->format('Y-m-d') => (int) $v])
            ->all();

        $allBlocks = \App\Models\CourtBlock::whereIn('court_id', $courtIds)
            ->whereBetween('date', [$weekStartStr, $weekEndStr])
            ->get()
            ->groupBy(fn($b) => $b->court_id . '|' . Carbon::parse($b->date)->format('Y-m-d'));

        // Тренеры — один раз с eager-load всех нужных relations
        $clubCoaches = $club->clubCoaches()->with(['user', 'schedules', 'overrides', 'blocks', 'rates'])->get();
        $coachUserIds = $clubCoaches->pluck('user_id')->filter()->unique();
        $clubCoachIds = $clubCoaches->pluck('id');

        // Брони, где этот юзер — тренер (для проверки занятости)
        $coachBookings = $coachUserIds->isEmpty() ? collect() : \App\Models\CourtBooking::whereIn('coach_id', $coachUserIds)
            ->whereBetween('date', [$weekStartStr, $weekEndStr])
            ->where('status', 'confirmed')
            ->get()
            ->groupBy(fn($b) => $b->coach_id . '|' . Carbon::parse($b->date)->format('Y-m-d'));

        // Ручные блокировки тренеров
        $coachManualBlocks = $clubCoachIds->isEmpty() ? collect() : \App\Models\CoachBlock::whereIn('club_coach_id', $clubCoachIds)
            ->whereBetween('date', [$weekStartStr, $weekEndStr])
            ->get()
            ->groupBy(fn($b) => $b->club_coach_id . '|' . Carbon::parse($b->date)->format('Y-m-d'));

        // === Сборка данных по 7 дням ===
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $dayStr = $d->format('Y-m-d');

            $courtSchedules = [];
            $maxFreeSlotsByCell = [];
            $occupiedSlots = 0;

            foreach ($courts as $court) {
                $key = $court->id . '|' . $dayStr;
                $sched = $this->scheduleService->buildSchedule(
                    $court,
                    $dayStr,
                    $allBookings->get($key, collect()),
                    $allBlocks->get($key, collect())
                );
                $courtSchedules[$court->id] = $sched;

                // Inline подсчёт занятых + max consecutive free (без повторных запросов)
                $times = array_keys($sched);
                $cnt = count($times);
                foreach ($times as $idx => $time) {
                    $status = $sched[$time]['status'];
                    if ($status === 'booked' || $status === 'blocked') {
                        $occupiedSlots++;
                    } elseif ($status === 'free') {
                        $consecutive = 0;
                        for ($j = $idx; $j < $cnt; $j++) {
                            if ($sched[$times[$j]]['status'] === 'free') $consecutive++;
                            else break;
                        }
                        $maxFreeSlotsByCell[$court->id . '-' . $time] = $consecutive;
                    }
                }
            }

            $occupancy = $totalSlotsPerDay > 0
                ? min(100, round($occupiedSlots / $totalSlotsPerDay * 100))
                : 0;

            $weekDays[] = [
                'date' => $dayStr,
                'dayName' => $d->locale('ru')->isoFormat('dd'),
                'dayNumLabel' => $d->locale('ru')->isoFormat('D MMMM'),
                'isSelected' => $dayStr === $date,
                'isToday' => $dayStr === now()->format('Y-m-d'),
                'occupancy' => $occupancy,
                'schedules' => $courtSchedules,
                'maxFreeSlots' => $maxFreeSlotsByCell,
                'unprocessed' => $unprocessedByDate[$dayStr] ?? 0,
            ];
        }

        $weekRangeLabel = $weekStart->locale('ru')->isoFormat('D MMMM') . ' — ' . $weekEnd->locale('ru')->isoFormat('D MMMM YYYY');

        // Все необработанные заявки клуба (для панели)
        $unprocessedBookings = \App\Models\CourtBooking::whereIn('court_id', $courtIds)
            ->where('status', 'confirmed')
            ->where('is_processed', false)
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // freePrices = [court_id-time => price] (цены не зависят от даты)
        $freePrices = [];
        if (!empty($weekDays)) {
            foreach ($courts as $court) {
                $sched = $weekDays[0]['schedules'][$court->id] ?? [];
                foreach ($sched as $time => $slot) {
                    $freePrices[$court->id . '-' . $time] = $slot['price'];
                }
            }
        }

        // freeSlotsByDate = [date => [court_id-time => price]] — только свободные
        // слоты, нужны для расчёта максимума и цены при редактировании брони.
        $freeSlotsByDate = [];
        foreach ($weekDays as $wd) {
            $freeSlotsByDate[$wd['date']] = [];
            foreach ($courts as $court) {
                foreach (($wd['schedules'][$court->id] ?? []) as $time => $slot) {
                    if (($slot['status'] ?? '') === 'free') {
                        $freeSlotsByDate[$wd['date']][$court->id . '-' . $time] = $slot['price'];
                    }
                }
            }
        }

        // coachAvailability — собираем in-memory из подгруженных коллекций
        $coachAvailability = [];
        foreach ($weekDays as $wd) {
            foreach ($clubCoaches as $cc) {
                foreach ($timeSlots as $time) {
                    $endTime = Carbon::parse($time)->addHour()->format('H:i');
                    $coachAvailability[$wd['date']][$cc->user_id][$time] =
                        $this->isCoachFreeFromCache($cc, $wd['date'], $time, $endTime, $coachBookings, $coachManualBlocks);
                }
            }
        }

        return view('club.courts.schedule_week', compact(
            'club', 'courts', 'timeSlots', 'date', 'weekDays', 'prevWeek', 'nextWeek',
            'weekRangeLabel', 'freePrices', 'freeSlotsByDate', 'coachAvailability', 'clubCoaches',
            'unprocessedBookings'
        ));
    }

    /**
     * In-memory проверка свободен ли тренер (использует уже загруженные коллекции,
     * не дёргает БД повторно). Логика повторяет ClubCoach::isAvailableAt + isFreeAt.
     */
    private function isCoachFreeFromCache(
        \App\Models\ClubCoach $cc,
        string $date,
        string $startTime,
        string $endTime,
        $coachBookings,
        $coachManualBlocks
    ): bool {
        $startMin = Carbon::parse($startTime)->hour * 60 + Carbon::parse($startTime)->minute;
        $endMin = Carbon::parse($endTime)->hour * 60 + Carbon::parse($endTime)->minute;
        $dayOfWeek = Carbon::parse($date)->dayOfWeekIso;

        // 1) overrides на конкретную дату (используем уже подгруженную коллекцию)
        $override = $cc->overrides->firstWhere(fn($o) => Carbon::parse($o->date)->format('Y-m-d') === $date);
        if ($override) {
            if (!$override->is_available) {
                if (!$override->start_time) return false;
                $ovStart = Carbon::parse($override->start_time)->hour * 60 + Carbon::parse($override->start_time)->minute;
                $ovEnd = Carbon::parse($override->end_time)->hour * 60 + Carbon::parse($override->end_time)->minute;
                if ($startMin < $ovEnd && $endMin > $ovStart) return false;
            } else {
                if ($override->start_time && $override->end_time) {
                    $ovStart = Carbon::parse($override->start_time)->hour * 60 + Carbon::parse($override->start_time)->minute;
                    $ovEnd = Carbon::parse($override->end_time)->hour * 60 + Carbon::parse($override->end_time)->minute;
                    if (!($startMin >= $ovStart && $endMin <= $ovEnd)) return false;
                }
            }
        } else {
            // 2) обычное расписание дня недели — несколько интервалов (перерывы):
            // тренер доступен, если время попадает в ЛЮБОЙ интервал дня.
            $daySchedules = $cc->schedules->where('day_of_week', $dayOfWeek);
            if ($daySchedules->isEmpty()) return false;
            $inAnyInterval = false;
            foreach ($daySchedules as $schedule) {
                $schStart = Carbon::parse($schedule->start_time)->hour * 60 + Carbon::parse($schedule->start_time)->minute;
                $schEnd = Carbon::parse($schedule->end_time)->hour * 60 + Carbon::parse($schedule->end_time)->minute;
                if ($startMin >= $schStart && $endMin <= $schEnd) {
                    $inAnyInterval = true;
                    break;
                }
            }
            if (!$inAnyInterval) return false;
        }

        // 3) брони этого тренера (из подгруженной коллекции)
        $bookingKey = $cc->user_id . '|' . $date;
        $startFmt = Carbon::parse($startTime)->format('H:i:s');
        $endFmt = Carbon::parse($endTime)->format('H:i:s');
        foreach ($coachBookings->get($bookingKey, collect()) as $b) {
            if ($b->start_time < $endFmt && $b->end_time > $startFmt) return false;
        }

        // 4) ручные блокировки тренера (из подгруженной коллекции)
        $blockKey = $cc->id . '|' . $date;
        foreach ($coachManualBlocks->get($blockKey, collect()) as $bl) {
            $blStart = Carbon::parse($bl->start_time)->hour * 60 + Carbon::parse($bl->start_time)->minute;
            $blEnd = Carbon::parse($bl->end_time)->hour * 60 + Carbon::parse($bl->end_time)->minute;
            if ($startMin < $blEnd && $endMin > $blStart) return false;
        }

        return true;
    }

    // === CRUD кортов ===

    public function index()
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)
            ->with('priceRanges')
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('club.courts.index', compact('courts'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'open_time' => 'required|date_format:H:i',
            'close_time' => 'required|date_format:H:i',
            'price_ranges' => 'required|array|min:1',
            'price_ranges.*.time_from' => 'required|date_format:H:i',
            'price_ranges.*.time_to' => 'required|date_format:H:i',
            'price_ranges.*.price' => 'required|numeric|min:0',
        ]);

        $errors = $this->scheduleService->validatePriceRanges(
            $validated['price_ranges'], $validated['open_time'], $validated['close_time']
        );
        if (!empty($errors)) {
            return back()->with('error', implode('. ', $errors))->withInput();
        }

        $maxSort = Court::where('club_id', $club->id)->max('sort_order') ?? 0;

        $court = Court::create([
            'club_id' => $club->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
            'sort_order' => $maxSort + 1,
        ]);

        foreach ($validated['price_ranges'] as $range) {
            CourtPriceRange::create([
                'court_id' => $court->id,
                'time_from' => $range['time_from'],
                'time_to' => $range['time_to'],
                'price' => $range['price'],
            ]);
        }

        return back()->with('success', 'Корт добавлен!');
    }

    public function update(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'open_time' => 'required|date_format:H:i',
            'close_time' => 'required|date_format:H:i',
            'price_ranges' => 'required|array|min:1',
            'price_ranges.*.time_from' => 'required|date_format:H:i',
            'price_ranges.*.time_to' => 'required|date_format:H:i',
            'price_ranges.*.price' => 'required|numeric|min:0',
        ]);

        $errors = $this->scheduleService->validatePriceRanges(
            $validated['price_ranges'], $validated['open_time'], $validated['close_time']
        );
        if (!empty($errors)) {
            return back()->with('error', implode('. ', $errors))->withInput();
        }

        $court->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
        ]);

        $court->priceRanges()->delete();
        foreach ($validated['price_ranges'] as $range) {
            CourtPriceRange::create([
                'court_id' => $court->id,
                'time_from' => $range['time_from'],
                'time_to' => $range['time_to'],
                'price' => $range['price'],
            ]);
        }

        return back()->with('success', 'Корт обновлён!');
    }

    public function destroy(Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $hasBookings = CourtBooking::where('court_id', $court->id)
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', now()->format('Y-m-d'))
            ->exists();

        if ($hasBookings) {
            return back()->with('error', 'Нельзя удалить корт с будущими бронированиями');
        }

        $court->priceRanges()->delete();
        $court->blocks()->delete();
        $court->bookings()->delete();
        $court->delete();

        return back()->with('success', 'Корт удалён!');
    }

    public function toggleActive(Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $court->update(['is_active' => !$court->is_active]);

        return back()->with('success', $court->is_active ? 'Корт активирован!' : 'Корт деактивирован!');
    }

    // === Бронирование ===

    /**
     * Нормализует телефон к формату 7XXXXXXXXXX (KZ/RU),
     * удаляя всё кроме цифр и приводя ведущую 8 к 7.
     */
    private function normalizePhone(?string $raw): string
    {
        if (!$raw) return '';
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }

    /**
     * Ищет пользователя в таблице users по нормализованному телефону.
     * Возвращает null если нет или телефон пустой/невалидный.
     */
    private function findUserByPhone(?string $phone): ?\App\Models\User
    {
        $normalized = $this->normalizePhone($phone);
        if (strlen($normalized) !== 11) return null;
        return \App\Models\User::where('phone', $normalized)->first();
    }

    public function book(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $isGroupBooking = ($request->input('booking_type') === 'group');

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'slots' => 'required|integer|min:1|max:8',
            // Клиент/оплата не нужны для типа «Групповые» — оплата идёт через пакеты группы.
            'client_name' => 'required_unless:booking_type,group|nullable|string|max:255',
            'client_phone' => 'required_unless:booking_type,group|nullable|string|max:50',
            'client_note' => 'nullable|string|max:1000',
            'payment_method' => 'required_unless:booking_type,group|nullable|string|in:cash,card,kaspi,certificate,club_card,deposit,cashback',
            'is_paid' => 'required_unless:booking_type,group|nullable|boolean',
            'comment' => 'nullable|string|max:500',
            'booking_type' => 'nullable|in:soft,group,individual,tournament',
            'group_id' => 'nullable|exists:club_groups,id',
            'coach_id' => 'nullable|exists:users,id',
            'coach_paid' => 'nullable|boolean',
            'custom_price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'club_card_id' => 'nullable|integer',
            'repeat' => 'nullable|in:none,daily,every_2_days,weekly,biweekly',
            'repeat_until' => 'nullable|in:week,two_weeks,month',
        ], [
            'client_name.required_unless' => 'Укажите имя клиента',
            'client_phone.required_unless' => 'Укажите номер телефона',
            'payment_method.required_unless' => 'Выберите способ оплаты',
            'payment_method.in' => 'Выберите корректный способ оплаты',
            'is_paid.required_unless' => 'Выберите статус оплаты (оплачено / не оплачено)',
        ]);

        if ($isGroupBooking) {
            // Для групповой брони — автозаполнение, скидка/цена/клиент игнорируются.
            $group = !empty($validated['group_id'])
                ? \App\Models\ClubGroup::find($validated['group_id'])
                : null;
            $validated['client_name'] = $group ? ('Группа: ' . $group->name) : 'Группа';
            $validated['client_phone'] = null;
            $validated['payment_method'] = null;
            $validated['is_paid'] = false;
            $validated['discount'] = 0;
            $validated['custom_price'] = 0;
            $linkedUser = null;
        } else {
            $validated['client_phone'] = $this->normalizePhone($validated['client_phone']);
            $linkedUser = $this->findUserByPhone($validated['client_phone']);

            // Карточка клиента — источник истины. Если клиент уже есть по телефону,
            // в бронь идёт имя из карточки, а введённое игнорируется — это исключает
            // рассинхрон, когда в карточке одно имя, а в свежей брони другое.
            // Для новых клиентов (нет в справочнике) требуем имя+фамилию.
            $existingClient = \App\Models\ClubClient::where('club_id', $club->id)
                ->where('phone', $validated['client_phone'])
                ->first();
            if ($existingClient) {
                $validated['client_name'] = $existingClient->name;
            } else {
                $words = preg_split('/\s+/', trim($validated['client_name']));
                $words = array_values(array_filter($words, fn($w) => mb_strlen($w) > 0));
                if (count($words) < 2) {
                    return back()
                        ->withInput()
                        ->withErrors(['client_name' => 'Укажите имя и фамилию (например: «Денис Дудников»)']);
                }
            }
        }

        $startTime = $validated['start_time'];
        $totalMinutes = $validated['slots'] * $court->slot_duration;
        $endTime = Carbon::parse($startTime)->addMinutes($totalMinutes)->format('H:i');

        $repeat = $validated['repeat'] ?? 'none';
        $repeatUntil = $validated['repeat_until'] ?? 'month';

        // Список дат для бронирования (одна или несколько при повторе)
        $dates = $this->expandRepeatDates($validated['date'], $repeat, $repeatUntil);

        $autoPrice = $this->scheduleService->calculatePrice($court, $startTime, $endTime);
        $customPrice = $validated['custom_price'] ?? $autoPrice;
        $discount = $validated['discount'] ?? 0;

        // Клубная карта клиента: проверяем принадлежность клубу и актуальность.
        // Для скидочной карты скидка считается от цены (источник истины — карта).
        $clubCardId = null;
        if (!$isGroupBooking && !empty($validated['club_card_id'])) {
            $card = \App\Models\ClubCard::where('club_id', $club->id)
                ->with('type')->find($validated['club_card_id']);
            if ($card && $card->isActual()) {
                $clubCardId = $card->id;
                if ($card->type && $card->type->isDiscount()) {
                    $pct = (int) $card->type->discount_percent;
                    $discount = (int) round($customPrice * $pct / 100);
                }
            }
        }

        $price = max(0, $customPrice - $discount);

        $created = [];   // [['date' => Y-m-d, 'id' => X], ...]
        $skipped = [];   // ['Y-m-d' => 'причина']
        $firstBooking = null;

        foreach ($dates as $date) {
            // Проверка корта
            if (!$this->scheduleService->canBook($court, $date, $startTime, $endTime)) {
                $skipped[$date] = 'занято';
                continue;
            }

            // Проверка тренера. Для групповой брони пропускаем проверку графика —
            // тренер привязан к группе на уровне группы, а не к слот-графику.
            if (!empty($validated['coach_id'])) {
                $clubCoach = \App\Models\ClubCoach::where('club_id', $club->id)
                    ->where('user_id', $validated['coach_id'])
                    ->first();
                $skipSchedule = $isGroupBooking;
                if (!$clubCoach || !$clubCoach->isFreeAt($date, $startTime, $endTime, null, $skipSchedule)) {
                    $skipped[$date] = 'тренер занят на это время';
                    continue;
                }
            }

            $booking = CourtBooking::create([
                'court_id' => $court->id,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'client_name' => $validated['client_name'],
                'client_phone' => $validated['client_phone'],
                'booked_by' => $linkedUser?->id ?? auth()->id(),
                'price' => $price,
                'discount' => $discount,
                'payment_method' => $validated['payment_method'] ?? null,
                'is_paid' => $validated['is_paid'] ?? false,
                'comment' => $validated['comment'] ?? null,
                'booking_type' => $validated['booking_type'] ?? null,
                'coach_id' => $validated['coach_id'] ?? null,
                'coach_paid' => !empty($validated['coach_id']) ? $request->boolean('coach_paid') : null,
                'club_card_id' => $clubCardId,
            ]);
            $firstBooking ??= $booking;
            $created[] = ['date' => $date, 'id' => $booking->id];

            // Привязка к группе → автосоздание занятия в журнале (фича 'groups').
            if (($validated['booking_type'] ?? null) === 'group'
                && !empty($validated['group_id'])
                && $club->hasFeature('groups')
            ) {
                $group = \App\Models\ClubGroup::find($validated['group_id']);
                if ($group && $group->club_id === $club->id && $group->status === 'active') {
                    \App\Models\ClubGroupSession::create([
                        'group_id' => $group->id,
                        'court_id' => $court->id,
                        'court_booking_id' => $booking->id,
                        'date' => $date,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'coach_id' => $validated['coach_id'] ?? $group->coach_id,
                        'status' => 'planned',
                    ]);
                }
            }

            \App\Models\ActivityLog::log('created', 'CourtBooking', $booking->id, "Бронирование: {$validated['client_name']}, {$court->name}, {$date} {$startTime}–{$endTime}");
        }

        // Если ни одна дата не сохранилась — единственная — рассказываем причину
        if (empty($created)) {
            $first = array_key_first($skipped);
            return back()->with('error', count($skipped) === 1 && $first !== null
                ? "Не удалось забронировать ({$skipped[$first]}): {$first}"
                : 'Все выбранные даты заняты');
        }

        // Карточка клиента — источник истины. Если клиент уже есть — заметку
        // из формы игнорируем (правка только через /club/clients/{id}).
        // Если новый — заметка из формы попадает в новосозданную карточку.
        if ($validated['client_phone']) {
            $clientNote = $request->has('client_note') ? trim((string) $request->input('client_note')) : '';
            \App\Models\ClubClient::firstOrCreate(
                ['club_id' => $club->id, 'phone' => $validated['client_phone']],
                ['name' => $validated['client_name'], 'note' => $clientNote !== '' ? $clientNote : null]
            );
        }

        // Пуш юзеру приложения — по одному пушу на каждую созданную бронь.
        if ($linkedUser) {
            $linkedUserId = $linkedUser->id;
            $clubName = $club->name;
            $courtName = $court->name;
            $startTimeLabel = $startTime;
            $createdSnapshot = $created;

            app()->terminating(function () use ($linkedUserId, $createdSnapshot, $clubName, $courtName, $startTimeLabel) {
                try {
                    $user = \App\Models\User::find($linkedUserId);
                    if (!$user) return;

                    foreach ($createdSnapshot as $row) {
                        $bookingId = $row['id'];
                        $dateLabel = Carbon::parse($row['date'])->format('d.m.Y');

                        $title = 'Вам забронирован корт ✅';
                        $body = "{$clubName} · {$courtName}, {$dateLabel} в {$startTimeLabel}";

                        \App\Models\Notification::create([
                            'user_id' => $linkedUserId,
                            'title' => $title,
                            'body' => $body,
                            'type' => 'booking_created',
                            'category' => 'booking',
                            'data' => ['booking_id' => $bookingId],
                        ]);

                        app(\App\Services\FCMNotificationService::class)->sendToUser($user, $title, $body, [
                            'type' => 'booking_created',
                            'booking_id' => (string) $bookingId,
                        ]);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Push booking_created error: ' . $e->getMessage());
                }
            });
        }

        // Сообщение об успехе
        $createdCount = count($created);
        if ($createdCount === 1 && empty($skipped)) {
            $message = "Забронировано: {$validated['client_name']}, {$startTime}–{$endTime}, " . number_format($price, 0, '', ' ') . " ₸";
        } else {
            $parts = ["Создано {$createdCount} бронирований"];
            if (!empty($skipped)) {
                $skippedDates = array_map(fn($d) => Carbon::parse($d)->format('d.m'), array_keys($skipped));
                $parts[] = 'пропущено ' . count($skipped) . ' (' . implode(', ', $skippedDates) . ')';
            }
            $message = implode(', ', $parts);
        }

        return back()->with('success', $message);
    }

    /**
     * Разворачивает start-дату + паттерн повтора в массив дат (Y-m-d).
     * - none: [start]
     * - daily / every_2_days / weekly / biweekly + repeat_until (week/two_weeks/month)
     */
    private function expandRepeatDates(string $startDate, string $repeat, string $repeatUntil): array
    {
        if ($repeat === 'none') {
            return [$startDate];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = match ($repeatUntil) {
            // До конца текущей недели (включая воскресенье)
            'week' => $start->copy()
                ->addDays((7 - $start->dayOfWeekIso) % 7)
                ->endOfDay(),
            'two_weeks' => $start->copy()->addDays(13)->endOfDay(),
            default => $start->copy()->endOfMonth(),
        };

        $step = match ($repeat) {
            'daily' => 1,
            'every_2_days' => 2,
            'weekly' => 7,
            'biweekly' => 14,
            default => 0,
        };

        if ($step === 0) {
            return [$startDate];
        }

        $dates = [];
        $cursor = $start->copy();
        // Защита от зацикливания — максимум 60 итераций
        for ($i = 0; $i < 60 && !$cursor->greaterThan($end); $i++) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDays($step);
        }
        return $dates;
    }

    public function updateBooking(Request $request, CourtBooking $booking)
    {
        $club = $this->getClub();
        $court = $booking->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $wasUnprocessed = !$booking->is_processed;

        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'client_phone' => 'required|string|max:50',
            'client_note' => 'nullable|string|max:1000',
            'payment_method' => 'required|string|in:cash,card,kaspi,certificate,club_card,deposit,cashback',
            'is_paid' => 'required|boolean',
            'is_processed' => 'nullable|boolean',
            'comment' => 'nullable|string|max:500',
            'booking_type' => 'nullable|in:soft,group,individual,tournament',
            'coach_id' => 'nullable',
            'coach_paid' => 'nullable|boolean',
            'custom_price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'club_card_id' => 'nullable|integer',
            'slots' => 'nullable|integer|min:1|max:8',
        ], [
            'client_name.required' => 'Укажите имя клиента',
            'client_phone.required' => 'Укажите номер телефона',
            'payment_method.required' => 'Выберите способ оплаты',
            'payment_method.in' => 'Выберите корректный способ оплаты',
            'is_paid.required' => 'Выберите статус оплаты (оплачено / не оплачено)',
        ]);

        $validated['client_phone'] = $this->normalizePhone($validated['client_phone']);
        $linkedUser = $this->findUserByPhone($validated['client_phone']);

        // Карточка клиента — источник истины. Если клиент уже есть по телефону,
        // в бронь идёт имя из карточки. Для новых клиентов требуем имя+фамилию.
        $existingClient = \App\Models\ClubClient::where('club_id', $club->id)
            ->where('phone', $validated['client_phone'])
            ->first();
        if ($existingClient) {
            $validated['client_name'] = $existingClient->name;
        } else {
            $words = preg_split('/\s+/', trim($validated['client_name']));
            $words = array_values(array_filter($words, fn($w) => mb_strlen($w) > 0));
            if (count($words) < 2) {
                return back()
                    ->withInput()
                    ->withErrors(['client_name' => 'Укажите имя и фамилию (например: «Денис Дудников»)']);
            }
        }

        // Клубная карта клиента: проверяем принадлежность и актуальность.
        $clubCardId = null;
        $cardDiscountPct = null;
        if (!empty($validated['club_card_id'])) {
            $card = \App\Models\ClubCard::where('club_id', $club->id)
                ->with('type')->find($validated['club_card_id']);
            if ($card && $card->isActual()) {
                $clubCardId = $card->id;
                if ($card->type && $card->type->isDiscount()) {
                    $cardDiscountPct = (int) $card->type->discount_percent;
                }
            }
        }

        $updateData = [
            'client_name' => $validated['client_name'],
            'client_phone' => $validated['client_phone'],
            'payment_method' => $validated['payment_method'] ?? null,
            'is_paid' => $validated['is_paid'] ?? false,
            'is_processed' => $validated['is_processed'] ?? $booking->is_processed,
            'comment' => $validated['comment'] ?? null,
            'booking_type' => $validated['booking_type'] ?? null,
            'coach_id' => ($validated['coach_id'] ?? null) ?: null,
            'coach_paid' => !empty($validated['coach_id']) ? $request->boolean('coach_paid') : null,
            'club_card_id' => $clubCardId,
        ];
        // Перепривязка к пользователю приложения если телефон сменился
        if ($linkedUser) {
            $updateData['booked_by'] = $linkedUser->id;
        }

        // Изменение длительности — пересчёт end_time + проверка свободного слота
        if (!empty($validated['slots'])) {
            $newEndTime = Carbon::parse($booking->start_time)
                ->addMinutes($validated['slots'] * $court->slot_duration)
                ->format('H:i');
            $newStart = Carbon::parse($booking->start_time)->format('H:i');
            $bookingDate = $booking->date instanceof \Carbon\Carbon
                ? $booking->date->format('Y-m-d')
                : (string) $booking->date;

            if (!$this->scheduleService->canBook($court, $bookingDate, $newStart, $newEndTime, $booking->id)) {
                return back()->with('error', 'Новая длительность пересекается с другой бронью или блокировкой');
            }

            // Проверка тренера на расширенный интервал (если он привязан)
            $coachIdToCheck = $updateData['coach_id'] ?? null;
            if ($coachIdToCheck) {
                $clubCoach = \App\Models\ClubCoach::where('club_id', $club->id)
                    ->where('user_id', $coachIdToCheck)
                    ->first();
                if (!$clubCoach || !$clubCoach->isFreeAt($bookingDate, $newStart, $newEndTime, $booking->id)) {
                    return back()->with('error', 'Тренер недоступен на новый интервал');
                }
            }

            $updateData['end_time'] = $newEndTime;

            // Если кастомная цена не передана, пересчитаем по тарифу клуба
            if (!isset($validated['custom_price'])) {
                $autoPrice = $this->scheduleService->calculatePrice($court, $newStart, $newEndTime);
                $discount = $booking->discount ?? 0;
                $updateData['price'] = max(0, $autoPrice - $discount);
            }
        }

        if (isset($validated['custom_price'])) {
            $discount = $validated['discount'] ?? 0;
            // Скидочная карта — источник истины для размера скидки.
            if ($cardDiscountPct !== null) {
                $discount = (int) round($validated['custom_price'] * $cardDiscountPct / 100);
            }
            $updateData['price'] = max(0, $validated['custom_price'] - $discount);
            $updateData['discount'] = $discount;
        }

        $booking->update($updateData);

        // Если клиента ещё нет в справочнике (например, у старой брони
        // добавили телефон) — создаём карточку. Заметку из формы берём только
        // для новых клиентов, существующих не трогаем (карточка — источник истины).
        if ($validated['client_phone']) {
            $clientNote = $request->has('client_note') ? trim((string) $request->input('client_note')) : '';
            \App\Models\ClubClient::firstOrCreate(
                ['club_id' => $club->id, 'phone' => $validated['client_phone']],
                ['name' => $validated['client_name'], 'note' => $clientNote !== '' ? $clientNote : null]
            );
        }

        // Push + уведомление при обработке (was unprocessed → now processed) — отложено
        if ($wasUnprocessed && $booking->is_processed && $booking->booked_by) {
            $bookedUserId = $booking->booked_by;
            $bookingId = $booking->id;
            $courtName = $court->name;
            $dateLabel = $booking->date->format('d.m.Y');
            $timeLabel = Carbon::parse($booking->start_time)->format('H:i');

            app()->terminating(function () use ($bookedUserId, $bookingId, $courtName, $dateLabel, $timeLabel) {
                try {
                    $bookedUser = \App\Models\User::find($bookedUserId);
                    if (!$bookedUser) return;

                    $title = 'Бронирование подтверждено ✅';
                    $body = "{$courtName}, {$dateLabel} в {$timeLabel}";

                    \App\Models\Notification::create([
                        'user_id' => $bookedUser->id,
                        'title' => $title,
                        'body' => $body,
                        'type' => 'booking_confirmed',
                        'category' => 'booking',
                        'data' => ['booking_id' => $bookingId],
                    ]);

                    app(\App\Services\FCMNotificationService::class)->sendToUser($bookedUser, $title, $body, [
                        'type' => 'booking_confirmed',
                        'booking_id' => (string) $bookingId,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Push booking confirmed error: ' . $e->getMessage());
                }
            });
        }

        \App\Models\ActivityLog::log('updated', 'CourtBooking', $booking->id, "Редактирование брони: {$booking->client_name}, {$booking->court->name}", $booking->getChanges());

        // Возвращаемся туда, откуда пришли (дневной или недельный вид).
        // Если referer не подходит — fallback на дневной вид.
        $bookingDate = $booking->date instanceof \Carbon\Carbon ? $booking->date->format('Y-m-d') : $booking->date;
        $referer = (string) $request->headers->get('referer', '');
        if (str_contains($referer, '/courts/schedule/week')) {
            return redirect()->route('club.courts.scheduleWeek', ['date' => $bookingDate])->with('success', 'Бронирование обновлено');
        }
        return redirect()->route('club.courts.schedule', ['date' => $bookingDate])->with('success', 'Бронирование обновлено');
    }

    public function cancelBooking(CourtBooking $booking)
    {
        $club = $this->getClub();
        $court = $booking->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // Push + уведомление об отмене — отложено
        if ($booking->booked_by) {
            $bookedUserId = $booking->booked_by;
            $bookingId = $booking->id;
            $courtName = $court->name;
            $dateLabel = $booking->date->format('d.m.Y');
            $timeLabel = Carbon::parse($booking->start_time)->format('H:i');

            app()->terminating(function () use ($bookedUserId, $bookingId, $courtName, $dateLabel, $timeLabel) {
                try {
                    $bookedUser = \App\Models\User::find($bookedUserId);
                    if (!$bookedUser) return;

                    $title = 'Бронирование отменено ❌';
                    $body = "{$courtName}, {$dateLabel} в {$timeLabel}";

                    \App\Models\Notification::create([
                        'user_id' => $bookedUser->id,
                        'title' => $title,
                        'body' => $body,
                        'type' => 'booking_cancelled',
                        'category' => 'booking',
                        'data' => ['booking_id' => $bookingId],
                    ]);

                    app(\App\Services\FCMNotificationService::class)->sendToUser($bookedUser, $title, $body, [
                        'type' => 'booking_cancelled',
                        'booking_id' => (string) $bookingId,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Push booking cancelled error: ' . $e->getMessage());
                }
            });
        }

        \App\Models\ActivityLog::log('cancelled', 'CourtBooking', $booking->id, "Отмена брони: {$booking->client_name}, {$court->name}");

        return back()->with('success', 'Бронирование отменено');
    }

    // === Блокировка ===

    public function blockSlot(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'comment' => 'nullable|string|max:500',
        ]);

        if (!$this->scheduleService->canBook($court, $validated['date'], $validated['start_time'], $validated['end_time'])) {
            return back()->with('error', 'Нельзя заблокировать — есть бронирование на это время');
        }

        CourtBlock::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'comment' => $validated['comment'] ?? null,
        ]);

        \App\Models\ActivityLog::log('blocked', 'CourtBlock', null, "Блокировка: {$court->name}, {$validated['date']} {$validated['start_time']}–{$validated['end_time']}");

        return back()->with('success', 'Слот заблокирован');
    }

    public function unblock(CourtBlock $block)
    {
        $club = $this->getClub();
        $court = $block->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $block->delete();

        \App\Models\ActivityLog::log('unblocked', 'CourtBlock', $block->id, "Разблокировка: {$court->name}");

        return back()->with('success', 'Слот разблокирован');
    }

    public function updateBlock(Request $request, CourtBlock $block)
    {
        $club = $this->getClub();
        $court = $block->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $block->update([
            'comment' => $validated['comment'] ?? null,
        ]);

        return back()->with('success', 'Блокировка обновлена');
    }
}
