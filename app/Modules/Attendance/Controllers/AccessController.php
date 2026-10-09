<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Controller;
use App\Core\Database;

/** Historique des entrées dans les salles (NOC, IT Room). */
final class AccessController extends Controller
{
    public function index(): void
    {
        $date = (string) $this->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $device = (int) $this->input('device', 0);
        $q = mb_substr((string) $this->input('q', ''), 0, 60);

        $where = ["d.usage_type = 'acces'", 'p.punched_at BETWEEN ? AND ?'];
        $params = [$date . ' 00:00:00', $date . ' 23:59:59'];
        if ($device) {
            $where[] = 'd.id = ?';
            $params[] = $device;
        }
        if ($q !== '') {
            $where[] = '(e.name LIKE ? OR p.device_user_id = ?)';
            array_push($params, "%$q%", $q);
        }

        $rows = Database::all(
            'SELECT p.punched_at, p.device_user_id, d.name device, o.name office, e.id employee_id,
                    COALESCE(e.name, CONCAT(\'Agent \', p.device_user_id)) employee
               FROM punches p JOIN devices d ON d.id = p.device_id JOIN offices o ON o.id = d.office_id
               LEFT JOIN employees e ON e.device_user_id = p.device_user_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY p.punched_at DESC LIMIT 1000',
            $params
        );

        $this->render('Attendance:access', [
            'title'   => 'Accès aux salles',
            'rows'    => $rows,
            'date'    => $date,
            'device'  => $device,
            'q'       => $q,
            'devices' => Database::all("SELECT id, name FROM devices WHERE usage_type = 'acces' ORDER BY name"),
        ]);
    }
}
