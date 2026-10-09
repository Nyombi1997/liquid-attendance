<?php
$rate = $kpis['rate'];
// Graphique : barres empilées sur 14 jours (SVG généré côté serveur)
$max = max(1, ...array_map(fn($d) => array_sum($d), array_values($trend)));
$w = 640; $h = 200; $pad = 28; $n = count($trend);
$slot = ($w - $pad) / max(1, $n); $bar = min(24, $slot * 0.55);
$series = ['present' => 'Présents', 'retard' => 'Retards', 'incomplet' => 'Sortie manquante / en cours', 'absent' => 'Absents'];
$jours = ['', 'L', 'M', 'M', 'J', 'V', 'S', 'D'];
?>
<div class="page-head" data-live="dashboard" data-live-since-punch="<?= (int) $live['punch'] ?>" data-live-since-day="<?= e($live['day']) ?>" data-live-date="<?= e($today) ?>">
  <div>
    <h1>Tableau de bord</h1>
    <p class="muted">Présence de <?= e(date_fr($today)) ?>, mise à jour en direct à chaque badge.</p>
  </div>
  <a class="btn btn-primary" href="/presence"><?= icon('calendar') ?> Présence du jour</a>
</div>

<div class="kpis">
  <div class="kpi kpi-primary">
    <span class="kpi-label">Présents aujourd'hui</span>
    <strong class="kpi-value"><span data-kpi="arrived"><?= $kpis['arrived'] ?></span><small> / <span data-kpi="expected"><?= $kpis['expected'] ?></span></small></strong>
    <div class="meter" role="img" data-kpi-meter aria-label="<?= $rate ?> % des agents attendus"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="meter-track" width="100" height="6" rx="3"/><rect class="meter-fill" width="<?= min(100, $rate) ?>" height="6" rx="3"/></svg></div>
    <span class="kpi-foot"><span data-kpi="rate"><?= $rate ?></span> % des agents attendus</span>
  </div>
  <a class="kpi" href="/presence?status=retard">
    <span class="kpi-label">Retards</span>
    <strong class="kpi-value" data-kpi="late"><?= $kpis['late'] ?></strong>
    <span class="kpi-foot">au-delà de la tolérance</span>
  </a>
  <a class="kpi" href="/presence?status=absent">
    <span class="kpi-label">Absents</span>
    <strong class="kpi-value" data-kpi="absent"><?= $kpis['absent'] ?></strong>
    <span class="kpi-foot">aucun pointage d'entrée</span>
  </a>
  <a class="kpi kpi-accent" href="/corrections">
    <span class="kpi-label">Sorties manquantes</span>
    <strong class="kpi-value" data-kpi="pending"><?= $kpis['pending'] ?></strong>
    <span class="kpi-foot">à corriger (30 derniers jours)</span>
  </a>
</div>

<div class="grid-main">
  <section class="card">
    <div class="card-head">
      <h2>Tendance sur 14 jours</h2>
      <a class="link" href="/presence?from=<?= e(array_key_first($trend)) ?>&amp;to=<?= e($today) ?>">Voir le détail</a>
    </div>
    <svg class="chart" viewBox="0 0 <?= $w ?> <?= $h + 22 ?>" role="img" aria-label="Présence quotidienne sur 14 jours">
      <?php foreach ([0, 0.5, 1] as $g): $y = $h - $g * ($h - 10); ?>
        <line class="grid" x1="<?= $pad ?>" x2="<?= $w ?>" y1="<?= $y ?>" y2="<?= $y ?>"/>
        <text class="axis" x="<?= $pad - 6 ?>" y="<?= $y + 4 ?>" text-anchor="end"><?= (int) round($g * $max) ?></text>
      <?php endforeach; ?>
      <?php $i = 0; foreach ($trend as $date => $d): $x = $pad + $i * $slot + ($slot - $bar) / 2; $y = $h; ?>
        <g><title><?= e(date_fr($date)) ?> : <?= $d['present'] ?> présents, <?= $d['retard'] ?> retards, <?= $d['incomplet'] ?> incomplets, <?= $d['absent'] ?> absents</title>
        <?php foreach (array_keys($series) as $k): $bh = $d[$k] / $max * ($h - 10); if ($bh <= 0) continue; $y -= $bh; ?>
          <rect class="s-<?= $k ?>" x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>" width="<?= round($bar, 1) ?>" height="<?= round(max($bh - 1, 0.5), 1) ?>" rx="2"/>
        <?php endforeach; ?>
        </g>
        <text class="axis" x="<?= round($x + $bar / 2, 1) ?>" y="<?= $h + 16 ?>" text-anchor="middle"><?= $jours[(int) date('N', strtotime($date))] . date('j', strtotime($date)) ?></text>
      <?php $i++; endforeach; ?>
    </svg>
    <ul class="legend">
      <?php foreach ($series as $k => $label): ?><li><span class="swatch s-<?= $k ?>"></span><?= e($label) ?></li><?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><h2>Retards du jour</h2><a class="link" href="/presence?status=retard">Tous</a></div>
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

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Derniers pointages</h2><a class="link" href="/acces">Accès aux salles</a></div>
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

  <section class="card">
    <div class="card-head"><h2>Pointeuses</h2><a class="link" href="/pointeuses">Détails</a></div>
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
</div>
