<?php
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetWorkflow;

$r = $req;
$issuesHtml = static function (array $issues): string {
    if (!$issues) {
        return '<ul class="issues"><li class="issue-ok">' . fa('fa-solid fa-check') . ' Disponible</li></ul>';
    }
    $html = '<ul class="issues">';
    foreach ($issues as $i) {
        $html .= '<li class="issue-' . e($i['level']) . '">' . fa($i['level'] === 'block' ? 'fa-solid fa-ban' : 'fa-solid fa-triangle-exclamation') . ' ' . e($i['text']) . '</li>';
    }
    return $html . '</ul>';
};
$plates = array_column($vehicles, 'plate', 'id');
$vehicleRows = array_map(static fn(array $v) => [
    'label'   => '<label class="tl-pick"><input type="radio" name="vehicle_id" value="' . (int) $v['id'] . '"'
        . ((int) $r['vehicle_id'] === (int) $v['id'] ? ' checked' : '') . ($v['blocked'] || !$can ? ' disabled' : '') . ' required>'
        . '<span class="list-main"><strong>' . e($v['plate']) . '</strong><small>' . e($v['brand'] . ' ' . $v['model'])
        . ' · ' . e(FleetWorkflow::VEHICLE_TYPES[$v['vehicle_type']] ?? '') . ' · ' . (int) $v['seats'] . ' pl.'
        . ($v['match'] ? '' : ' · <em>autre type que demandé</em>') . '</small>' . $issuesHtml($v['issues']) . '</span></label>',
    'blocked' => $v['blocked'],
    'slot'    => $slot,
    'bars'    => $v['bars'],
], $vehicles);
$driverRows = array_map(static fn(array $d) => [
    'label'   => '<label class="tl-pick"><input type="radio" name="driver_id" value="' . (int) $d['id'] . '"'
        . ((int) $r['driver_id'] === (int) $d['id'] ? ' checked' : '') . ($d['blocked'] || !$can ? ' disabled' : '') . ' required>'
        . '<span class="list-main"><strong>' . e($d['name']) . ($d['preferred'] ? ' <span class="tag">souhaité</span>' : '') . '</strong><small>'
        . e($d['license_category'] ? 'Permis ' . $d['license_category'] : 'Permis : catégories non renseignées')
        . ($d['authorized'] ? ' · autorisé : ' . e(implode(', ', array_map(static fn($id) => $plates[$id] ?? '?', $d['authorized']))) : '')
        . '</small>' . $issuesHtml($d['issues']) . '</span></label>',
    'blocked' => $d['blocked'],
    'slot'    => $slot,
    'bars'    => $d['bars'],
], $drivers);
?>
<div class="page-head">
  <div>
    <p class="muted"><a href="/flotte/demandes/<?= (int) $r['id'] ?>"><?= fa('fa-solid fa-arrow-left') ?> Demande <?= e($r['reference']) ?></a></p>
    <h1>Affecter un véhicule<?= (int) $r['with_driver'] ? ' et un chauffeur' : '' ?> <?= Fleet::badge($r) ?></h1>
    <p class="muted"><?= e($r['requester_name']) ?> · <?= e($r['destination']) ?> · <strong><?= e(Fleet::when($r['depart_at'])) ?> → <?= e(Fleet::when($r['return_at'])) ?></strong>
      · <?= (int) $r['passengers'] ?> passager(s) · <?= e(FleetWorkflow::VEHICLE_TYPES[$r['vehicle_type']] ?? 'type indifférent') ?>
      <?= (int) $r['with_driver'] ? ($r['preferred_driver_name'] ? ' · chauffeur souhaité : ' . e($r['preferred_driver_name']) : '') : ' · <strong>le demandeur conduit</strong>' ?></p>
  </div>
</div>

<?php if (!$can): ?>
  <div class="callout callout-warn"><?= fa('fa-solid fa-circle-info') ?>
    <?= $r['validation'] === 'en_attente' ? 'Demande pas encore validée : seule une demande urgente peut être affectée avant validation.' : 'Cette demande ne peut plus être affectée.' ?></div>
<?php elseif ($r['validation'] === 'en_attente'): ?>
  <div class="callout callout-warn"><?= fa('fa-solid fa-bolt') ?> Demande urgente pas encore validée : l'affectation est possible, la validation se fera a posteriori (tracée dans l'historique).</div>
<?php endif; ?>

<form method="post" action="/flotte/demandes/<?= (int) $r['id'] ?>/affecter" class="stack">
  <?= csrf_field() ?>
  <section class="card table-card">
    <div class="card-head"><h2><?= fa('fa-solid fa-car') ?> Véhicules</h2><span class="muted small"><span class="swatch tl-legend-slot"></span> créneau demandé · barres : réservations existantes</span></div>
    <?php $rows = $vehicleRows; $head = 'Véhicule'; require __DIR__ . '/_timeline.php'; ?>
  </section>
  <?php if ((int) $r['with_driver']): ?>
  <section class="card table-card">
    <div class="card-head"><h2><?= fa('fa-solid fa-id-card') ?> Chauffeurs</h2><span class="muted small">Autorisation sur le véhicule choisi vérifiée à l'enregistrement.</span></div>
    <?php $rows = $driverRows; $head = 'Chauffeur'; require __DIR__ . '/_timeline.php'; ?>
  </section>
  <?php endif; ?>
  <?php if ($can): ?>
  <div class="assign-bar card">
    <span class="muted">Les disponibilités sont revérifiées au moment de l'enregistrement.</span>
    <span class="spacer"></span>
    <a class="btn btn-ghost" href="/flotte/demandes/<?= (int) $r['id'] ?>">Annuler</a>
    <button class="btn btn-primary" type="submit"><?= fa('fa-solid fa-check') ?> <?= $r['status'] === 'affectee' ? 'Modifier l\'affectation' : 'Affecter' ?></button>
  </div>
  <?php endif; ?>
</form>
