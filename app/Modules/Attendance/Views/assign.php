<?php
$preEmployee = (int) ($_GET['employe'] ?? 0);
$target = old('target', $preEmployee ? 'employees' : 'group');
$selected = array_map('intval', (array) old('employee_ids', $preEmployee ? [$preEmployee] : []));
$scheduleId = (int) old('schedule_id', $preselect ?: 0);
?>
<div class="page-head">
  <div>
    <h1>Attribuer un horaire</h1>
    <p class="muted">À tout le monde, à un groupe, ou à un ou plusieurs agents, sur une période.</p>
  </div>
  <a class="btn btn-ghost" href="/horaires">Annuler</a>
</div>

<form method="post" action="/horaires/attribuer" class="assign-layout" data-assign>
  <?= csrf_field() ?>
  <section class="card stack">
    <label class="field"><span>Horaire</span>
      <select name="schedule_id" required>
        <option value="">Choisir…</option>
        <?php foreach ($schedules as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $scheduleId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(substr($s['start_time'], 0, 5)) ?>–<?= e(substr($s['end_time'], 0, 5)) ?>)<?= $s['is_default'] ? ' · par défaut' : '' ?></option>
        <?php endforeach; ?>
      </select></label>

    <fieldset class="field">
      <legend>Pour qui ?</legend>
      <div class="segmented segmented-block">
        <label><input type="radio" name="target" value="all" <?= $target === 'all' ? 'checked' : '' ?>><span>Tout le monde</span></label>
        <label><input type="radio" name="target" value="group" <?= $target === 'group' ? 'checked' : '' ?>><span>Un groupe</span></label>
        <label><input type="radio" name="target" value="employees" <?= $target === 'employees' ? 'checked' : '' ?>><span>Des agents</span></label>
      </div>
    </fieldset>

    <p class="hint" data-show-for="all">L'horaire devient l'horaire par défaut : il s'applique à tous les agents qui n'ont ni horaire individuel, ni horaire de groupe. Pas de période à saisir.</p>

    <label class="field" data-show-for="group"><span>Groupe</span>
      <select name="group_id">
        <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) old('group_id') === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= (int) $g['members'] ?> agents)</option><?php endforeach; ?>
      </select>
      <?php if (!$groups): ?><small class="hint">Aucun groupe : <a href="/groupes">créez-en un</a>.</small><?php endif; ?>
    </label>

    <div class="grid-2 gap-sm" data-hide-for="all">
      <label class="field"><span>Du</span><input type="date" name="date_from" value="<?= e(old('date_from', date('Y-m-d'))) ?>"></label>
      <label class="field"><span>Au (facultatif)</span><input type="date" name="date_to" value="<?= e(old('date_to')) ?>"></label>
    </div>
    <label class="field" data-hide-for="all"><span>Motif (facultatif)</span><input name="note" value="<?= e(old('note')) ?>" maxlength="255" placeholder="Ex. : Ramadan, rotation NOC…"></label>

    <div><button class="btn btn-primary" type="submit">Attribuer</button></div>
  </section>

  <section class="card table-card" data-show-for="employees">
    <div class="card-head">
      <h2>Agents <small class="muted">(<span data-selected-count><?= count($selected) ?></span> sélectionné(s))</small></h2>
      <input type="search" class="input-sm" placeholder="Filtrer…" data-filter-list="#agent-list" aria-label="Filtrer les agents">
    </div>
    <ul class="pick-list" id="agent-list">
      <?php foreach ($employees as $emp): ?>
        <li data-text="<?= e(mb_strtolower($emp['name'] . ' ' . $emp['device_user_id'] . ' ' . ($emp['group_name'] ?? ''))) ?>">
          <label class="check"><input type="checkbox" name="employee_ids[]" value="<?= (int) $emp['id'] ?>" <?= in_array((int) $emp['id'], $selected, true) ? 'checked' : '' ?>>
            <span><?= e($emp['name']) ?> <small class="muted">· n° <?= e($emp['device_user_id']) ?><?= $emp['group_name'] ? ' · ' . e($emp['group_name']) : '' ?></small></span></label>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</form>
