<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\Request;

/**
 * Жалобы игроков друг на друга.
 *
 * Появились вместе с личной перепиской: писать можно любому, значит должен
 * быть человек, который читает жалобы. Без этого экрана жалоба просто ложилась
 * бы в таблицу, и открытая переписка держалась бы на честном слове.
 */
class ReportController extends Controller
{
    /** Сколько последних сообщений показываем для понимания сути. */
    private const CONTEXT_MESSAGES = 20;

    public function index(Request $request)
    {
        $status = $request->query('status', ContentReport::STATUS_NEW);

        $reports = ContentReport::when(
            $status !== 'all',
            fn ($q) => $q->where('status', $status)
        )
            ->with('reporter:id,name,phone')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $targets = User::whereIn('id', $reports->pluck('reportable_id'))
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        return view('admin.reports.index', [
            'reports' => $reports,
            'targets' => $targets,
            'status' => $status,
            'counts' => [
                'new' => ContentReport::where('status', ContentReport::STATUS_NEW)->count(),
                'reviewed' => ContentReport::where('status', ContentReport::STATUS_REVIEWED)->count(),
            ],
        ]);
    }

    /**
     * Жалоба целиком: кто, на кого и что было в переписке.
     *
     * Переписку показываем прямо здесь: без неё жалоба «оскорбления» — это
     * слово против слова, и решение принимать не на чем.
     */
    public function show(ContentReport $report)
    {
        $target = User::find($report->reportable_id);

        $messages = collect();
        if ($target) {
            $conversation = Conversation::where(function ($q) use ($report, $target) {
                $q->where('user_one_id', min($report->reporter_id, $target->id))
                    ->where('user_two_id', max($report->reporter_id, $target->id));
            })->first();

            if ($conversation) {
                $messages = ConversationMessage::where('conversation_id', $conversation->id)
                    ->with('user:id,name')
                    ->orderByDesc('id')
                    ->limit(self::CONTEXT_MESSAGES)
                    ->get()
                    ->sortBy('id')
                    ->values();
            }
        }

        return view('admin.reports.show', [
            'report' => $report->load('reporter:id,name,phone'),
            'target' => $target,
            'messages' => $messages,
            'blockedByReporter' => $target
                ? UserBlock::where('user_id', $report->reporter_id)
                    ->where('blocked_user_id', $target->id)
                    ->exists()
                : false,
        ]);
    }

    /** Жалоба разобрана. */
    public function review(ContentReport $report)
    {
        $report->update(['status' => ContentReport::STATUS_REVIEWED]);

        return redirect()
            ->route('admin.reports.index')
            ->with('success', 'Жалоба помечена разобранной');
    }
}
