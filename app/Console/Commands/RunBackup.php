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

    /** Выгрузка в облако + ротация. По умолчанию rclone (надёжнее с Yandex/MinIO), иначе S3 SDK. */
    private function uploadToCloud(array $files, int $keep): void
    {
        if (!config('filesystems.disks.backup.key') || !config('filesystems.disks.backup.bucket')) {
            $this->line('ℹ Облако не настроено (BACKUP_KEY/BACKUP_BUCKET пусты) — копии только на сервере.');
            return;
        }

        // rclone — основной путь (обходит проблемы подписи aws-sdk с S3-совместимыми).
        if (env('BACKUP_USE_RCLONE', true)) {
            $this->uploadViaRclone($files, $keep);
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

    /** Выгрузка через rclone (креды — через окружение, не видны в ps). */
    private function uploadViaRclone(array $files, int $keep): void
    {
        $c = config('filesystems.disks.backup');
        $bucket = $c['bucket'];
        $prefix = trim($c['path_prefix'] ?? 'padel-backups', '/');
        $base = $bucket . '/' . $prefix;

        // rclone установлен?
        exec('command -v rclone 2>/dev/null', $w, $wc);
        if ($wc !== 0 || empty($w)) {
            $this->error('☁ rclone не установлен. Установите: apt-get install -y rclone (или curl https://rclone.org/install.sh | sudo bash)');
            return;
        }

        // Конфиг S3-бэкенда rclone через переменные окружения (не в argv).
        putenv('RCLONE_S3_PROVIDER=Other');
        putenv('RCLONE_S3_ENV_AUTH=false');
        putenv('RCLONE_S3_ACCESS_KEY_ID=' . $c['key']);
        putenv('RCLONE_S3_SECRET_ACCESS_KEY=' . $c['secret']);
        putenv('RCLONE_S3_REGION=' . ($c['region'] ?? 'ru-central1'));
        putenv('RCLONE_S3_ENDPOINT=' . ($c['endpoint'] ?? ''));

        foreach ($files as $local) {
            $dest = ':s3:' . $base . '/' . basename($local);
            $cmd = sprintf(
                'rclone copyto %s %s --s3-no-check-bucket --low-level-retries 3 2>&1',
                escapeshellarg($local),
                escapeshellarg($dest)
            );
            exec($cmd, $out, $code);
            if ($code === 0) {
                $this->info('☁ Выгружено в облако: ' . basename($local));
            } else {
                $this->error('☁ Ошибка rclone (' . basename($local) . '): ' . implode(' ', array_slice($out, -4)));
            }
            $out = [];
        }

        // Ротация в облаке: оставить $keep последних по каждому префиксу.
        exec(sprintf('rclone lsf %s 2>/dev/null', escapeshellarg(':s3:' . $base . '/')), $list, $lc);
        if ($lc === 0) {
            foreach (['db-', 'files-'] as $pfx) {
                $names = collect($list)
                    ->map(fn($n) => rtrim($n, '/'))
                    ->filter(fn($n) => str_starts_with($n, $pfx))
                    ->sortDesc()
                    ->values();
                foreach ($names->slice($keep) as $old) {
                    exec(sprintf('rclone deletefile %s 2>/dev/null', escapeshellarg(':s3:' . $base . '/' . $old)));
                }
            }
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
