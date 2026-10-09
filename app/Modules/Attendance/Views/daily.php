<?php
$statuses = ['present' => 'Présent', 'retard' => 'Retard', 'absent' => 'Absent', 'sortie_manquante' => 'Sortie manquante',
    'en_cours' => 'En cours', 'hors_horaire' => 'Hors horaire', 'repos' => 'Repos'];
$multi = $filters['from'] !== $filters['to'];
$live = !$multi && $filters['from'] === date('Y-m-d');
$query = array_filter(['from' => $filters['from'], 'to' => $filters['to'], 'group' => $filters['group'] ?: null,
    'status' => $filters['status'] ?: null, 'q' => $filters['q'] ?: null]);
$shift = fn(int $days) => url('/presence', array_merge($query, [
    'from' => date('Y-m-d', strtotime($filters['from'] . " $days days")),
    'to' => date('Y-m-d', strtotime($filters['to'] . " $days days")),
]));
?>
<div class="page-head"<?= $live ? ' data-auto-refresh="30"' : '' ?>>
  <div>
    <h1>Présence</h1>
    <p class="muted"><?= $multi ? 'Du ' . e(date_fr($filters['from'], false)) . ' au ' . e(date_fr($filters['to'], false)) : e(ucfirst(date_fr($filters['from']))) ?> · <?= count($rows) ?> ligne(s)</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="<?= e($shift(-1)) ?>" aria-label="Jour précédent"><?= icon('chevron-left') ?></a>
    <a class="btn btn-ghost" href="/presence">Aujourd'hui</a>
    <a class="btn btn-ghost" href="<?= e($shift(1)) ?>" aria-label="Jour suivant"><?= icon('chevron-right') ?></a>
    <a class="btn btn-outline" href="<?= e(url('/presence/export', $query)) ?>"><?= icon('download') ?> Export CSV</a>
  </div>
</div>

<form class="filters card" method="get" action="/presence">
  <label class="field"><span>Du</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
  <label class="field"><span>Au</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
  <label class="field"><span>Groupe</span>
    <select name="group"><option value="0">Tous</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $filters['group'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field"><span>Statut</span>
    <select name="status"><option value="">Tous (hors repos)</option>
      <?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field field-grow"><span>Employé</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom, matricule ou numéro"></label>
  <button class="btn btn-primary" type="submit"><?= icon('filter') ?> Filtrer</button>
</form>

<div class="summary">
  <span>Présence totale <strong><?= minutes_label($totals['worked']) ?></strong></span>
  <span>Retards cumulés <strong><?= minutes_label($totals['late']) ?></strong></span>
  <span>Heures sup <strong><?= minutes_label($totals['overtime']) ?></strong></span>
</div>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr>
      <?php if ($multi): ?><th>Date</th><?php endif; ?>
      <th>Employé</th><th>Horaire</th><th>Arrivée</th><th>Départ</th><th class="num">Présence</th><th class="num">Retard</th><th class="num">Heures sup</th><th>Statut</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php if ($multi): ?><td class="nowrap"><?= e(date('d/m/Y', strtotime($r['work_date']))) ?></td><?php endif; ?>
        <td><a class="person" href="/employes/<?= (int) $r['employee_id'] ?>?mois=<?= e(substr($r['work_date'], 0, 7)) ?>">
          <span class="avatar avatar-sm"><?= e(initials($r['name'])) ?></span>
          <span><strong><?= e($r['name']) ?></strong><small class="muted block"><?= e($r['group_name'] ?? 'Sans groupe') ?></small></span></a></td>
        <td class="muted"><?= e($r['schedule_name'] ?? '—') ?><?php if ($r['expected_start']): ?><small class="block"><?= e(time_label($r['expected_start'])) ?>–<?= e(time_label($r['expected_end'])) ?></small><?php endif; ?></td>
        <td class="nowrap"><?= e(time_label($r['first_in'])) ?></td>
        <td class="nowrap"><?= e(time_label($r['last_out'])) ?><?php if ($r['corrected_out']): ?> <span class="tag" title="<?= e($r['correction_note'] ?? 'Saisie manuelle') ?>">corrigée</span><?php endif; ?></td>
        <td class="num"><?= minutes_label((int) $r['worked_minutes']) ?></td>
        <td class="num <?= $r['late_minutes'] > 0 ? 'text-warn' : '' ?>"><?= $r['late_minutes'] > 0 ? (int) $r['late_minutes'] . ' min' : '—' ?></td>
        <td class="num"><?= minutes_label((int) $r['overtime_minutes']) ?></td>
        <td><?= status_badge($r['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted center">Aucune donnée pour ces critères.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
