<?php
$rate = $kpis['rate'];
$full = can('view'); // false : manager (son département, pages du jour) ou RH
$daily = can('presence'); // présence du jour : pas pour les RH
// Camembert (anneau) en SVG : cercles de circonférence 100, chaque part est
// tracée par stroke-dasharray / stroke-dashoffset (pas d'attribut style=, CSP).
// Fond gris tant qu'il n'y a aucune donnée. Le mode direct (app.js, data-donut)
// reprend les pourcentages calculés par le serveur.
$donut = static function (array $a, array $b, string $label): string {
    // $a, $b : [clé de l'indicateur en %, classe de couleur, valeur]
    $seg = static fn(array $p, int $offset, string $part) => '<circle class="donut-seg ' . $p[1] . '" cx="21" cy="21" r="15.9155"'
        . ' transform="rotate(-90 21 21)" stroke-dasharray="' . $p[2] . ' ' . (100 - $p[2]) . '" stroke-dashoffset="' . -$offset . '" data-donut-' . $part . '/>';
    return '<div class="donut" role="img" aria-label="' . e($label) . '" data-donut="' . e($a[0]) . ' ' . e($b[0]) . '">'
        . '<svg viewBox="0 0 42 42" aria-hidden="true"><circle class="donut-track" cx="21" cy="21" r="15.9155"/>'
        . $seg($a, 0, 'a') . $seg($b, $a[2], 'b') . '</svg>'
        . '<span class="donut-center"><span><strong data-kpi="' . e($a[0]) . '">' . $a[2] . '</strong> %</span></span></div>';
};
?>
<div class="page-head" data-live="dashboard" data-live-since-punch="<?= (int) $live['punch'] ?>" data-live-since-day="<?= e($live['day']) ?>" data-live-date="<?= e($today) ?>">
  <div>
    <h1>Tableau de bord</h1>
    <p class="muted">Présence de <?= e(date_fr($today)) ?><?= $scoped ? ' dans votre département' : '' ?>, mise à jour en direct à chaque badge.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/agents"><?= icon('id-card') ?> Agents</a>
    <?php if ($daily): ?><a class="btn btn-primary" href="/presence"><?= icon('calendar') ?> Présence du jour</a><?php endif; ?>
  </div>
</div>

<div class="kpis">
  <<?= $daily ? 'a href="/presence?status=venus"' : 'div' ?> class="kpi kpi-primary">
    <span class="kpi-label">Présents aujourd'hui</span>
    <strong class="kpi-value"><span data-kpi="arrived"><?= $kpis['arrived'] ?></span><small> / <span data-kpi="expected"><?= $kpis['expected'] ?></span></small></strong>
    <div class="meter" role="img" data-kpi-meter aria-label="<?= $rate ?> % des agents attendus"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="meter-track" width="100" height="6" rx="3"/><rect class="meter-fill" width="<?= min(100, $rate) ?>" height="6" rx="3"/></svg></div>
    <span class="kpi-foot"><span data-kpi="rate"><?= $rate ?></span> % des agents attendus</span>
  </<?= $daily ? 'a' : 'div' ?>>
  <<?= $daily ? 'a href="/presence?status=retard"' : 'div' ?> class="kpi">
    <span class="kpi-label">Retards</span>
    <strong class="kpi-value" data-kpi="late"><?= $kpis['late'] ?></strong>
    <span class="kpi-foot">au-delà de la tolérance</span>
  </<?= $daily ? 'a' : 'div' ?>>
  <<?= $daily ? 'a href="/presence?status=absent"' : 'div' ?> class="kpi">
    <span class="kpi-label">Absents</span>
    <strong class="kpi-value" data-kpi="absent"><?= $kpis['absent'] ?></strong>
    <span class="kpi-foot">aucun pointage d'entrée</span>
  </<?= $daily ? 'a' : 'div' ?>>
  <?php if ($full): ?>
  <a href="/corrections" class="kpi">
    <span class="kpi-label">Sorties manquantes</span>
    <strong class="kpi-value" data-kpi="pending"><?= $kpis['pending'] ?></strong>
    <span class="kpi-foot">à corriger (30 derniers jours)</span>
  </a>
  <?php endif; ?>
</div>

<div class="grid-main">
  <section class="card">
    <div class="card-head">
      <h2>Répartition du jour</h2>
      <?php if ($daily): ?><a class="link" href="/presence?status=venus">Voir les présents</a><?php endif; ?>
    </div>
    <div class="donuts">
      <figure class="donut-block">
        <figcaption>Présents et absents<small class="muted block">agents attendus</small></figcaption>
        <?= $donut(['present_pct', 's-present', $kpis['present_pct']], ['absent_pct', 's-absent', $kpis['absent_pct']], 'Présents : ' . $kpis['present_pct'] . ' %, absents : ' . $kpis['absent_pct'] . ' %') ?>
        <ul class="legend">
          <li><span class="swatch s-present"></span>Présents <strong data-kpi="arrived"><?= $kpis['arrived'] ?></strong> · <span data-kpi="present_pct"><?= $kpis['present_pct'] ?></span> %</li>
          <li><span class="swatch s-absent"></span>Absents <strong data-kpi="absent"><?= $kpis['absent'] ?></strong> · <span data-kpi="absent_pct"><?= $kpis['absent_pct'] ?></span> %</li>
        </ul>
      </figure>
      <figure class="donut-block">
        <figcaption>À l'heure et en retard<small class="muted block">agents arrivés</small></figcaption>
        <?= $donut(['ontime_pct', 's-present', $kpis['ontime_pct']], ['late_pct', 's-retard', $kpis['late_pct']], 'À l\'heure : ' . $kpis['ontime_pct'] . ' %, en retard : ' . $kpis['late_pct'] . ' %') ?>
        <ul class="legend">
          <li><span class="swatch s-present"></span>À l'heure <strong data-kpi="ontime"><?= $kpis['ontime'] ?></strong> · <span data-kpi="ontime_pct"><?= $kpis['ontime_pct'] ?></span> %</li>
          <li><span class="swatch s-retard"></span>En retard <strong data-kpi="late"><?= $kpis['late'] ?></strong> · <span data-kpi="late_pct"><?= $kpis['late_pct'] ?></span> %</li>
        </ul>
      </figure>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Retards du jour</h2><?php if ($daily): ?><a class="link" href="/presence?status=retard">Tous</a><?php endif; ?></div>
    <p class="muted empty-line" data-lates-empty<?= $lates ? ' hidden' : '' ?>>Aucun retard pour l'instant.</p>
    <ul class="list" data-lates>
      <?php foreach ($lates as $l): ?>
        <li data-late="<?= (int) $l['id'] ?>:<?= (int) $l['late_minutes'] ?>:<?= e(time_label($l['first_in'])) ?>"><a href="/employes/<?= (int) $l['id'] ?>" class="list-item">
          <span class="avatar avatar-sm"><?= e(initials($l['name'])) ?></span>
          <span class="list-main"><strong><?= e($l['name']) ?></strong><small><?= e($l['group_name'] ?? 'Sans groupe') ?> · arrivé à <?= e(time_label($l['first_in'])) ?></small></span>
          <span class="badge badge-warn">+<?= (int) $l['late_minutes'] ?> min</span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

<div class="<?= $devices ? 'grid-2' : '' ?>">
  <section class="card">
    <div class="card-head"><h2>Derniers pointages</h2><?php if ($full): ?><a class="link" href="/acces">Accès aux salles</a><?php endif; ?></div>
    <ul class="list" data-punches>
      <?php foreach ($punches as $p): ?>
        <li class="list-item" data-punched-at="<?= e($p['punched_at']) ?>" data-punch-id="<?= (int) $p['id'] ?>">
          <span class="dir dir-<?= e($p['usage_type'] === 'acces' ? 'acces' : $p['direction']) ?>"><?= icon($p['usage_type'] === 'acces' ? 'door' : ($p['direction'] === 'entree' ? 'arrow-in' : 'arrow-out')) ?></span>
          <span class="list-main"><strong><?= e($p['employee']) ?></strong><small><?= e($p['device']) ?></small></span>
          <span class="time"><?= e(date('d/m H:i', strtotime($p['punched_at']))) ?></span>
        </li>
      <?php endforeach; ?>
      <?php if (!$punches): ?><li class="muted empty-line" data-punches-empty>Aucun pointage importé pour l'instant.</li><?php endif; ?>
    </ul>
  </section>

  <?php if ($devices): ?>
  <section class="card">
    <div class="card-head"><h2>Pointeuses</h2><?php if ($full): ?><a class="link" href="/pointeuses">Détails</a><?php endif; ?></div>
    <ul class="list" data-devices>
      <?php foreach ($devices as $d):
        $ok = $d['last_sync_ok'] === 1 || $d['last_sync_ok'] === '1';
        $drift = $d['clock_offset_sec'] !== null && abs((int) $d['clock_offset_sec']) > 60; ?>
        <li class="list-item" data-device-id="<?= (int) $d['id'] ?>" data-device-ip="<?= e($d['ip']) ?>">
          <span class="dot <?= $d['last_sync_at'] === null ? 'dot-muted' : ($ok ? 'dot-ok' : 'dot-danger') ?>" data-device-dot></span>
          <span class="list-main"><strong><?= e($d['name']) ?></strong><small data-device-sync><?= e($d['ip']) ?> · <?= $d['last_sync_at'] ? 'synchronisée à ' . e(date('H:i', strtotime($d['last_sync_at']))) : 'jamais synchronisée' ?></small></span>
          <span class="badge badge-warn" data-device-drift<?= $drift ? '' : ' hidden' ?>>Horloge <?= (int) $d['clock_offset_sec'] > 0 ? '+' : '' ?><?= (int) $d['clock_offset_sec'] ?> s</span>
          <span class="badge badge-danger" data-device-down<?= !$ok && $d['last_sync_at'] ? '' : ' hidden' ?>>Injoignable</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>
