<?php
use App\Modules\Attendance\Controllers\ScheduleController;

$s = $schedule ?? [];
$v = fn(string $k, $d = '') => old($k, $s[$k] ?? $d);
$t = fn(string $k, string $d) => substr((string) old($k, $s[$k] ?? $d), 0, 5);
$checked = array_map('intval', (array) old('workdays', explode(',', $s['workdays'] ?? '1,2,3,4,5')));
$action = $schedule ? '/horaires/' . (int) $schedule['id'] : '/horaires';
?>
<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p class="muted">Une fin plus tôt que le début signifie que l'horaire passe minuit (équipe de nuit).</p>
  </div>
  <a class="btn btn-ghost" href="/horaires">Annuler</a>
</div>

<section class="card card-narrow">
  <form method="post" action="<?= e($action) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="field"><span>Nom</span><input name="name" value="<?= e($v('name')) ?>" maxlength="100" placeholder="Ex. : Horaire NOC jour" required></label>
    <div class="grid-2 gap-sm">
      <label class="field"><span>Début</span><input type="time" name="start_time" value="<?= e($t('start_time', '08:00')) ?>" required></label>
      <label class="field"><span>Fin</span><input type="time" name="end_time" value="<?= e($t('end_time', '17:00')) ?>" required></label>
    </div>
    <fieldset class="field">
      <legend>Jours travaillés</legend>
      <div class="day-picker">
        <?php foreach (ScheduleController::DAYS as $n => $label): ?>
          <label class="day"><input type="checkbox" name="workdays[]" value="<?= $n ?>" <?= in_array($n, $checked, true) ? 'checked' : '' ?>><span><?= $label ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="grid-3 gap-sm">
      <label class="field"><span>Pause (min)</span><input type="number" name="break_minutes" min="0" max="600" value="<?= e($v('break_minutes', 60)) ?>">
        <small class="hint">Déduite du temps de présence</small></label>
      <label class="field"><span>Tolérance retard (min)</span><input type="number" name="late_tolerance_minutes" min="0" max="240" value="<?= e($v('late_tolerance_minutes', 15)) ?>">
        <small class="hint">Au-delà, le retard compte depuis l'heure de début</small></label>
      <label class="field"><span>Seuil heures sup (min)</span><input type="number" name="overtime_after_minutes" min="0" max="600" value="<?= e($v('overtime_after_minutes', 30)) ?>">
        <small class="hint">Dépassement minimum après la fin</small></label>
    </div>
    <label class="field field-color"><span>Couleur</span><input type="color" name="color" value="<?= e($v('color', '#273c88')) ?>"></label>
    <div><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</section>
