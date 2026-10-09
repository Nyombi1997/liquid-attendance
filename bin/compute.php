<?php
declare(strict_types=1);

/*
 * Recalcul des journées de présence.
 *   php bin/compute.php --days=7                 (derniers jours, utilisé par le worker)
 *   php bin/compute.php --from=2026-09-01 --to=2026-09-30
 *   php bin/compute.php --employee-device-id=42 --from=2026-10-08 --to=2026-10-09
 *       (seulement ces agents, numéros de pointeuse séparés par des virgules ;
 *        utilisé par le worker après chaque badge reçu en temps réel)
 * Au tout premier passage (table vide), l'historique est calculé depuis le
 * réglage history_start_date.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Modules\Attendance\Services\AttendanceComputer;

$opts = getopt('', ['days::', 'from::', 'to::', 'employee-device-id::']);
$today = date('Y-m-d');

$employeeIds = null;
if (isset($opts['employee-device-id'])) {
    $deviceIds = array_values(array_filter(array_map('trim', explode(',', (string) $opts['employee-device-id'])), 'strlen'));
    if (!$deviceIds || !isset($opts['from'])) {
        fwrite(STDERR, "Usage : --employee-device-id=<numéro>[,<numéro>…] --from=AAAA-MM-JJ [--to=AAAA-MM-JJ]\n");
        exit(2);
    }
    $employeeIds = array_map('intval', array_column(Database::all(
        'SELECT id FROM employees WHERE device_user_id IN (' . implode(',', array_fill(0, count($deviceIds), '?')) . ')',
        $deviceIds
    ), 'id'));
    if (!$employeeIds) {
        echo "[compute] aucun employé pour le numéro " . implode(',', $deviceIds) . "\n";
        exit(0);
    }
}

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
$n = (new AttendanceComputer())->run($from, $to, $employeeIds);
printf("[compute %s] %d journées calculées du %s au %s%s en %.1f s\n", date('Y-m-d H:i:s'), $n, $from, $to,
    $employeeIds ? ' (' . count($employeeIds) . ' agent(s))' : '', microtime(true) - $start);
