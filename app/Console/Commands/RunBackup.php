<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--keep=7 : Сколько последних копий хранить}';
    protected $description = 'Бэкап БД (gzip) + загруженных файлов (tar.gz): локально с ротацией + выгрузка в облако';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $stamp = now()->format('Y-m-d_H-i-s');

        $localDir = storage_path('app/backups');
        if (!is_dir($localDir)) {
            mkdir($localDir, 0750, true);
        }

        $dbFile = $localDir . '/db-' . $stamp . '.sql.gz';
        $filesFile = $localDir . '/files-' . $stamp . '.tar.gz';

        $ok = true;
        $created = [];

        // 1) Дамп базы данных.
        if ($this->dumpDatabase($dbFile)) {
            $created[] = $dbFile;
            $this->info('✅ БД: ' . basename($dbFile) . ' (' . $this->human(filesize($dbFile)) . ')');
        } else {
            $ok = false;
            $this->error('❌ Не удалось создать дамп БД');
        }

        // 2) Архив загруженных файлов (storage/app/public).
        if ($this->archiveFiles($filesFile)) {
            $created[] = $filesFile;
            $this->info('✅ Файлы: ' . basename($filesFile) . ' (' . $this->human(filesize($filesFile)) . ')');
        } else {
            $this->warn('⚠ Архив файлов не создан (возможно, нет загрузок) — пропускаем');
        }

        // 3) Выгрузка в облако (если настроен диск backup).
        $this->uploadToCloud($created, $keep);

        // 4) Ротация локальных копий.
        $this->rotateLocal($localDir, 'db-', $keep);
        $this->rotateLocal($localDir, 'files-', $keep);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** mysqldump → gzip. Пароль передаём через защищённый временный конфиг (не виден в ps). */
    private function dumpDatabase(string $gzPath): bool
    {
        $conn = config('database.default', 'mysql');
        $c = config("database.connections.$conn");
        $host = $c['host'] ?? '127.0.0.1';
        $port = $c['port'] ?? '3306';
        $user = $c['username'] ?? 'root';
        $pass = $c['password'] ?? '';
        $db = $c['database'] ?? '';

        if ($db === '') {
            $this->error('DB_DATABASE не задан');
            return false;
        }

        $cnf = tempnam(sys_get_temp_dir(), 'mybak');
        file_put_contents($cnf, "[client]\nhost=\"{$host}\"\nport=\"{$port}\"\nuser=\"{$user}\"\npassword=\"{$pass}\"\n");
        @chmod($cnf, 0600);

        $sqlTmp = $gzPath . '.tmp.sql';
        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers --events --no-tablespaces %s > %s 2>&1',
            escapeshellarg($cnf),
            escapeshellarg($db),
            escapeshellarg($sqlTmp)
        );
        exec($cmd, $out, $code);
        @unlink($cnf);

        if ($code !== 0 || !file_exists($sqlTmp) || filesize($sqlTmp) === 0) {
            @unlink($sqlTmp);
            if (!empty($out)) $this->error(implode("\n", array_slice($out, 0, 5)));
            return false;
        }

        // Сжимаем дамп.
        exec(sprintf('gzip -f %s', escapeshellarg($sqlTmp)), $o2, $c2);
        if ($c2 !== 0 || !file_exists($sqlTmp . '.gz')) {
            @unlink($sqlTmp);
            return false;
        }
        @rename($sqlTmp . '.gz', $gzPath);

        return file_exists($gzPath) && filesize($gzPath) > 0;
    }

    /** tar.gz папки storage/app/public. */
    private function archiveFiles(string $tarPath): bool
    {
        $src = storage_path('app/public');
        if (!is_dir($src)) return false;

        // Пустая папка — нечего архивировать.
        $hasContent = false;
        foreach (scandir($src) as $e) {
            if ($e !== '.' && $e !== '..') { $hasContent = true; break; }
        }
        if (!$hasContent) return false;

        $cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($tarPath), escapeshellarg($src));
        exec($cmd, $out, $code);

        return $code === 0 && file_exists($tarPath) && filesize($tarPath) > 0;
    }

    /** Выгрузка в облачный диск backup (S3-совместимый) + ротация там же. */
    private function uploadToCloud(array $files, int $keep): void
    {
        if (!config('filesystems.disks.backup.key') || !config('filesystems.disks.backup.bucket')) {
            $this->line('ℹ Облако не настроено (BACKUP_KEY/BACKUP_BUCKET пусты) — копии только на сервере.');
            return;
        }

        try {
            $disk = Storage::disk('backup');
            $remoteDir = trim(config('filesystems.disks.backup.path_prefix', 'padel-backups'), '/');

            foreach ($files as $local) {
                $remote = $remoteDir . '/' . basename($local);
                $stream = fopen($local, 'r');
                $disk->writeStream($remote, $stream);
                if (is_resource($stream)) fclose($stream);
                $this->info('☁ Выгружено в облако: ' . basename($local));
            }

            // Ротация в облаке (по именам: db-* и files-*).
            foreach (['db-', 'files-'] as $prefix) {
                $all = collect($disk->files($remoteDir))
                    ->filter(fn($p) => str_starts_with(basename($p), $prefix))
                    ->sortDesc()      // имена с датой сортируются по времени
                    ->values();
                foreach ($all->slice($keep) as $old) {
                    $disk->delete($old);
                }
            }
        } catch (\Throwable $e) {
            $this->error('☁ Ошибка выгрузки в облако: ' . $e->getMessage());
        }
    }

    /** Оставить $keep последних копий с префиксом, остальное удалить. */
    private function rotateLocal(string $dir, string $prefix, int $keep): void
    {
        $files = glob($dir . '/' . $prefix . '*');
        if (!$files || count($files) <= $keep) return;
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }

    private function human($bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, 2) . ' ' . $u[$i];
    }
}
