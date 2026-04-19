<?php
// Временный скрипт для сброса OPcache. УДАЛИТЬ после использования.
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache сброшен OK\n";
} else {
    echo "OPcache не установлен или отключён\n";
}
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "APCu cache сброшен OK\n";
}
echo "\nВРЕМЯ: " . date('Y-m-d H:i:s') . "\n";
echo "УДАЛИ ФАЙЛ: rm public/reset-opcache.php\n";
