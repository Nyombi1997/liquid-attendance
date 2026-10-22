<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Controller;
use App\Core\Database;

/**
 * Administration > État du service : battement de cœur du worker, pointeuses,
 * taille de la base, dernière sauvegarde. Les valeurs du worker sont écrites
 * par worker/sync.py dans la table settings (worker_*, compute_last_at, backup_*).
 */
final class ServiceController extends Controller
{
    /** Le worker écrit son battement de cœur toutes les 60 s. */
    private const WORKER_LATE = 180;
    private const WORKER_DOWN = 600;
    /** Sauvegarde quotidienne : en retard au-delà de 26 h. */
    private const BACKUP_LATE = 26 * 3600;

    public function index(): void
    {
        $s = [];
        foreach (Database::all(
            "SELECT name, value FROM settings
              WHERE name IN ('worker_heartbeat_at', 'worker_started_at', 'compute_last_at',
                             'backup_last_at', 'backup_last_file', 'backup_last_size', 'backup_last_error')"
        ) as $row) {
            $s[$row['name']] = $row['value'];
        }

        $beat = isset($s['worker_heartbeat_at']) ? time() - strtotime($s['worker_heartbeat_at']) : null;
        $worker = match (true) {
            $beat === null              => 'none',
            $beat <= self::WORKER_LATE  => 'ok',
            $beat <= self::WORKER_DOWN  => 'late',
            default                     => 'down',
        };

        $backupAge = isset($s['backup_last_at']) ? time() - strtotime($s['backup_last_at']) : null;
        $backup = match (true) {
            ($s['backup_last_error'] ?? '') !== '' => 'error',
            $backupAge === null                    => 'none',
            $backupAge <= self::BACKUP_LATE        => 'ok',
            default                                => 'late',
        };

        $devices = Database::all(
            'SELECT d.id, d.name, d.ip, d.port, d.active, d.usage_type, d.direction, d.model, d.last_sync_at, d.last_sync_ok,
                    d.last_error, d.last_event_at, d.live_since, d.clock_offset_sec, o.name office,
                    (SELECT MAX(punched_at) FROM punches p WHERE p.device_id = d.id) last_punch
               FROM devices d JOIN offices o ON o.id = d.office_id
              ORDER BY d.active DESC, d.usage_type DESC, o.name, d.direction'
        );

        $tables = Database::all(
            'SELECT table_name name, table_rows row_estimate, data_length + index_length bytes
               FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY bytes DESC'
        );

        $this->render('Platform:service', [
            'title'     => 'État du service',
            'settings'  => $s,
            'worker'    => $worker,
            'backup'    => $backup,
            'devices'   => $devices,
            'listening' => count(array_filter($devices, static fn(array $d) => $d['active'] && $d['live_since'])),
            'active'    => count(array_filter($devices, static fn(array $d) => (bool) $d['active'])),
            'dbSize'    => array_sum(array_map(static fn(array $t) => (int) $t['bytes'], $tables)),
            'tables'    => array_slice($tables, 0, 8),
        ]);
    }
}
