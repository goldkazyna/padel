<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only диагностика: находит телефоны, привязанные более чем к одному
 * аккаунту users. Ничего не меняет — только выводит список для анализа.
 */
class FindDuplicatePhones extends Command
{
    protected $signature = 'users:duplicate-phones';

    protected $description = 'Показать аккаунты с одинаковым номером телефона (дубли)';

    public function handle(): int
    {
        // Группируем по нормализованному телефону (только цифры), игнорируя пустые.
        $dupPhones = DB::table('users')
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->select('phone', DB::raw('COUNT(*) as cnt'))
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'phone');

        if ($dupPhones->isEmpty()) {
            $this->info('✅ Дублей телефонов не найдено.');
            return 0;
        }

        $totalPhones = $dupPhones->count();
        $totalAccounts = $dupPhones->sum();

        $this->warn("⚠ Найдено {$totalPhones} телефон(ов) с дублями (всего {$totalAccounts} аккаунтов):");
        $this->newLine();

        foreach ($dupPhones as $phone => $cnt) {
            $users = User::where('phone', $phone)
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'role', 'telegram_id', 'rating', 'created_at']);

            $this->line("📞 <fg=yellow>{$phone}</> — {$cnt} аккаунта:");

            $rows = $users->map(fn ($u) => [
                $u->id,
                mb_strimwidth((string) $u->name, 0, 22, '…'),
                mb_strimwidth((string) $u->email, 0, 32, '…'),
                $u->role,
                $u->telegram_id ?: '—',
                $u->rating,
                optional($u->created_at)->format('Y-m-d') ?: '—',
            ])->all();

            $this->table(
                ['id', 'name', 'email', 'role', 'telegram_id', 'rating', 'created'],
                $rows
            );
        }

        $this->newLine();
        $this->info("Итого: {$totalPhones} номеров, {$totalAccounts} аккаунтов.");

        return 0;
    }
}
