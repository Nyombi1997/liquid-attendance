<?php
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetFiles;
use App\Modules\Fleet\Services\FleetWorkflow;

$v = $vehicle;
?>
<div class="page-head">
  <div>
    <p class="muted"><a href="/flotte/vehicules"><?= fa('fa-solid fa-arrow-left') ?> Véhicules</a></p>
    <h1><span class="plate"><?= e($v['plate']) ?></span> · <?= e($v['brand'] . ' ' . $v['model']) ?> <?= Fleet::vehicleBadge($v['status']) ?>
      <?= $v['active'] ? '' : '<span class="badge badge-muted">Retiré du parc</span>' ?></h1>
  </div>
  <?php if (can('fleet_manage')): ?>
    <div class="head-actions">
      <button class="btn btn-outline" type="button" data-dialog="new-doc"><?= fa('fa-solid fa-file-arrow-up') ?> Ajouter un document</button>
      <button class="btn btn-primary" type="button" data-dialog="edit-vehicle"><?= icon('edit') ?> Modifier</button>
    </div>
  <?php endif; ?>
</div>

<div class="grid-2">
  <section class="card">
    <h2>Fiche</h2>
    <dl class="facts facts-tight">
      <dt>Type</dt><dd><?= e(FleetWorkflow::VEHICLE_TYPES[$v['vehicle_type']] ?? '') ?>, <?= (int) $v['seats'] ?> places</dd>
      <dt>Année</dt><dd><?= $v['year'] ? (int) $v['year'] : '—' ?></dd>
      <dt>Carburant</dt><dd><?= e(Fleet::FUEL_TYPES[$v['fuel_type']] ?? '') ?><?= $v['tank_liters'] !== null ? ', réservoir de ' . e(rtrim(rtrim((string) $v['tank_liters'], '0'), '.')) . ' L' : '' ?></dd>
      <dt>Consommation de référence</dt><dd><?= $v['ref_consumption'] !== null ? e(str_replace('.', ',', (string) (float) $v['ref_consumption'])) . ' L/100 km' : '—' ?></dd>
      <dt>Kilométrage</dt><dd><?= $v['odometer_km'] !== null ? e(number_format((int) $v['odometer_km'], 0, ',', ' ')) . ' km' : '—' ?></dd>
      <dt>Tracker GPS</dt><dd><?= $v['tracker_id'] ? e($v['tracker_id']) : '<span class="muted">aucun</span>' ?></dd>
      <?php if ($v['notes']): ?><dt>Remarques</dt><dd><?= e($v['notes']) ?></dd><?php endif; ?>
    </dl>
  </section>
  <section class="card">
    <h2>Documents en cours</h2>
    <dl class="facts facts-tight">
      <?php foreach (\App\Modules\Fleet\Services\Availability::DOC_KINDS as $k => $label): ?>
        <dt><?= e($label) ?></dt><dd><?= Fleet::expiryBadge($current[$k] ?? null, $alertDays) ?></dd>
      <?php endforeach; ?>
    </dl>
    <p class="muted small">Assurance ou contrôle technique expiré : le véhicule ne peut pas être affecté. Alerte <?= (int) $alertDays ?> jours avant.</p>
  </section>
</div>

<section class="card table-card">
  <div class="card-head"><h2><?= fa('fa-regular fa-folder-open') ?> Documents</h2></div>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Type</th><th>Référence</th><th>Expiration</th><th>Fichier</th><th>Ajouté</th></tr></thead>
    <tbody>
    <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= e(Fleet::DOC_KINDS[$d['kind']] ?? $d['kind']) ?></td>
        <td><?= e($d['reference'] ?? '—') ?></td>
        <td><?= $d['expires_on'] ? e(date('d/m/Y', strtotime($d['expires_on']))) : '—' ?></td>
        <td><?php if ($d['stored_name']): ?><a href="/flotte/documents/<?= (int) $d['id'] ?>"><?= fa($d['file_mime'] === 'application/pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-file-image') ?> <?= e($d['file_name']) ?></a> <small class="muted"><?= e(FleetFiles::sizeLabel((int) $d['file_size'])) ?></small><?php else: ?>—<?php endif; ?></td>
        <td class="nowrap"><?= e(date('d/m/Y', strtotime($d['created_at']))) ?> <small class="muted"><?= e($d['author'] ?? '') ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$documents): ?><tr><td colspan="5" class="muted center">Aucun document.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Réservations à venir</h2>
    <ul class="list">
      <?php foreach ($upcoming as $r): ?>
        <li><a class="list-item" href="/flotte/demandes/<?= (int) $r['id'] ?>">
          <span class="list-main"><strong><?= e($r['reference']) ?> · <?= e($r['destination']) ?></strong>
            <small><?= e(Fleet::when($r['depart_at'])) ?> → <?= e(Fleet::when($r['return_at'])) ?> · <?= e($r['requester']) ?><?= $r['driver'] ? ' · ' . e($r['driver']) : '' ?></small></span>
          <?= Fleet::badge($r) ?></a></li>
      <?php endforeach; ?>
      <?php if (!$upcoming): ?><li class="empty-line muted">Aucune réservation.</li><?php endif; ?>
    </ul>
  </section>
  <section class="card">
    <h2>Dernières missions</h2>
    <ul class="list">
      <?php foreach ($missions as $m): ?>
        <li><a class="list-item" href="/flotte/demandes/<?= (int) $m['id'] ?>">
          <span class="list-main"><strong><?= e($m['reference']) ?> · <?= e($m['destination']) ?></strong>
            <small><?= e(Fleet::when($m['started_at'])) ?><?= $m['driver'] ? ' · ' . e($m['driver']) : '' ?>
              <?= $m['end_km'] !== null ? ' · ' . e(number_format((int) $m['end_km'] - (int) $m['start_km'], 0, ',', ' ')) . ' km' : ' · en cours' ?></small></span>
          <?= $m['end_condition'] && $m['end_condition'] !== 'bon' ? '<span class="badge badge-warn">' . e(Fleet::CONDITIONS[$m['end_condition']]) . '</span>' : '' ?></a></li>
      <?php endforeach; ?>
      <?php if (!$missions): ?><li class="empty-line muted">Aucune mission.</li><?php endif; ?>
    </ul>
  </section>
</div>

<?php if (can('fleet_manage')): ?>
<dialog id="edit-vehicle" class="dialog dialog-wide">
  <form method="post" action="/flotte/vehicules/<?= (int) $v['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <h2>Modifier <?= e($v['plate']) ?></h2>
    <?php require __DIR__ . '/_vehicle_form.php'; ?>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>

<dialog id="new-doc" class="dialog">
  <form method="post" action="/flotte/vehicules/<?= (int) $v['id'] ?>/documents" enctype="multipart/form-data" class="stack">
    <?= csrf_field() ?>
    <h2>Ajouter un document</h2>
    <label class="field"><span>Type</span><select name="kind" required>
      <?php foreach (Fleet::DOC_KINDS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="field"><span>Référence (n° de police, de certificat…)</span><input name="reference" maxlength="80"></label>
    <label class="field"><span>Date d'expiration</span><input type="date" name="expires_on"><small class="hint">Obligatoire pour l'assurance et le contrôle technique.</small></label>
    <label class="field"><span>Fichier (PDF ou image, 5 Mo maximum)</span><input type="file" name="file" accept="application/pdf,image/jpeg,image/png,image/webp" data-max-bytes="<?= FleetFiles::MAX_BYTES ?>"></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
