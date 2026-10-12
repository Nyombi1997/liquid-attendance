<?php
$kinds = ['entree' => 'Entrées', 'sortie' => 'Sorties', 'acces' => 'Accès aux salles'];
?>
<div class="page-head">
  <div>
    <h1>Pointages</h1>
    <p class="muted">Tous les badges reçus des pointeuses le <?= e(date_fr($date)) ?><?= $scoped ? ', agents de votre département' : '' ?>. Cliquez sur un agent pour ouvrir sa fiche.</p>
  </div>
</div>

<form class="filters card" method="get" action="/pointages" data-auto-filter>
  <?php if (can('view')): ?><label class="field"><span>Date</span><input type="date" name="date" value="<?= e($date) ?>"></label><?php endif; ?>
  <label class="field"><span>Type</span>
    <select name="kind"><option value="">Tous</option>
      <?php foreach ($kinds as $k => $label): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field"><span>Pointeuse</span>
    <select name="device"><option value="0">Toutes</option>
      <?php foreach ($devices as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $device === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field field-grow"><span>Agent</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom ou numéro" data-suggest="submit"></label>
</form>

<section class="card table-card">
  <table class="table">
    <thead><tr><th>Heure</th><th>Agent</th><th>Pointeuse</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $k = $r['usage_type'] === 'acces' ? 'acces' : $r['direction']; ?>
      <tr>
        <td class="nowrap"><?= e(date('H:i:s', strtotime($r['punched_at']))) ?></td>
        <td><?php if ($r['employee_id'] && can('staff')): ?><a class="person" href="/employes/<?= (int) $r['employee_id'] ?>"><?= avatar($r['employee'], photo_url(['id' => $r['employee_id'], 'photo_updated_at' => $r['photo_updated_at']])) ?><strong><?= e($r['employee']) ?></strong></a><?php else: ?><span class="person"><?= avatar($r['employee'], null) ?><strong><?= e($r['employee']) ?></strong></span><?php endif; ?></td>
        <td class="nowrap"><span class="dir dir-<?= e($k) ?>"><?= icon($k === 'acces' ? 'door' : ($k === 'entree' ? 'arrow-in' : 'arrow-out')) ?></span> <?= e($r['device']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="muted center">Aucun pointage ce jour-là.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
