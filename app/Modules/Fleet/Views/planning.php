<?php
use App\Modules\Fleet\Services\Fleet;

$period = $view === 'jour' ? date_fr($date) : 'Semaine du ' . date_fr(substr($from, 0, 10), false) . ' au ' . date_fr(date('Y-m-d', strtotime($to . ' -1 day')), false);
$showRequester = true;
?>
<div class="page-head">
  <div>
    <h1>Planning du parc</h1>
    <p class="muted">Réservations des véhicules et des chauffeurs (missions affectées et en cours). Cliquez une barre pour ouvrir la demande.</p>
  </div>
</div>

<form class="filters card" method="get" data-auto-filter>
  <div class="segmented">
    <a href="?<?= e(http_build_query(['vue' => 'jour', 'date' => $date])) ?>" class="<?= $view === 'jour' ? 'is-active' : '' ?>">Jour</a>
    <a href="?<?= e(http_build_query(['vue' => 'semaine', 'date' => $date])) ?>" class="<?= $view === 'semaine' ? 'is-active' : '' ?>">Semaine</a>
  </div>
  <input type="hidden" name="vue" value="<?= e($view) ?>">
  <div class="month-nav">
    <a class="btn btn-ghost" href="?<?= e(http_build_query(['vue' => $view, 'date' => $prev])) ?>" aria-label="Période précédente"><?= icon('chevron-left') ?></a>
    <strong><?= e(ucfirst($period)) ?></strong>
    <a class="btn btn-ghost" href="?<?= e(http_build_query(['vue' => $view, 'date' => $next])) ?>" aria-label="Période suivante"><?= icon('chevron-right') ?></a>
  </div>
  <label class="field"><span>Date</span><input type="date" name="date" value="<?= e($date) ?>"></label>
  <a class="btn btn-outline" href="?<?= e(http_build_query(['vue' => $view])) ?>">Aujourd'hui</a>
</form>

<?php if ($waiting): ?>
<section class="card">
  <h2><?= fa('fa-solid fa-hourglass-half') ?> À affecter sur cette période (<?= count($waiting) ?>)</h2>
  <ul class="list">
    <?php foreach ($waiting as $w): ?>
      <li><a class="list-item" href="/flotte/demandes/<?= (int) $w['id'] ?>/affecter">
        <span class="list-main"><strong><?= (int) $w['urgent'] ? '<span class="req-urgent">' . fa('fa-solid fa-bolt') . ' Urgent</span> · ' : '' ?><?= e($w['reference']) ?> · <?= e($w['destination']) ?></strong>
          <small><?= e($w['requester_name']) ?> · <?= e(Fleet::when($w['depart_at'])) ?> → <?= e(Fleet::when($w['return_at'])) ?></small></span>
        <?= Fleet::badge($w) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card table-card">
  <div class="card-head"><h2><?= fa('fa-solid fa-car') ?> Véhicules</h2>
    <ul class="legend"><li><span class="swatch tl-sw-booked"></span> Réservé</li><li><span class="swatch tl-sw-ongoing"></span> En mission</li><li><span class="swatch tl-sw-off"></span> En panne / entretien</li></ul></div>
  <?php $rows = $vehicles; $head = 'Véhicule'; require __DIR__ . '/_timeline.php'; ?>
</section>
<section class="card table-card">
  <div class="card-head"><h2><?= fa('fa-solid fa-id-card') ?> Chauffeurs</h2></div>
  <?php $rows = $drivers; $head = 'Chauffeur'; require __DIR__ . '/_timeline.php'; ?>
</section>
