<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\AttendanceComputer;

/** Journées « sortie manquante » à compléter par un admin ou les RH. */
final class CorrectionController extends Controller
{
    public function index(): void
    {
        $days = (int) $this->input('jours', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $rows = Database::all(
            "SELECT d.*, e.name, g.name group_name
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
               LEFT JOIN employee_groups g ON g.id = e.group_id
              WHERE e.active = 1 AND d.status = 'sortie_manquante' AND d.work_date >= ?
              ORDER BY d.work_date DESC, e.name",
            [date('Y-m-d', strtotime("-$days days"))]
        );
        $recent = Database::all(
            'SELECT d.*, e.name, u.full_name corrector
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
               LEFT JOIN users u ON u.id = d.corrected_by
              WHERE d.corrected_out IS NOT NULL ORDER BY d.corrected_at DESC LIMIT 15'
        );
        $this->render('Attendance:corrections', [
            'title' => 'Sorties manquantes', 'rows' => $rows, 'recent' => $recent, 'days' => $days,
        ]);
    }

    public function store(int $id): void
    {
        $day = Database::one('SELECT * FROM attendance_days WHERE id = ?', [$id]) ?? $this->notFound();
        $time = (string) $this->input('time', '');
        $note = mb_substr((string) $this->input('note', ''), 0, 255);

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || !$day['first_in']) {
            flash('error', 'Heure invalide.');
            $this->back();
        }
        $out = $day['work_date'] . ' ' . $time . ':00';
        // Horaire de nuit : une heure plus petite que l'arrivée tombe le lendemain
        if ($out <= $day['first_in']) {
            $out = date('Y-m-d H:i:s', strtotime($out . ' +1 day'));
        }

        Database::run(
            'UPDATE attendance_days SET corrected_out = ?, corrected_by = ?, corrected_at = NOW(), correction_note = ? WHERE id = ?',
            [$out, Auth::id(), $note ?: null, $id]
        );
        (new AttendanceComputer())->run($day['work_date'], $day['work_date'], [(int) $day['employee_id']]);
        Audit::log('correction_sortie', ['jour' => $id, 'sortie' => $out, 'note' => $note]);
        flash('success', 'Heure de sortie enregistrée.');
        $this->back();
    }

    public function cancel(int $id): void
    {
        $day = Database::one('SELECT * FROM attendance_days WHERE id = ?', [$id]) ?? $this->notFound();
        Database::run(
            'UPDATE attendance_days SET corrected_out = NULL, corrected_by = NULL, corrected_at = NULL, correction_note = NULL WHERE id = ?',
            [$id]
        );
        (new AttendanceComputer())->run($day['work_date'], $day['work_date'], [(int) $day['employee_id']]);
        Audit::log('correction_annulee', ['jour' => $id]);
        flash('success', 'Correction annulée.');
        $this->back();
    }
}
