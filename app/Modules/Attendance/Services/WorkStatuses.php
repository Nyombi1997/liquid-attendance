<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Historique des disponibilités (table work_statuses) : chaque statut saisi
 * par les RH (télétravail, mission, congé, au bureau) avec sa période.
 * employees.work_status garde le statut en cours (page Agents) ; l'historique
 * permet au calendrier d'afficher les raisons d'absence des jours passés.
 */
final class WorkStatuses
{
    /**
     * Nouveau statut à partir d'aujourd'hui ($status null = retour à l'automatique) :
     * les périodes en cours s'arrêtent hier, celles qui commençaient aujourd'hui
     * ou plus tard sont remplacées.
     */
    public static function record(int $employeeId, ?string $status, ?string $until, ?string $note): void
    {
        $today = date('Y-m-d');
        Database::run(
            'DELETE FROM work_statuses WHERE employee_id = ? AND date_from >= ?',
            [$employeeId, $today]
        );
        Database::run(
            'UPDATE work_statuses SET date_to = ? WHERE employee_id = ? AND (date_to IS NULL OR date_to >= ?)',
            [date('Y-m-d', strtotime('-1 day')), $employeeId, $today]
        );
        if ($status !== null) {
            Database::run(
                'INSERT INTO work_statuses (employee_id, status, date_from, date_to, note, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$employeeId, $status, $today, $until, $note, Auth::id()]
            );
        }
    }

    /**
     * Raisons d'absence de chaque agent, jour par jour, entre $from et $to
     * (« au bureau » n'en est pas une ; libellés : Calendar::REASONS).
     *
     * @param int[] $employeeIds
     * @return array<int, array<string, array{status:string, note:?string, until:?string}>> [employee_id][date]
     */
    public static function reasons(array $employeeIds, string $from, string $to): array
    {
        if (!$employeeIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $employeeIds));
        $rows = Database::all(
            "SELECT employee_id, status, date_from, date_to, note FROM work_statuses
              WHERE employee_id IN ($in) AND status IN ('teletravail','mission','conge')
                AND date_from <= ? AND (date_to IS NULL OR date_to >= ?)
              ORDER BY date_from, id",
            [$to, $from]
        );
        $out = [];
        foreach ($rows as $r) {
            $start = max($r['date_from'], $from);
            $end = min($r['date_to'] ?? $to, $to);
            for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                // Une période plus récente remplace la précédente
                $out[(int) $r['employee_id']][$d] = [
                    'status' => $r['status'], 'note' => $r['note'], 'until' => $r['date_to'],
                ];
            }
        }
        return $out;
    }
}
