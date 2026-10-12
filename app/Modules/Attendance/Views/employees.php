<div class="page-head">
  <div>
    <h1>Employés</h1>
    <p class="muted"><?= count($employees) ?> employé(s). La liste est importée des pointeuses ; le nom affiché ici se modifie dans la plateforme sans toucher aux appareils.</p>
  </div>
  <?php if (can('manage')): ?><a class="btn btn-outline" href="/groupes"><?= icon('layers') ?> Gérer les groupes</a><?php endif; ?>
</div>

<form class="filters card" method="get" action="/employes" data-auto-filter>
  <label class="field field-grow"><span>Recherche</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, matricule ou numéro de pointeuse" data-suggest="go"></label>
  <label class="field"><span>Groupe</span>
    <select name="group"><option value="">Tous</option><option value="aucun" <?= $group === 'aucun' ? 'selected' : '' ?>>Sans groupe</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (string) $group === (string) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field"><span>État</span>
    <select name="etat">
      <?php foreach (['actifs' => 'Actifs', 'inactifs' => 'Inactifs', 'tous' => 'Tous'] as $k => $l): ?><option value="<?= $k ?>" <?= $state === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select></label>
</form>

<form method="post" action="/employes/groupe" id="bulk-form">
  <?= csrf_field() ?>
  <?php if (can('manage')): ?>
  <div class="bulk-bar" data-bulk-bar>
    <span><strong data-bulk-count>0</strong> sélectionné(s)</span>
    <select name="group_id" aria-label="Groupe">
      <option value="0">Retirer du groupe</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Affecter au groupe</button>
    <?php if ($departments): ?>
    <span class="bulk-sep" aria-hidden="true"></span>
    <select name="department_id" aria-label="Département">
      <option value="0">Retirer du département</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit" formaction="/employes/departement">Affecter au département</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <section class="card table-card">
    <div class="table-scroll">
    <table class="table">
      <thead><tr>
        <?php if (can('manage')): ?><th class="check-col"><input type="checkbox" data-check-all aria-label="Tout sélectionner"></th><?php endif; ?>
        <th>Nom</th><th>N° pointeuse</th><th>Matricule</th><th>Département</th><th>Groupe</th><th>Dernier pointage</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($employees as $emp): ?>
        <tr class="<?= $emp['active'] ? '' : 'is-muted' ?>">
          <?php if (can('manage')): ?><td class="check-col"><input type="checkbox" name="ids[]" value="<?= (int) $emp['id'] ?>" aria-label="Sélectionner <?= e($emp['name']) ?>"></td><?php endif; ?>
          <td><a class="person" href="/employes/<?= (int) $emp['id'] ?>"><?= avatar($emp['name'], photo_url($emp)) ?>
            <span><strong><?= e($emp['name']) ?></strong>
            <?php if ($emp['device_name'] && $emp['device_name'] !== $emp['name']): ?><small class="muted block">Pointeuse : <?= e($emp['device_name']) ?></small><?php endif; ?></span></a></td>
          <td><code><?= e($emp['device_user_id']) ?></code></td>
          <td><?= e($emp['matricule'] ?? '—') ?></td>
          <td><?= $emp['department_name'] ? e($emp['department_name']) : '<span class="muted">—</span>' ?></td>
          <td><?= $emp['group_name'] ? '<span class="tag">' . e($emp['group_name']) . '</span>' : '<span class="muted">—</span>' ?></td>
          <td class="muted nowrap"><?= $emp['last_punch'] ? e(date('d/m/Y H:i', strtotime($emp['last_punch']))) : '—' ?></td>
          <td class="actions"><?php if (can('manage')): ?><a class="btn btn-ghost btn-sm" href="/employes/<?= (int) $emp['id'] ?>/modifier">Modifier</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$employees): ?><tr><td colspan="8" class="muted center">Aucun employé. Ils apparaîtront après la première synchronisation des pointeuses.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</form>
