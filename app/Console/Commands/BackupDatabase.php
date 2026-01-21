<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--name= : Имя файла бэкапа}';
    
    protected $description = 'Создать бэкап базы данных';

    public function handle()
    {
        $this->info('🔄 Создаём бэкап базы данных...');

        // Получаем настройки из .env
        $host = config('database.connections.mysql.host', env('DB_HOST', '127.0.0.1'));
        $database = config('database.connections.mysql.database', env('DB_DATABASE'));
        $username = config('database.connections.mysql.username', env('DB_USERNAME'));
        $password = config('database.connections.mysql.password', env('DB_PASSWORD'));
        $port = config('database.connections.mysql.port', env('DB_PORT', '3306'));

        // Создаём папку для бэкапов
        $backupPath = storage_path('app/backups');
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        // Имя файла
        $fileName = $this->option('name') 
            ? $this->option('name') . '.sql'
            : 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        $filePath = $backupPath . '/' . $fileName;

        // Команда mysqldump
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($filePath)
        );

        // Выполняем
        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($filePath) && filesize($filePath) > 0) {
            $size = $this->formatBytes(filesize($filePath));
            $this->info("✅ Бэкап создан: {$fileName}");
            $this->info("📁 Путь: {$filePath}");
            $this->info("📊 Размер: {$size}");
            
            // Показываем последние 5 бэкапов
            $this->showRecentBackups($backupPath);
            
            return 0;
        } else {
            $this->error('❌ Ошибка создания бэкапа');
            if (!empty($output)) {
                $this->error(implode("\n", $output));
            }
            return 1;
        }
    }

    protected function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    protected function showRecentBackups($path): void
    {
        $files = glob($path . '/*.sql');
        if (empty($files)) return;

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $recent = array_slice($files, 0, 5);

        $this->newLine();
        $this->info('📋 Последние бэкапы:');
        
        foreach ($recent as $file) {
            $name = basename($file);
            $size = $this->formatBytes(filesize($file));
            $date = date('d.m.Y H:i', filemtime($file));
            $this->line("   • {$name} ({$size}) - {$date}");
        }
    }
}