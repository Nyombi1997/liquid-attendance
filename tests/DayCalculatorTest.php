<?php
declare(strict_types=1);
// Tests du calcul des journées : php tests/DayCalculatorTest.php
require dirname(__DIR__) . '/app/Modules/Attendance/Services/DayCalculator.php';
use App\Modules\Attendance\Services\DayCalculator as C;

$s = ['id'=>1,'start_time'=>'08:00:00','end_time'=>'17:00:00','break_minutes'=>60,
      'late_tolerance_minutes'=>15,'overtime_after_minutes'=>30,'workdays'=>'1,2,3,4,5'];
$night = ['id'=>2,'start_time'=>'22:00:00','end_time'=>'06:00:00','break_minutes'=>0,
      'late_tolerance_minutes'=>0,'overtime_after_minutes'=>0,'workdays'=>'1,2,3,4,5,6,7'];
$later = new DateTimeImmutable('2026-10-20 12:00:00');
$fail = 0;
function check(string $name, array $r, array $exp): void {
    global $fail;
    foreach ($exp as $k => $v) if ($r[$k] !== $v) { $fail++; echo "ÉCHEC $name : $k = ".var_export($r[$k],true)." attendu ".var_export($v,true)."\n"; return; }
    echo "ok   $name\n";
}
// 2026-10-05 = lundi
check('à l\'heure', C::compute($s,'2026-10-05',['2026-10-05 07:55:00'],['2026-10-05 17:05:00'],null,$later),
  ['status'=>'present','late_minutes'=>0,'worked_minutes'=>490,'overtime_minutes'=>0]);
check('dans la tolérance', C::compute($s,'2026-10-05',['2026-10-05 08:15:00'],['2026-10-05 17:00:00'],null,$later),
  ['status'=>'present','late_minutes'=>0]);
check('retard', C::compute($s,'2026-10-05',['2026-10-05 08:16:00'],['2026-10-05 17:00:00'],null,$later),
  ['status'=>'retard','late_minutes'=>16]);
check('heures sup', C::compute($s,'2026-10-05',['2026-10-05 08:00:00'],['2026-10-05 18:00:00'],null,$later),
  ['overtime_minutes'=>60,'worked_minutes'=>540]);
check('sous le seuil heures sup', C::compute($s,'2026-10-05',['2026-10-05 08:00:00'],['2026-10-05 17:20:00'],null,$later),
  ['overtime_minutes'=>0]);
check('départ anticipé', C::compute($s,'2026-10-05',['2026-10-05 08:00:00'],['2026-10-05 16:00:00'],null,$later),
  ['early_leave_minutes'=>60,'status'=>'present']);
check('sortie manquante', C::compute($s,'2026-10-05',['2026-10-05 08:00:00','2026-10-05 13:00:00'],[],null,$later),
  ['status'=>'sortie_manquante','last_out'=>null,'first_in'=>'2026-10-05 08:00:00']);
check('en cours le jour même', C::compute($s,'2026-10-05',['2026-10-05 08:00:00'],[],null,new DateTimeImmutable('2026-10-05 15:00:00')),
  ['status'=>'en_cours']);
check('correction admin', C::compute($s,'2026-10-05',['2026-10-05 08:00:00'],[],'2026-10-05 17:00:00',$later),
  ['status'=>'present','worked_minutes'=>480]);
check('sortie avant entrée ignorée', C::compute($s,'2026-10-05',['2026-10-05 08:00:00'],['2026-10-05 07:00:00'],null,$later),
  ['status'=>'sortie_manquante']);
check('absent', C::compute($s,'2026-10-05',[],[],null,$later), ['status'=>'absent']);
check('repos samedi', C::compute($s,'2026-10-10',[],[],null,$later), ['status'=>'repos']);
check('hors horaire samedi', C::compute($s,'2026-10-10',['2026-10-10 09:00:00'],['2026-10-10 12:00:00'],null,$later),
  ['status'=>'hors_horaire','overtime_minutes'=>180]);
check('pas encore commencé', C::compute($s,'2026-10-05',[],[],null,new DateTimeImmutable('2026-10-05 07:00:00')),
  ['status'=>'en_cours']);
check('équipe de nuit', C::compute($night,'2026-10-05',['2026-10-05 22:05:00'],['2026-10-06 06:10:00'],null,$later),
  ['status'=>'retard','late_minutes'=>5,'worked_minutes'=>485,'overtime_minutes'=>10]);
$w = C::punchWindow($night,'2026-10-05');
check('fenêtre de nuit', ['a'=>$w[0],'b'=>$w[1]], ['a'=>'2026-10-05 18:00:00','b'=>'2026-10-06 14:00:00']);
echo $fail ? "\n$fail échec(s)\n" : "\nTous les tests passent.\n";
exit($fail ? 1 : 0);