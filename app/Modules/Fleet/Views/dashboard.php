<?php
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetStats;

$total = array_sum($vehicles);
?>
<div class="page-head">
  <div>
    <h1>Parc automobile</h1>
    <p class="muted">État du parc, demandes en attente et délais de traitement.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/flotte/planning"><?= fa('fa-solid fa-calendar-days') ?> Planning</a>
    <a class="btn btn-primary" href="/flotte/demandes/nouvelle"><?= icon('plus') ?> Nouvelle demande</a>
  </div>
</div>

<h2 class="section-title">Véhicules (<?= $total ?>)</h2>
<div class="kpis">
  <a class="kpi kpi-primary" href="/flotte/vehicules?statut=disponible"><span class="kpi-label">Disponibles</span><strong class="kpi-value"><?= $vehicles['disponible'] ?></strong><span class="kpi-foot">sur <?= $total ?> véhicule(s)</span></a>
  <a class="kpi" href="/flotte/vehicules?statut=en_mission"><span class="kpi-label">En mission</span><strong class="kpi-value"><?= $vehicles['en_mission'] ?></strong></a>
  <a class="kpi" href="/flotte/vehicules?statut=en_panne"><span class="kpi-label">En panne</span><strong class="kpi-value<?= $vehicles['en_panne'] ? ' text-danger' : '' ?>"><?= $vehicles['en_panne'] ?></strong></a>
  <a class="kpi" href="/flotte/vehicules?statut=en_entretien"><span class="kpi-label">En entretien</span><strong class="kpi-value"><?= $vehicles['en_entretien'] ?></strong></a>
</div>

<h2 class="section-title">Demandes en attente</h2>
<div class="kpis">
  <a class="kpi" href="/flotte/registre?filtre=n1"><span class="kpi-label">Validation N+1</span><strong class="kpi-value"><?= $pending['n1'] ?></strong></a>
  <a class="kpi" href="/flotte/registre?filtre=direction"><span class="kpi-label">Validation direction</span><strong class="kpi-value"><?= $pending['direction'] ?></strong></a>
  <a class="kpi" href="/flotte/registre?filtre=affecter"><span class="kpi-label">À affecter</span><strong class="kpi-value"><?= $pending['affecter'] ?></strong></a>
  <a class="kpi" href="/flotte/registre?filtre=urgences"><span class="kpi-label">Urgences à régulariser</span><strong class="kpi-value<?= $pending['urgences'] ? ' text-danger' : '' ?>"><?= $pending['urgences'] ?></strong><span class="kpi-foot">affectées avant validation</span></a>
</div>

<h2 class="section-title">Délais moyens (<?= FleetStats::DELAY_DAYS ?> derniers jours)</h2>
<div class="kpis">
  <div class="kpi"><span class="kpi-label">Création → validation</span><strong class="kpi-value"><?= e(FleetStats::duration($delays['validation'])) ?></strong><span class="kpi-foot"><?= $delays['n_validation'] ?> demande(s) validée(s)</span></div>
  <div class="kpi"><span class="kpi-label">Validation → affectation</span><strong class="kpi-value"><?= e(FleetStats::duration($delays['affectation'])) ?></strong><span class="kpi-foot"><?= $delays['n_affectation'] ?> affectation(s) après validation</span></div>
  <div class="kpi"><span class="kpi-label">Création → affectation</span><strong class="kpi-value"><?= e(FleetStats::duration($delays['total'])) ?></strong><span class="kpi-foot">urgences comprises</span></div>
  <div class="kpi"><span class="kpi-label">Ce mois-ci</span><strong class="kpi-value"><?= e(number_format($month['km'], 0, ',', ' ')) ?> <small>km</small></strong>
    <span class="kpi-foot"><?= $month['missions'] ?> mission(s) clôturée(s)<?= $month['rating'] !== null ? ' · note ' . e(str_replace('.', ',', (string) $month['rating'])) . '/5' : '' ?></span></div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><?= fa('fa-solid fa-hourglass-half') ?> À affecter</h2><a class="link" href="/flotte/registre?filtre=affecter">Tout voir</a></div>
    <ul class="list">
      <?php foreach ($toAssign as $r): ?>
        <li><a class="list-item" href="/flotte/demandes/<?= (int) $r['id'] ?><?= can('fleet_manage') ? '/affecter' : '' ?>">
          <span class="list-main"><strong><?= (int) $r['urgent'] ? '<span class="req-urgent">' . fa('fa-solid fa-bolt') . ' Urgent</span> · ' : '' ?><?= e($r['reference']) ?> · <?= e($r['destination']) ?></strong>
            <small><?= e($r['requester_name']) ?> · départ <?= e(Fleet::when($r['depart_at'])) ?></small></span>
          <?= Fleet::badge($r) ?></a></li>
      <?php endforeach; ?>
      <?php if (!$toAssign): ?><li class="empty-line muted">Aucune demande à affecter.</li><?php endif; ?>
    </ul>
  </section>
  <section class="card">
    <div class="card-head"><h2><?= fa('fa-solid fa-route') ?> Aujourd'hui</h2><span class="muted small">départs et missions en cours</span></div>
    <ul class="list">
      <?php foreach ($today as $r): ?>
        <li><a class="list-item" href="/flotte/demandes/<?= (int) $r['id'] ?>">
          <span class="list-main"><strong><?= e($r['reference']) ?> · <?= e($r['destination']) ?></strong>
            <small><?= e(Fleet::when($r['depart_at'])) ?> → <?= e(Fleet::when($r['return_at'])) ?><?= $r['plate'] ? ' · ' . e($r['plate']) : '' ?><?= $r['driver_name'] ? ' · ' . e($r['driver_name']) : '' ?></small></span>
          <?= Fleet::badge($r) ?></a></li>
      <?php endforeach; ?>
      <?php if (!$today): ?><li class="empty-line muted">Aucun départ ni mission en cours.</li><?php endif; ?>
    </ul>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2><?= fa('fa-solid fa-triangle-exclamation') ?> Documents et permis</h2><span class="muted small">expirés ou expirant dans <?= (int) $alertDays ?> jours</span></div>
  <ul class="list">
    <?php foreach ($expiring as $x): ?>
      <li><a class="list-item" href="<?= e($x['url']) ?>"><span class="list-main"><strong><?= e($x['label']) ?></strong></span><?= Fleet::expiryBadge($x['expires'], $alertDays) ?></a></li>
    <?php endforeach; ?>
    <?php if (!$expiring): ?><li class="empty-line muted">Rien à signaler.</li><?php endif; ?>
  </ul>
</section>
