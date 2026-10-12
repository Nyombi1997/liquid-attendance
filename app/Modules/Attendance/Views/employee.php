<?php
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$moisLabel = ucfirst(substr(date_fr($month . '-01', false), strpos(date_fr($month . '-01', false), ' ') + 1));
?>
<div class="page-head">
  <div class="person person-lg">
    <?= avatar($emp['name'], photo_url($emp), 'avatar avatar-lg') ?>
    <div>
      <h1><?= e($emp['name']) ?> <?= $emp['active'] ? '' : '<span class="badge badge-muted">Inactif</span>' ?></h1>
      <p class="muted">N° pointeuse <code><?= e($emp['device_user_id']) ?></code>
        <?= $emp['matricule'] ? ' · matricule ' . e($emp['matricule']) : '' ?>
        · <?= e($emp['group_name'] ?? 'Sans groupe') ?>
        · horaire actuel : <strong><?= e($schedule['name'] ?? 'aucun') ?></strong>
        <?php if ($schedule): ?>(<?= e(substr($schedule['start_time'], 0, 5)) ?>–<?= e(substr($schedule['end_time'], 0, 5)) ?>)<?php endif; ?></p>
    </div>
  </div>
  <div class="head-actions">
    <?php if (can('calendar')): ?>
      <a class="btn btn-outline" href="/calendrier?vue=mois&amp;date=<?= e($month) ?>-01&amp;q=<?= e(rawurlencode($emp['name'])) ?>"><?= icon('timeline') ?> Calendrier</a>
    <?php endif; ?>
    <?php if (can('manage')): ?>
      <a class="btn btn-outline" href="/horaires/attribuer?employe=<?= (int) $emp['id'] ?>"><?= icon('clock') ?> Attribuer un horaire</a>
      <a class="btn btn-primary" href="/employes/<?= (int) $emp['id'] ?>/modifier"><?= icon('edit') ?> Modifier</a>
    <?php endif; ?>
  </div>
</div>

<div class="month-nav">
  <a class="btn btn-ghost" href="?mois=<?= e($prev) ?>" aria-label="Mois précédent"><?= icon('chevron-left') ?></a>
  <strong><?= e($moisLabel) ?></strong>
  <a class="btn btn-ghost" href="?mois=<?= e($next) ?>" aria-label="Mois suivant"><?= icon('chevron-right') ?></a>
</div>

<div class="kpis">
  <div class="kpi"><span class="kpi-label">Jours présents</span><strong class="kpi-value"><?= $stats['present'] ?></strong></div>
  <div class="kpi"><span class="kpi-label">Présence totale</span><strong class="kpi-value"><?= minutes_label($stats['worked']) ?></strong></div>
  <div class="kpi"><span class="kpi-label">Retards</span><strong class="kpi-value"><?= $stats['late_days'] ?></strong><span class="kpi-foot"><?= minutes_label($stats['late']) ?> au total</span></div>
  <div class="kpi"><span class="kpi-label">Heures sup</span><strong class="kpi-value"><?= minutes_label($stats['overtime']) ?></strong></div>
  <div class="kpi"><span class="kpi-label">Absences</span><strong class="kpi-value"><?= $stats['absent'] ?></strong></div>
  <?php if (can('view')): ?><div class="kpi kpi-accent"><span class="kpi-label">Sorties manquantes</span><strong class="kpi-value"><?= $stats['missing'] ?></strong></div><?php endif; ?>
</div>

<div class="grid-main">
  <section class="card table-card">
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>Jour</th><th>Arrivée</th><th>Départ</th><th class="num">Présence</th><th class="num">Retard</th><th class="num">Heures sup</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($days as $d): ?>
        <tr class="<?= $d['status'] === 'repos' ? 'is-muted' : '' ?>">
          <td class="nowrap"><?= e(day_short($d['work_date'])) ?></td>
          <td><?= e(time_label($d['first_in'])) ?></td>
          <td><?= e(time_label($d['last_out'])) ?><?= $d['corrected_out'] ? ' <span class="tag">corrigée</span>' : '' ?></td>
          <td class="num"><?= minutes_label((int) $d['worked_minutes']) ?></td>
          <td class="num <?= $d['late_minutes'] > 0 ? 'text-warn' : '' ?>"><?= $d['late_minutes'] > 0 ? (int) $d['late_minutes'] . ' min' : '—' ?></td>
          <td class="num"><?= minutes_label((int) $d['overtime_minutes']) ?></td>
          <td><?= status_badge($d['status'], (int) $d['late_minutes']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$days): ?><tr><td colspan="7" class="muted center">Aucune journée calculée pour ce mois.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Fiche</h2></div>
    <dl class="facts">
      <dt>Nom sur la pointeuse</dt><dd><?= e($emp['device_name'] ?? '—') ?></dd>
      <dt>E-mail</dt><dd><?= e($emp['email'] ?? '—') ?></dd>
      <dt>Téléphone</dt><dd><?= e($emp['phone'] ?? '—') ?></dd>
    </dl>
    <div class="card-head"><h2>Accès aux salles récents</h2></div>
    <ul class="list">
      <?php foreach ($access as $a): ?>
        <li class="list-item"><span class="dir dir-acces"><?= icon('door') ?></span><span class="list-main"><strong><?= e($a['device']) ?></strong></span><span class="time"><?= e(date('d/m H:i', strtotime($a['punched_at']))) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$access): ?><li class="muted empty-line">Aucun passage enregistré.</li><?php endif; ?>
    </ul>
  </section>
</div>
