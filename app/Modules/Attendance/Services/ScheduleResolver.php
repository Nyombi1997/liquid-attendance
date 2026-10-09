<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Core\Database;

/**
 * Trouve l'horaire applicable à un agent pour une date.
 * Priorité : attribution individuelle > attribution du groupe > horaire par défaut.
 * À période égale, l'attribution la plus récente l'emporte.
 */
final class ScheduleResolver
{
    private array $schedules = [];
    private ?array $default = null;
    private array $byEmployee = [];
    private array $byGroup = [];

    public function __construct()
    {
        foreach (Database::all('SELECT * FROM schedules') as $s) {
            $this->schedules[(int) $s['id']] = $s;
            if ((int) $s['is_default'] === 1) {
                $this->default = $s;
            }
        }
        $rows = Database::all('SELECT * FROM schedule_assignments ORDER BY date_from DESC, id DESC');
        foreach ($rows as $a) {
            $bucket = $a['target_type'] === 'employee' ? 'byEmployee' : 'byGroup';
            $this->{$bucket}[(int) $a['target_id']][] = $a;
        }
    }

    public function resolve(array $employee, string $date): ?array
    {
        $id = $this->match($this->byEmployee[(int) $employee['id']] ?? [], $date);
        if ($id === null && !empty($employee['group_id'])) {
            $id = $this->match($this->byGroup[(int) $employee['group_id']] ?? [], $date);
        }
        return $id !== null ? ($this->schedules[$id] ?? $this->default) : $this->default;
    }

    private function match(array $assignments, string $date): ?int
    {
        foreach ($assignments as $a) {
            if ($a['date_from'] <= $date && ($a['date_to'] === null || $a['date_to'] >= $date)) {
                return (int) $a['schedule_id'];
            }
        }
        return null;
    }
}
