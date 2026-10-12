<?php
declare(strict_types=1);
// Tests des cases du calendrier : php tests/CalendarTest.php
require dirname(__DIR__) . '/app/Core/helpers.php';
require dirname(__DIR__) . '/app/Modules/Attendance/Services/Calendar.php';
use App\Modules\Attendance\Services\Calendar as K;

$fail = 0;
function check(string $name, array $r, array $exp): void {
    global $fail;
    foreach ($exp as $k => $v) if ($r[$k] !== $v) { $fail++; echo "ÉCHEC $name : $k = ".var_export($r[$k],true)." attendu ".var_export($v,true)."\n"; return; }
    echo "ok   $name\n";
}
$day = fn(array $over) => $over + ['first_in'=>null,'last_out'=>null,'worked_minutes'=>0,'late_minutes'=>0,
    'status'=>'absent','expected_start'=>'2026-10-12 08:00:00','expected_end'=>'2026-10-12 17:00:00'];
$now = '2026-10-12 11:30:00';
$conge = ['status'=>'conge','note'=>'Annuel','until'=>'2026-10-20'];

check('journée terminée', K::cell($day(['first_in'=>'2026-10-05 08:10:00','last_out'=>'2026-10-05 17:00:00','worked_minutes'=>470,'status'=>'present']), null, '2026-10-05', $now),
  ['kind'=>'work','label'=>'7 h 50','late'=>false]);
check('en cours aujourd\'hui', K::cell($day(['first_in'=>'2026-10-12 08:30:00','late_minutes'=>30,'status'=>'en_cours']), null, '2026-10-12', $now),
  ['kind'=>'ongoing','minutes'=>180,'label'=>'3 h 00','late'=>true]);
check('départ inconnu (jour passé)', K::cell($day(['first_in'=>'2026-10-05 08:00:00','status'=>'sortie_manquante']), null, '2026-10-05', $now),
  ['kind'=>'open','label'=>'08:00','minutes'=>0]);
check('congé sans badge', K::cell($day([]), $conge, '2026-10-12', $now),
  ['kind'=>'reason','reason'=>'conge','label'=>'En congé']);
check('congé mais venu', K::cell($day(['first_in'=>'2026-10-12 09:00:00','status'=>'en_cours']), $conge, '2026-10-12', $now),
  ['kind'=>'ongoing','reason'=>'conge']);
check('absent sans raison', K::cell($day([]), null, '2026-10-12', $now), ['kind'=>'absent','label'=>'Absent']);
check('attendu plus tard', K::cell($day(['status'=>'en_cours']), null, '2026-10-12', '2026-10-12 07:00:00'),
  ['kind'=>'pending','label'=>'08:00']);
check('repos', K::cell($day(['status'=>'repos','expected_start'=>null,'expected_end'=>null]), null, '2026-10-11', $now),
  ['kind'=>'off','label'=>'Repos']);
check('pas de journée calculée', K::cell(null, null, '2026-10-20', $now), ['kind'=>'off','label'=>'']);
check('congé à venir', K::cell(null, $conge, '2026-10-15', $now), ['kind'=>'reason']);
check('position bornée', ['a'=>K::minuteOf('2026-10-11 23:00:00','2026-10-12'),'b'=>K::minuteOf('2026-10-12 08:30:00','2026-10-12'),
  'c'=>K::minuteOf('2026-10-13 02:00:00','2026-10-12')], ['a'=>0,'b'=>510,'c'=>1440]);
echo $fail ? "\n$fail échec(s)\n" : "\nTous les tests passent.\n";
exit($fail ? 1 : 0);
