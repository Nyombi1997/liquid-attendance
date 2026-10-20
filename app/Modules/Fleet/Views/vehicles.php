<?php
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetWorkflow;
?>
<div class="page-head">
  <div>
    <h1>Véhicules</h1>
    <p class="muted">Parc automobile : état, documents et kilométrage. Alerte <?= (int) $alertDays ?> jours avant l'expiration de l'assurance et du contrôle technique.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/flotte/planning"><?= fa('fa-solid fa-calendar-days') ?> Planning</a>
    <?php if (can('fleet_manage')): ?>
      <button class="btn btn-primary" type="button" data-dialog="new-vehicle"><?= icon('plus') ?> Nouveau véhicule</button>
    <?php endif; ?>
  </div>
</div>

<nav class="chips" aria-label="Filtrer par état">
  <a class="chip<?= $status === '' ? ' is-active' : '' ?>" href="/flotte/vehicules">Tous <span class="chip-count"><?= array_sum($counts) ?></span></a>
  <?php foreach (Fleet::VEHICLE_STATUSES as $k => [$label]): ?>
    <a class="chip<?= $status === $k ? ' is-active' : '' ?>" href="/flotte/vehicules?statut=<?= e($k) ?>"><?= e($label) ?> <span class="chip-count"><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
</nav>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Immatriculation</th><th>Véhicule</th><th>Type</th><th>État</th><th>Assurance</th><th>Contrôle technique</th><th class="num">Kilométrage</th></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $v): $d = $docs[(int) $v['id']] ?? []; ?>
      <tr>
        <td><a href="/flotte/vehicules/<?= (int) $v['id'] ?>"><strong class="plate"><?= e($v['plate']) ?></strong></a></td>
        <td><?= e($v['brand'] . ' ' . $v['model']) ?><?= $v['year'] ? ' <span class="muted">' . (int) $v['year'] . '</span>' : '' ?>
          <small class="block muted"><?= e(Fleet::FUEL_TYPES[$v['fuel_type']] ?? '') ?><?= $v['tracker_id'] ? ' · tracker ' . e($v['tracker_id']) : '' ?></small></td>
        <td><?= e(FleetWorkflow::VEHICLE_TYPES[$v['vehicle_type']] ?? '') ?> <span class="muted">· <?= (int) $v['seats'] ?> pl.</span></td>
        <td><?= Fleet::vehicleBadge($v['status']) ?><?= $v['mission_ref'] ? ' <small class="muted">' . e($v['mission_ref']) . '</small>' : '' ?></td>
        <td><?= Fleet::expiryBadge($d['assurance'] ?? null, $alertDays) ?></td>
        <td><?= Fleet::expiryBadge($d['controle_technique'] ?? null, $alertDays) ?></td>
        <td class="num"><?= $v['odometer_km'] !== null ? e(number_format((int) $v['odometer_km'], 0, ',', ' ')) . ' km' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$vehicles): ?><tr><td colspan="7" class="muted center">Aucun véhicule<?= $status !== '' ? ' dans cet état' : '' ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($retired): ?>
<details class="card">
  <summary><strong>Véhicules retirés du parc</strong> <span class="muted">(<?= count($retired) ?>)</span></summary>
  <ul class="list">
    <?php foreach ($retired as $v): ?>
      <li><a class="list-item" href="/flotte/vehicules/<?= (int) $v['id'] ?>"><span class="list-main"><strong><?= e($v['plate']) ?></strong><small><?= e($v['brand'] . ' ' . $v['model']) ?></small></span></a></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>

<?php if (can('fleet_manage')): ?>
<dialog id="new-vehicle" class="dialog dialog-wide">
  <form method="post" action="/flotte/vehicules" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau véhicule</h2>
    <?php $v = null; require __DIR__ . '/_vehicle_form.php'; ?>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Ajouter</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
