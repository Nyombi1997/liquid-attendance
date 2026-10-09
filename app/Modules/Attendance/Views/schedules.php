<?php
use App\Modules\Attendance\Controllers\ScheduleController;

$daysLabel = function (string $workdays): string {
    $d = array_map('intval', explode(',', $workdays));
    if ($d === [1, 2, 3, 4, 5]) return 'Lundi au vendredi';
    if ($d === [1, 2, 3, 4, 5, 6]) return 'Lundi au samedi';
    if (count($d) === 7) return 'Tous les jours';
    return implode(', ', array_map(fn($x) => ScheduleController::DAYS[$x], $d));
};
$swatch = fn(string $c) => '<svg class="swatch-svg" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="5" fill="' . e($c) . '"/></svg>';
?>
<div class="page-head">
  <div>
    <h1>Horaires</h1>
    <p class="muted">Priorité : l'horaire d'un agent passe avant celui de son groupe, qui passe avant l'horaire par défaut.</p>
  </div>
  <?php if (can('manage')): ?>
  <div class="head-actions">
    <a class="btn btn-outline" href="/horaires/nouveau"><?= icon('plus') ?> Nouvel horaire</a>
    <a class="btn btn-primary" href="/horaires/attribuer"><?= icon('users') ?> Attribuer un horaire</a>
  </div>
  <?php endif; ?>
</div>

<div class="cards">
  <?php foreach ($schedules as $s): ?>
    <article class="card schedule-card">
      <div class="card-head">
        <h2><?= $swatch($s['color']) ?> <?= e($s['name']) ?></h2>
        <?php if ($s['is_default']): ?><span class="badge badge-primary">Par défaut · tout le monde</span><?php endif; ?>
      </div>
      <p class="schedule-hours"><?= e(substr($s['start_time'], 0, 5)) ?> <span>→</span> <?= e(substr($s['end_time'], 0, 5)) ?>
        <?php if ($s['end_time'] <= $s['start_time']): ?><span class="tag">nuit</span><?php endif; ?></p>
      <ul class="schedule-facts">
        <li><?= e($daysLabel($s['workdays'])) ?></li>
        <li>Pause : <?= (int) $s['break_minutes'] ?> min</li>
        <li>Tolérance de retard : <?= (int) $s['late_tolerance_minutes'] ?> min</li>
        <li>Heures sup à partir de : <?= (int) $s['overtime_after_minutes'] ?> min de dépassement</li>
      </ul>
      <?php if (can('manage')): ?>
      <div class="card-actions">
        <a class="btn btn-ghost btn-sm" href="/horaires/<?= (int) $s['id'] ?>/modifier">Modifier</a>
        <a class="btn btn-ghost btn-sm" href="/horaires/attribuer?horaire=<?= (int) $s['id'] ?>">Attribuer</a>
        <?php if (!$s['is_default']): ?>
        <form method="post" action="/horaires/<?= (int) $s['id'] ?>/supprimer" data-confirm="Supprimer cet horaire et ses <?= (int) $s['assignments'] ?> attribution(s) ?"><?= csrf_field() ?><button class="btn btn-danger-ghost btn-sm" type="submit">Supprimer</button></form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>

<section class="card table-card">
  <div class="card-head"><h2>Attributions en cours et à venir</h2></div>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Cible</th><th>Horaire</th><th>Période</th><th>Motif</th><th>Créée par</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($assignments as $a): ?>
      <tr>
        <td><?= $a['target_type'] === 'group' ? icon('layers') . ' Groupe <strong>' . e($a['target_name'] ?? '?') . '</strong> <small class="muted">(' . (int) $a['people'] . ' agents)</small>'
                                                   : icon('user') . ' <a href="/employes/' . (int) $a['target_id'] . '">' . e($a['target_name'] ?? '?') . '</a>' ?></td>
        <td><?= $swatch($a['color']) ?> <?= e($a['schedule_name']) ?></td>
        <td class="nowrap">du <?= e(date('d/m/Y', strtotime($a['date_from']))) ?><?= $a['date_to'] ? ' au ' . e(date('d/m/Y', strtotime($a['date_to']))) : ', sans fin' ?></td>
        <td class="muted"><?= e($a['note'] ?? '—') ?></td>
        <td class="muted"><?= e($a['author'] ?? '—') ?></td>
        <td class="actions"><?php if (can('manage')): ?>
          <form method="post" action="/attributions/<?= (int) $a['id'] ?>/supprimer" data-confirm="Retirer cette attribution ? Les journées concernées seront recalculées."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Retirer</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$assignments): ?><tr><td colspan="6" class="muted center">Aucune attribution : tout le monde suit l'horaire par défaut.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if (can('manage')): ?>
<section class="card">
  <div class="card-head"><h2>Recalculer une période</h2></div>
  <p class="muted">Utile après avoir changé le rôle d'une pointeuse ou importé un ancien historique. Les corrections saisies sont conservées.</p>
  <form method="post" action="/presence/recalculer" class="filters">
    <?= csrf_field() ?>
    <label class="field"><span>Du</span><input type="date" name="from" value="<?= e(date('Y-m-01')) ?>" required></label>
    <label class="field"><span>Au</span><input type="date" name="to" value="<?= e(date('Y-m-d')) ?>" required></label>
    <button class="btn btn-outline" type="submit"><?= icon('refresh') ?> Recalculer</button>
  </form>
</section>
<?php endif; ?>
