<?php
declare(strict_types=1);

/*
 * Recalcul des journées de présence.
 *   php bin/compute.php --days=7                 (derniers jours, utilisé par le worker)
 *   php bin/compute.php --from=2026-09-01 --to=2026-09-30
 * Au tout premier passage (table vide), l'historique est calculé depuis le
 * réglage history_start_date.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Modules\Attendance\Services\AttendanceComputer;

$opts = getopt('', ['days::', 'from::', 'to::']);
$today = date('Y-m-d');

if (isset($opts['from'])) {
    $from = $opts['from'];
    $to = $opts['to'] ?? $today;
} else {
    $days = max(1, (int) ($opts['days'] ?? 7));
    $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $to = $today;
    if ((int) Database::value('SELECT COUNT(*) FROM attendance_days') === 0) {
        $from = Database::value("SELECT value FROM settings WHERE name = 'history_start_date'") ?: $from;
        echo "[compute] premier calcul : historique depuis $from\n";
    }
}

$start = microtime(true);
$n = (new AttendanceComputer())->run($from, $to);
printf("[compute %s] %d journées calculées du %s au %s en %.1f s\n", date('Y-m-d H:i:s'), $n, $from, $to, microtime(true) - $start);
