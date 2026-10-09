<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\AttendanceComputer;

/**
 * Horaires saisis manuellement et leurs attributions :
 * à tout le monde (horaire par défaut), à un groupe, ou à un ou plusieurs agents.
 */
final class ScheduleController extends Controller
{
    public const DAYS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];

    public function index(): void
    {
        $schedules = Database::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM schedule_assignments a WHERE a.schedule_id = s.id) assignments
               FROM schedules s ORDER BY s.is_default DESC, s.name"
        );
        $assignments = Database::all(
            "SELECT a.*, s.name schedule_name, s.color,
                    CASE a.target_type WHEN 'group' THEN g.name ELSE e.name END target_name,
                    CASE a.target_type WHEN 'group'
                         THEN (SELECT COUNT(*) FROM employees x WHERE x.group_id = a.target_id AND x.active = 1)
                         ELSE 1 END people,
                    u.full_name author
               FROM schedule_assignments a
               JOIN schedules s ON s.id = a.schedule_id
               LEFT JOIN employee_groups g ON a.target_type = 'group' AND g.id = a.target_id
               LEFT JOIN employees e ON a.target_type = 'employee' AND e.id = a.target_id
               LEFT JOIN users u ON u.id = a.created_by
              WHERE a.date_to IS NULL OR a.date_to >= CURDATE() - INTERVAL 30 DAY
              ORDER BY a.target_type DESC, target_name, a.date_from DESC"
        );
        $this->render('Attendance:schedules', [
            'title' => 'Horaires', 'schedules' => $schedules, 'assignments' => $assignments,
        ]);
    }

    public function create(): void
    {
        $this->render('Attendance:schedule_form', ['title' => 'Nouvel horaire', 'schedule' => null]);
    }

    public function store(): void
    {
        $d = $this->validated('/horaires/nouveau');
        $id = Database::insert(
            'INSERT INTO schedules (name, start_time, end_time, break_minutes, late_tolerance_minutes,
                                    overtime_after_minutes, workdays, color) VALUES (?,?,?,?,?,?,?,?)',
            array_values($d)
        );
        Audit::log('horaire_cree', ['id' => $id] + $d);
        flash('success', "Horaire « {$d['name']} » créé. Vous pouvez maintenant l'attribuer.");
        $this->redirect('/horaires');
    }

    public function edit(int $id): void
    {
        $s = Database::one('SELECT * FROM schedules WHERE id = ?', [$id]) ?? $this->notFound();
        $this->render('Attendance:schedule_form', ['title' => 'Modifier l\'horaire', 'schedule' => $s]);
    }

    public function update(int $id): void
    {
        Database::one('SELECT id FROM schedules WHERE id = ?', [$id]) ?? $this->notFound();
        $d = $this->validated("/horaires/$id/modifier");
        Database::run(
            'UPDATE schedules SET name = ?, start_time = ?, end_time = ?, break_minutes = ?, late_tolerance_minutes = ?,
                    overtime_after_minutes = ?, workdays = ?, color = ? WHERE id = ?',
            [...array_values($d), $id]
        );
        Audit::log('horaire_modifie', ['id' => $id] + $d);
        $this->recomputeRecent();
        flash('success', 'Horaire mis à jour. Les 31 derniers jours ont été recalculés.');
        $this->redirect('/horaires');
    }

    public function destroy(int $id): void
    {
        $s = Database::one('SELECT * FROM schedules WHERE id = ?', [$id]) ?? $this->notFound();
        if ((int) $s['is_default'] === 1) {
            flash('error', 'L\'horaire par défaut ne peut pas être supprimé. Définissez d\'abord un autre horaire par défaut.');
            $this->redirect('/horaires');
        }
        Database::run('DELETE FROM schedules WHERE id = ?', [$id]);
        Audit::log('horaire_supprime', ['id' => $id, 'nom' => $s['name']]);
        $this->recomputeRecent();
        flash('success', 'Horaire supprimé, ses attributions aussi.');
        $this->redirect('/horaires');
    }

    public function assignForm(): void
    {
        $this->render('Attendance:assign', [
            'title'     => 'Attribuer un horaire',
            'schedules' => Database::all('SELECT id, name, start_time, end_time, is_default FROM schedules ORDER BY name'),
            'groups'    => Database::all(
                'SELECT g.id, g.name, (SELECT COUNT(*) FROM employees e WHERE e.group_id = g.id AND e.active = 1) members
                   FROM employee_groups g ORDER BY g.name'
            ),
            'employees' => Database::all(
                'SELECT e.id, e.name, e.device_user_id, g.name group_name FROM employees e
                   LEFT JOIN employee_groups g ON g.id = e.group_id WHERE e.active = 1 ORDER BY e.name'
            ),
            'preselect' => (int) $this->input('horaire', 0),
        ]);
    }

    public function assign(): void
    {
        $scheduleId = (int) $this->input('schedule_id', 0);
        $target = (string) $this->input('target', '');
        $from = (string) $this->input('date_from', '');
        $to = (string) $this->input('date_to', '') ?: null;
        $note = mb_substr((string) $this->input('note', ''), 0, 255) ?: null;
        $this->withInput();

        if (!Database::value('SELECT id FROM schedules WHERE id = ?', [$scheduleId])) {
            $this->fail('Choisissez un horaire.');
        }

        // Tout le monde : l'horaire devient l'horaire par défaut
        if ($target === 'all') {
            Database::run('UPDATE schedules SET is_default = (id = ?)', [$scheduleId]);
            Audit::log('horaire_par_defaut', ['horaire' => $scheduleId]);
            $this->recomputeRecent();
            flash('success', 'Cet horaire s\'applique désormais à tous les agents sans horaire particulier.');
            $this->redirect('/horaires');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ($to !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from))) {
            $this->fail('Période invalide.');
        }

        if ($target === 'group') {
            $groupId = (int) $this->input('group_id', 0);
            if (!Database::value('SELECT id FROM employee_groups WHERE id = ?', [$groupId])) {
                $this->fail('Choisissez un groupe.');
            }
            $targets = [['group', $groupId]];
            $affected = array_column(Database::all('SELECT id FROM employees WHERE group_id = ?', [$groupId]), 'id');
        } elseif ($target === 'employees') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['employee_ids'] ?? []))));
            if (!$ids) {
                $this->fail('Sélectionnez au moins un agent.');
            }
            $targets = array_map(fn($id) => ['employee', $id], $ids);
            $affected = $ids;
        } else {
            $this->fail('Choisissez à qui attribuer l\'horaire.');
        }

        foreach ($targets as [$type, $id]) {
            Database::run(
                'INSERT INTO schedule_assignments (schedule_id, target_type, target_id, date_from, date_to, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$scheduleId, $type, $id, $from, $to, $note, Auth::id()]
            );
        }
        unset($_SESSION['_old']);
        Audit::log('horaire_attribue', ['horaire' => $scheduleId, 'cible' => $target, 'du' => $from, 'au' => $to, 'n' => count($targets)]);

        if ($from <= date('Y-m-d') && $affected) {
            (new AttendanceComputer())->run(max($from, date('Y-m-d', strtotime('-400 days'))), $to ?? date('Y-m-d'), array_map('intval', $affected));
        }
        flash('success', 'Horaire attribué à ' . count($affected) . ' agent(s).');
        $this->redirect('/horaires');
    }

    public function unassign(int $id): void
    {
        $a = Database::one('SELECT * FROM schedule_assignments WHERE id = ?', [$id]) ?? $this->notFound();
        Database::run('DELETE FROM schedule_assignments WHERE id = ?', [$id]);
        Audit::log('attribution_supprimee', $a);
        $ids = $a['target_type'] === 'group'
            ? array_column(Database::all('SELECT id FROM employees WHERE group_id = ?', [$a['target_id']]), 'id')
            : [(int) $a['target_id']];
        if ($ids && $a['date_from'] <= date('Y-m-d')) {
            (new AttendanceComputer())->run(max($a['date_from'], date('Y-m-d', strtotime('-400 days'))), $a['date_to'] ?? date('Y-m-d'), array_map('intval', $ids));
        }
        flash('success', 'Attribution supprimée.');
        $this->redirect('/horaires');
    }

    /** Recalcul manuel d'une période (après une correction de règles, par exemple). */
    public function recompute(): void
    {
        $from = (string) $this->input('from', '');
        $to = (string) $this->input('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            flash('error', 'Période invalide.');
            $this->redirect('/horaires');
        }
        $n = (new AttendanceComputer())->run($from, $to);
        Audit::log('recalcul', ['du' => $from, 'au' => $to]);
        flash('success', "$n journées recalculées.");
        $this->redirect('/horaires');
    }

    private function recomputeRecent(): void
    {
        (new AttendanceComputer())->run(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
    }

    private function fail(string $message): never
    {
        flash('error', $message);
        $this->redirect('/horaires/attribuer');
    }

    private function validated(string $back): array
    {
        $time = fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? $v . ':00' : null;
        $days = array_values(array_intersect(array_map('intval', (array) ($_POST['workdays'] ?? [])), array_keys(self::DAYS)));
        sort($days);
        $color = (string) $this->input('color', '#273c88');

        $d = [
            'name'          => mb_substr((string) $this->input('name', ''), 0, 100),
            'start_time'    => $time($this->input('start_time')),
            'end_time'      => $time($this->input('end_time')),
            'break'         => max(0, min(600, (int) $this->input('break_minutes', 0))),
            'tolerance'     => max(0, min(240, (int) $this->input('late_tolerance_minutes', 0))),
            'overtime'      => max(0, min(600, (int) $this->input('overtime_after_minutes', 0))),
            'workdays'      => implode(',', $days),
            'color'         => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#273c88',
        ];
        $error = match (true) {
            $d['name'] === ''                              => 'Le nom est obligatoire.',
            !$d['start_time'] || !$d['end_time']           => 'Heures de début et de fin obligatoires (HH:MM).',
            $d['start_time'] === $d['end_time']            => 'Le début et la fin ne peuvent pas être identiques.',
            $d['workdays'] === ''                          => 'Cochez au moins un jour travaillé.',
            default                                        => null,
        };
        if ($error) {
            $this->withInput();
            flash('error', $error);
            $this->redirect($back);
        }
        return $d;
    }
}
