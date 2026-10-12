<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Platform\Services\Departments;

/**
 * Journal des pointages bruts (toutes pointeuses), du plus récent au plus ancien.
 * Manager : agents de son département ; RH et managers : journée d'aujourd'hui uniquement.
 */
final class PunchController extends Controller
{
    private const KINDS = ['entree', 'sortie', 'acces'];

    public function index(): void
    {
        $date = (string) $this->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !Auth::can('view')) {
            $date = date('Y-m-d');
        }
        $device = (int) $this->input('device', 0);
        $kind = in_array($this->input('kind'), self::KINDS, true) ? (string) $this->input('kind') : '';
        $q = mb_substr((string) $this->input('q', ''), 0, 60);
        $scope = Auth::departmentScope();

        $where = ['p.punched_at BETWEEN ? AND ?', '(e.id IS NULL OR e.active = 1)'];
        $params = [$date . ' 00:00:00', $date . ' 23:59:59'];
        if ($device) {
            $where[] = 'd.id = ?';
            $params[] = $device;
        }
        if ($kind === 'acces') {
            $where[] = "d.usage_type = 'acces'";
        } elseif ($kind) {
            $where[] = "d.usage_type <> 'acces' AND d.direction = ?";
            $params[] = $kind;
        }
        if ($q !== '') {
            $where[] = '(e.name LIKE ? OR p.device_user_id = ?)';
            array_push($params, "%$q%", $q);
        }

        $rows = Database::all(
            "SELECT p.id, p.punched_at, p.device_user_id, d.name device, d.direction, d.usage_type, e.id employee_id,
                    COALESCE(e.name, CONCAT('Agent ', p.device_user_id)) employee, e.photo_updated_at
               FROM punches p JOIN devices d ON d.id = p.device_id
               LEFT JOIN employees e ON e.device_user_id = p.device_user_id
              WHERE " . implode(' AND ', $where) . Departments::sql($scope) . '
              ORDER BY p.punched_at DESC, p.id DESC LIMIT 2000',
            $params
        );

        $this->render('Attendance:punches', [
            'title'   => 'Pointages',
            'rows'    => $rows,
            'date'    => $date,
            'device'  => $device,
            'kind'    => $kind,
            'q'       => $q,
            'scoped'  => $scope !== null,
            'devices' => Database::all('SELECT id, name FROM devices WHERE active = 1 ORDER BY usage_type DESC, name'),
        ]);
    }
}
